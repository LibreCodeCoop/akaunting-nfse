<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Models\Setting\Category;
use Illuminate\Support\Facades\DB;
use Modules\Nfse\Application\CategoryIssuancePolicy;
use Tests\Feature\FeatureTestCase;

final class NativeCategoryIssuanceFormTest extends FeatureTestCase
{
    public function testNativeCreateAndEditContainFiscalConfigurationWithoutAnotherForm(): void
    {
        $this->loginAs();
        $category = Category::factory()->create([
            'company_id' => company_id(), 'type' => 'income', 'enabled' => true,
        ]);
        (new CategoryIssuancePolicy())->record((int) company_id(), (int) $category->id,
            (int) auth()->id(), 'issue', 'Synthetic category authorized for testing the native form.');

        $this->get(route('categories.create'))
            ->assertOk()
            ->assertSee('data-nfse-native-category-policy="true"', false)
            ->assertSee('nfse_category_issuance_mode')
            ->assertDontSee('action="' . route('nfse.settings.category-issuance.store') . '"', false);

        $this->get(route('categories.edit', $category->id))
            ->assertOk()
            ->assertSee('data-nfse-native-category-policy="true"', false)
            ->assertSee('data-nfse-native-category-policy-history', false)
            ->assertSee('nfse_category_issuance_justification')
            ->assertDontSee('action="' . route('nfse.settings.category-issuance.store') . '"', false);

        $this->get(route('nfse.settings.category-issuance.index'))
            ->assertRedirect(route('categories.index'));
        $this->get(route('nfse.settings.edit'))
            ->assertOk()->assertSee(route('categories.index'), false);
    }

    public function testNativeCreateUpdateAndReverseMaintainHistoryWithOneSubmit(): void
    {
        $this->loginAs();
        $companyId = (int) company_id();
        $attributes = Category::factory()->enabled()->raw([
            'company_id' => $companyId,
            'type' => 'income',
            'name' => 'Synthetic financial service fiscal category',
        ]);
        $response = $this->postJson(route('categories.store'), array_merge($attributes, [
            'nfse_category_issuance_mode' => 'issue',
            'nfse_category_issuance_justification' => 'Synthetic service is approved for NFS-e issuance.',
        ]));
        $response->assertOk()->assertJsonPath('success', true);

        $category = Category::query()->where('company_id', $companyId)
            ->where('name', 'Synthetic financial service fiscal category')->firstOrFail();
        $policies = new CategoryIssuancePolicy();
        self::assertSame('issue', $policies->latest($companyId, (int) $category->id)['mode']);
        self::assertSame((int) auth()->id(), (int) $policies->latest($companyId, (int) $category->id)['actor_id']);
        self::assertCount(1, $policies->history($companyId, (int) $category->id));

        $updatedAttributes = array_merge($attributes, [
            'name' => 'Synthetic financial service fiscal category updated',
            'nfse_category_issuance_mode' => 'issue',
            'nfse_category_issuance_justification' => '',
        ]);
        $this->patchJson(route('categories.update', $category->id), $updatedAttributes)
            ->assertOk()->assertJsonPath('success', true);
        self::assertCount(1, $policies->history($companyId, (int) $category->id));

        $this->patchJson(route('categories.update', $category->id), array_merge($updatedAttributes, [
            'nfse_category_issuance_mode' => 'do_not_issue',
            'nfse_category_issuance_justification' => 'Synthetic administrative review withdraws issuance approval.',
        ]))->assertOk()->assertJsonPath('success', true);

        self::assertSame('Synthetic financial service fiscal category updated', $category->fresh()->name);
        self::assertSame('do_not_issue', $policies->latest($companyId, (int) $category->id)['mode']);
        self::assertCount(2, $policies->history($companyId, (int) $category->id));
        self::assertSame(0, DB::table('nfse_receipts')->count());
    }

    public function testInvalidFiscalAuthorizationRollsBackNativeCategoryUpdate(): void
    {
        $this->withExceptionHandling()->loginAs();
        $companyId = (int) company_id();
        $category = Category::factory()->create([
            'company_id' => $companyId, 'type' => 'income', 'enabled' => false,
            'name' => 'Synthetic disabled category before native save',
        ]);
        $attributes = [
            'company_id' => $companyId,
            'name' => 'Synthetic new category name that must be rolled back',
            'code' => (string) $category->code,
            'type' => 'income',
            'color' => (string) $category->color,
            // A forged client can omit enabled, but policy validation must
            // still inspect the category's persisted disabled status.
            'nfse_category_issuance_mode' => 'issue',
            'nfse_category_issuance_justification' => 'Synthetic invalid issuance authorization for disabled income.',
        ];

        $this->patchJson(route('categories.update', $category->id), $attributes)
            ->assertOk()->assertJsonPath('success', false);

        self::assertSame('Synthetic disabled category before native save', $category->fresh()->name);
        self::assertSame(0, DB::table('nfse_category_issuance_policies')
            ->where('company_id', $companyId)->where('category_id', $category->id)->count());
    }

    public function testInvalidJustificationRejectsChangeAndPreservesCurrentPolicy(): void
    {
        $this->withExceptionHandling()->loginAs();
        $companyId = (int) company_id();
        $category = Category::factory()->create([
            'company_id' => $companyId, 'type' => 'income', 'enabled' => true,
        ]);
        $policies = new CategoryIssuancePolicy();
        $policies->record($companyId, (int) $category->id, (int) auth()->id(),
            'issue', 'Existing synthetic approval must be retained.');

        $this->patchJson(route('categories.update', $category->id), [
            'company_id' => $companyId, 'name' => 'MUST NOT SAVE',
            'code' => $category->code, 'type' => 'income', 'color' => $category->color,
            'nfse_category_issuance_mode' => 'do_not_issue',
            'nfse_category_issuance_justification' => 'short',
        ])->assertOk()->assertJsonPath('success', false);

        self::assertSame($category->name, $category->fresh()->name);
        self::assertSame('issue', $policies->latest($companyId, (int) $category->id)['mode']);
        self::assertCount(1, $policies->history($companyId, (int) $category->id));
    }

    public function testForeignCompanyCategoryCannotReceivePolicyViaNativeUpdate(): void
    {
        $this->withExceptionHandling()->loginAs();
        $foreign = Category::factory()->create([
            'company_id' => (int) company_id() + 1000, 'type' => 'income', 'enabled' => true,
        ]);
        $this->patchJson(route('categories.update', $foreign->id), [
            'name' => 'Foreign category modified', 'type' => 'income',
            'code' => $foreign->code, 'color' => $foreign->color,
            'nfse_category_issuance_mode' => 'issue',
            'nfse_category_issuance_justification' => 'Synthetic attempt to change another company category.',
        ])->assertNotFound();

        self::assertSame(0, DB::table('nfse_category_issuance_policies')
            ->where('category_id', $foreign->id)->count());
        self::assertSame($foreign->name, $foreign->fresh()->name);
    }

    public function testDuplicateNativeCategoryCodeCannotClaimFiscalSaveWasSuccessful(): void
    {
        $this->withExceptionHandling()->loginAs();
        $companyId = (int) company_id();
        $existing = Category::factory()->create([
            'company_id' => $companyId, 'type' => 'income', 'enabled' => true,
            'code' => 'SYNTH-TEST-CATEGORY-DUP-001',
        ]);
        $this->postJson(route('categories.store'), [
            'company_id' => $companyId,
            'name' => 'Synthetic duplicate that must never save an issuance policy',
            'code' => $existing->code,
            'type' => 'income',
            'color' => $existing->color,
            'nfse_category_issuance_mode' => 'issue',
            'nfse_category_issuance_justification' => 'Synthetic attempted fiscal authorization on duplicate category.',
        ])->assertOk()->assertJsonPath('success', false);

        self::assertNull((new CategoryIssuancePolicy())->latest($companyId, (int) $existing->id));
        self::assertSame(1, Category::query()->where('company_id', $companyId)
            ->where('code', $existing->code)->count());
    }

    public function testIncomeCategoriesWithoutCodeRemainCreatableEvenWhenAnotherCodeIsEmpty(): void
    {
        $this->loginAs();
        $companyId = (int) company_id();
        $existing = Category::factory()->create([
            'company_id' => $companyId,
            'type' => 'income',
            'enabled' => true,
            'code' => '',
            'name' => 'Synthetic previous income without code',
        ]);

        $attributes = Category::factory()->enabled()->raw([
            'company_id' => $companyId,
            'type' => 'income',
            'code' => '',
            'name' => 'Synthetic new income without code',
        ]);
        $this->postJson(route('categories.store'), array_merge($attributes, [
            'nfse_category_issuance_mode' => 'issue',
            'nfse_category_issuance_justification' => 'Synthetic income without a category code can authorize issuance.',
        ]))->assertOk()->assertJsonPath('success', true);

        $category = Category::query()->where('company_id', $companyId)
            ->where('name', 'Synthetic new income without code')->firstOrFail();

        self::assertSame('issue', (new CategoryIssuancePolicy())
            ->latest($companyId, (int) $category->id)['mode']);
        self::assertNotSame((int) $existing->id, (int) $category->id);
        self::assertTrue(Category::query()->where('company_id', $companyId)
            ->whereKey($existing->id)->exists());
        self::assertTrue(Category::query()->where('company_id', $companyId)
            ->whereKey($category->id)->exists());
    }

    public function testNativeCategorySaveWithoutFiscalInputLeavesHistoryUnchanged(): void
    {
        $this->loginAs();
        $companyId = (int) company_id();
        $category = Category::factory()->create([
            'company_id' => $companyId, 'type' => 'income', 'enabled' => true,
        ]);
        $policies = new CategoryIssuancePolicy();
        $policies->record($companyId, (int) $category->id, (int) auth()->id(),
            'issue', 'Synthetic fiscal permission set from the existing API.');

        $this->patchJson(route('categories.update', $category->id), [
            'name' => 'Native category without additional fiscal change',
            'type' => 'income', 'code' => $category->code, 'color' => $category->color,
        ])->assertOk()->assertJsonPath('success', true);

        self::assertCount(1, $policies->history($companyId, (int) $category->id));
        self::assertSame('issue', $policies->latest($companyId, (int) $category->id)['mode']);
    }

    public function testNonIncomeCategoryCannotBeAuthorizedViaCraftedNativeInput(): void
    {
        $this->withExceptionHandling()->loginAs();
        $category = Category::factory()->create([
            'company_id' => company_id(), 'type' => 'expense', 'enabled' => true,
        ]);
        $this->patchJson(route('categories.update', $category->id), [
            'name' => 'Must not be saved', 'type' => 'expense',
            'code' => $category->code, 'color' => $category->color,
            'nfse_category_issuance_mode' => 'issue',
            'nfse_category_issuance_justification' => 'Synthetic crafted expense cannot authorize issuance.',
        ])->assertOk()->assertJsonPath('success', false);

        self::assertSame($category->name, $category->fresh()->name);
        self::assertSame(0, DB::table('nfse_category_issuance_policies')
            ->where('category_id', $category->id)->count());
    }
}

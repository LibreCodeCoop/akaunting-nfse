<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Jobs\Auth\CreateUser;
use App\Models\Document\Document;
use App\Traits\Permissions;
use Modules\Nfse\Models\NfseReceipt;
use Tests\Feature\FeatureTestCase;

final class NativeInvoiceFiscalActionsAccessTest extends FeatureTestCase
{
    use Permissions;

    public function testReadOnlySalesUserCannotSeeOrInvokeFiscalMutationsOrSettings(): void
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->create();
        $this->receipt($invoice, '155', 'emitted');

        $user = $this->salesUser('nfse-panel-readonly', false);
        $this->withExceptionHandling()->loginAs($user);

        $this->get(route('invoices.show', $invoice->id))
            ->assertOk()
            ->assertDontSee('data-nfse-settings-link="true"', false)
            ->assertDontSee('data-nfse-native-refresh="true"', false)
            ->assertDontSee('data-nfse-native-substitute="true"', false)
            ->assertDontSee('data-nfse-native-cancel="true"', false);

        $this->post(route('nfse.invoices.refresh', $invoice->id))->assertForbidden();
        $this->post(route('nfse.invoices.substitute', $invoice->id))->assertForbidden();
        $this->delete(route('nfse.invoices.cancel', $invoice->id))->assertForbidden();
        $this->get(route('nfse.settings.edit'))->assertForbidden();
    }

    public function testFiscalOperatorSeesReceiptActionsButNotProtectedSettings(): void
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->create();
        $receipt = $this->receipt($invoice, '155', 'emitted');

        $user = $this->salesUser('nfse-panel-operator', true);
        $this->withExceptionHandling()->loginAs($user);

        $content = $this->get(route('invoices.show', $invoice->id))
            ->assertOk()->getContent();

        self::assertStringContainsString('data-nfse-receipt-actions="' . $receipt->id . '"', $content);
        self::assertStringContainsString('data-nfse-native-refresh="true"', $content);
        self::assertStringNotContainsString('data-nfse-settings-link="true"', $content);
        $this->get(route('nfse.settings.edit'))->assertForbidden();
        $this->patch(route('nfse.settings.vault'))->assertForbidden();
        $this->post(route('nfse.certificate.upload'))->assertForbidden();
        $this->delete(route('nfse.certificate.destroy'))->assertForbidden();
    }

    public function testSettingsViewerMayReadButCannotChangeFiscalConfiguration(): void
    {
        $this->loginAs();
        $role = $this->createRole('nfse-settings-readonly');
        $this->attachPermission($role, 'read-admin-panel');
        $this->attachPermission($role, 'read-nfse-settings');

        $user = $this->dispatch(new CreateUser(array_merge(user_model_class()::factory()->raw(), [
            'enabled' => 1,
            'companies' => [$this->company->id],
            'roles' => [$role->id],
        ])));

        $this->withExceptionHandling()->loginAs($user);
        $this->get(route('nfse.settings.edit'))->assertOk();
        $this->patch(route('nfse.settings.fiscal'))->assertForbidden();
        $this->post(route('nfse.certificate.parse'))->assertForbidden();
        $this->delete(route('nfse.certificate.destroy'))->assertForbidden();
    }

    public function testOnlyTheLatestReceiptCardContainsItsOwnFiscalMutations(): void
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->create();
        $old = $this->receipt($invoice, '154', 'cancelled');
        $latest = $this->receipt($invoice, '155', 'emitted');

        $content = $this->get(route('invoices.show', $invoice->id))
            ->assertOk()->getContent();

        $older = $this->receiptCard($content, $old);
        $current = $this->receiptCard($content, $latest);

        self::assertStringNotContainsString('data-nfse-native-refresh="true"', $older);
        self::assertStringNotContainsString('data-nfse-native-cancel="true"', $older);
        self::assertStringNotContainsString('data-nfse-native-substitute="true"', $older);

        self::assertStringContainsString('data-nfse-receipt-actions="' . $latest->id . '"', $current);
        self::assertStringContainsString('name="nfse_receipt_id" value="' . $latest->id . '"', $current);
        self::assertStringContainsString('name="nfse_substitution_receipt_id" value="' . $latest->id . '"', $current);
        self::assertStringContainsString(route('nfse.invoices.refresh', $invoice->id), $current);
        self::assertStringContainsString(route('nfse.invoices.cancel', $invoice->id), $current);
        self::assertStringContainsString(route('nfse.invoices.substitute', $invoice->id), $current);
        self::assertStringNotContainsString('data-nfse-settings-link="true"', $current);
    }

    public function testStaleReceiptSubmissionsCannotRefreshCancelOrSubstituteAnotherEmission(): void
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->create();
        $old = $this->receipt($invoice, '154', 'emitted');
        $latest = $this->receipt($invoice, '155', 'emitted');

        $this->post(route('nfse.invoices.refresh', $invoice->id), [
            'nfse_receipt_id' => $old->id,
        ])->assertRedirect(route('invoices.show', $invoice->id))
            ->assertSessionHas('warning');

        $this->delete(route('nfse.invoices.cancel', $invoice->id), [
            'nfse_receipt_id' => $old->id,
        ])->assertRedirect(route('invoices.show', $invoice->id))
            ->assertSessionHas('warning');

        $this->post(route('nfse.invoices.substitute', $invoice->id), [
            'nfse_substitution_receipt_id' => $old->id,
        ])->assertRedirect(route('invoices.show', $invoice->id))
            ->assertSessionHas('error');

        self::assertSame('emitted', (string) $latest->fresh()->status);
        self::assertSame('emitted', (string) $old->fresh()->status);
    }

    private function salesUser(string $roleName, bool $canUpdate): mixed
    {
        $role = $this->createRole($roleName);
        $this->attachPermission($role, 'read-admin-panel');
        $this->attachPermission($role, 'read-sales-invoices');

        if ($canUpdate) {
            $this->attachPermission($role, 'update-sales-invoices');
        }

        return $this->dispatch(new CreateUser(array_merge(user_model_class()::factory()->raw(), [
            'enabled' => 1,
            'companies' => [$this->company->id],
            'roles' => [$role->id],
        ])));
    }

    private function receipt(Document $invoice, string $number, string $status): NfseReceipt
    {
        return NfseReceipt::query()->create([
            'invoice_id' => $invoice->id,
            'nfse_number' => $number,
            'chave_acesso' => str_repeat(substr($number, -1), 50),
            'status' => $status,
        ]);
    }

    private function receiptCard(string $content, NfseReceipt $receipt): string
    {
        $start = strpos($content, 'data-nfse-receipt-id="' . $receipt->id . '"');
        self::assertNotFalse($start);

        $end = strpos($content, '</article>', $start);
        self::assertNotFalse($end);

        return substr($content, $start, $end - $start);
    }
}

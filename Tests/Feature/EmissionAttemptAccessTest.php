<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use App\Jobs\Auth\CreateUser;
use App\Models\Document\Document;
use App\Traits\Permissions;
use Modules\Nfse\Models\NfseEmissionAttempt;
use Tests\Feature\FeatureTestCase;

final class EmissionAttemptAccessTest extends FeatureTestCase
{
    use Permissions;

    public function testReadOnlyHistoryIsTenantScopedAndDoesNotExposeRawFiscalPayload(): void
    {
        $this->loginAs();

        $companyId = (int) company_id();
        $invoice = Document::factory()->invoice()->create(['company_id' => $companyId]);
        $foreign = Document::factory()->invoice()->create(['company_id' => $companyId]);
        $foreign->forceFill(['company_id' => $companyId + 1000])->saveQuietly();

        $own = $this->attempt($invoice, $companyId, '1', 'E0312');
        $other = $this->attempt($foreign, $companyId + 1000, '2', 'E9999');

        $response = $this->get(route('nfse.invoices.emission-attempts', $invoice));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', (int) $own->id);
        $response->assertJsonPath('data.0.official_code', 'E0312');
        $response->assertJsonMissing(['official_code' => 'E9999']);
        $response->assertJsonMissingPath('data.0.payload_fingerprint');
        $response->assertJsonMissingPath('data.0.authorized_xml');

        self::assertNotSame($own->id, $other->id);

        $this->withExceptionHandling()
            ->get(route('nfse.invoices.emission-attempts', $foreign))
            ->assertNotFound();
    }

    public function testSalesInvoicePermissionIsRequiredForAttemptHistory(): void
    {
        $this->loginAs();
        $invoice = Document::factory()->invoice()->create(['company_id' => company_id()]);

        $role = $this->createRole('nfse-attempts-restricted');
        $this->attachPermission($role, 'read-admin-panel');

        $restricted = $this->dispatch(new CreateUser(array_merge(user_model_class()::factory()->raw(), [
            'enabled' => 1,
            'companies' => [$this->company->id],
            'roles' => [$role->id],
        ])));

        $this->withExceptionHandling()
            ->loginAs($restricted)
            ->get(route('nfse.invoices.emission-attempts', $invoice))
            ->assertForbidden();
    }

    private function attempt(Document $invoice, int $companyId, string $digit, string $code): NfseEmissionAttempt
    {
        return NfseEmissionAttempt::query()->create([
            'company_id' => $companyId,
            'invoice_id' => (int) $invoice->id,
            'dps_identifier' => str_repeat($digit, 42),
            'environment' => 2,
            'attempt_number' => 1,
            'payload_fingerprint' => hash('sha256', $digit),
            'municipio_ibge' => '3303302',
            'codigo_tributacao_nacional' => '010701',
            'codigo_tributacao_municipal' => '123',
            'codigo_servico' => '010701123',
            'competence_date' => '2026-09-01',
            'origin' => 'manual',
            'status' => 'rejected',
            'official_code' => $code,
            'official_message' => 'Servico nao aceito para a DPS',
            'http_status' => 422,
            'started_at' => now(),
        ]);
    }
}

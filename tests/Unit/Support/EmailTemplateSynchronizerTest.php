<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\EmailTemplateSynchronizer;
use Modules\Nfse\Tests\TestCase;

final class EmailTemplateSynchronizerTest extends TestCase
{
    public function testBuildTranslationsReturnsExpectedGlobalSettingsKeys(): void
    {
        $translations = EmailTemplateSynchronizer::buildTranslations();

        self::assertSame(
            'Modelo de NFS-e Emitida (enviado ao cliente)',
            $translations['pt-BR']['settings.email.templates.invoice_nfse_issued_customer']
        );

        self::assertSame(
            'Issued NFS-e Template (sent to customer)',
            $translations['en-GB']['settings.email.templates.invoice_nfse_issued_customer']
        );
    }

    public function testPlanCompanyTemplatesPromotesCanonicalAliasAndDeletesDuplicates(): void
    {
        $plan = EmailTemplateSynchronizer::planCompanyTemplates([
            ['id' => 31, 'company_id' => 1, 'alias' => 'nfse_issued_customer', 'name' => 'settings.email.templates.nfse_issued_customer'],
            ['id' => 32, 'company_id' => 1, 'alias' => 'nfse_issued_customer', 'name' => 'settings.email.templates.nfse_issued_customer'],
            ['id' => 33, 'company_id' => 1, 'alias' => 'invoice_nfse_issued_customer', 'name' => 'settings.email.templates.invoice_nfse_issued_customer'],
        ]);

        self::assertSame(33, $plan['canonical_id']);
        self::assertSame('invoice_nfse_issued_customer', $plan['updates']['alias']);
        self::assertSame('settings.email.templates.invoice_nfse_issued_customer', $plan['updates']['name']);
        self::assertSame([31, 32], $plan['delete_ids']);
    }

    public function testPlanCompanyTemplatesMigratesLegacyRecordWhenCanonicalDoesNotExist(): void
    {
        $plan = EmailTemplateSynchronizer::planCompanyTemplates([
            ['id' => 31, 'company_id' => 1, 'alias' => 'nfse_issued_customer', 'name' => 'settings.email.templates.nfse_issued_customer'],
        ]);

        self::assertSame(31, $plan['canonical_id']);
        self::assertSame('invoice_nfse_issued_customer', $plan['updates']['alias']);
        self::assertSame('settings.email.templates.invoice_nfse_issued_customer', $plan['updates']['name']);
        self::assertSame([], $plan['delete_ids']);
    }
}

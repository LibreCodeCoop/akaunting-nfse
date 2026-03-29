<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Models;

use Modules\Nfse\Models\CompanyService;
use PHPUnit\Framework\TestCase;

class CompanyServiceTest extends TestCase
{
    private function skipWhenEloquentModelIsUnavailable(): void
    {
        if (!class_exists(\Illuminate\Database\Eloquent\Model::class, false)
            || !method_exists(\Illuminate\Database\Eloquent\Model::class, 'getFillable')) {
            $this->markTestSkipped('Illuminate Eloquent is not available in this test runtime.');
        }
    }

    /**
     * Test model fillable includes all required fields
     */
    public function testModelFillableIncludesRequiredFields(): void
    {
        $this->skipWhenEloquentModelIsUnavailable();

        $fillable = (new CompanyService())->getFillable();

        $this->assertContains('company_id', $fillable);
        $this->assertContains('item_lista_servico', $fillable);
        $this->assertContains('codigo_tributacao_nacional', $fillable);
        $this->assertContains('aliquota', $fillable);
        $this->assertContains('description', $fillable);
        $this->assertContains('is_default', $fillable);
        $this->assertContains('is_active', $fillable);
    }

    /**
     * Test model casts aliquota as decimal(2)
     */
    public function testModelCastsAliquotaAsDecimal(): void
    {
        $this->skipWhenEloquentModelIsUnavailable();

        $service = new CompanyService();
        $casts = $service->getCasts();

        $this->assertArrayHasKey('aliquota', $casts);
        $this->assertStringContainsString('decimal', $casts['aliquota']);
    }

    /**
     * Test model casts booleans correctly
     */
    public function testModelCastsBooleanFields(): void
    {
        $this->skipWhenEloquentModelIsUnavailable();

        $service = new CompanyService();
        $casts = $service->getCasts();

        $this->assertArrayHasKey('is_default', $casts);
        $this->assertArrayHasKey('is_active', $casts);
        $this->assertEquals('boolean', $casts['is_default']);
        $this->assertEquals('boolean', $casts['is_active']);
    }

    /**
     * Test table name is correct
     */
    public function testTableNameIsCorrect(): void
    {
        $this->skipWhenEloquentModelIsUnavailable();

        $service = new CompanyService();
        $this->assertSame('nfse_company_services', $service->getTable());
    }

    public function testDisplayNameReturnsCatalogLabelForSavedLc116Code(): void
    {
        $this->skipWhenEloquentModelIsUnavailable();

        $service = new CompanyService();
        $service->item_lista_servico = '0101';

        $this->assertSame('1.01 - Analise e desenvolvimento de sistemas', $service->display_name);
    }
}

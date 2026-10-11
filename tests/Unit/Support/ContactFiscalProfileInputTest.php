<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Illuminate\Http\Request;
use Modules\Nfse\Support\ContactFiscalProfileInput;
use PHPUnit\Framework\TestCase;

final class ContactFiscalProfileInputTest extends TestCase
{
    public function testNoFiscalFieldsMeansNoFiscalProfileMutation(): void
    {
        self::assertNull(ContactFiscalProfileInput::fromRequest(Request::create('/', 'POST', [
            'name' => 'Cliente',
        ])));
    }

    public function testExplicitEmptyValuesClearTheFiscalProfile(): void
    {
        self::assertSame(
            ['municipal_registration' => '', 'legal_name' => ''],
            ContactFiscalProfileInput::fromRequest(Request::create('/', 'POST', [
                'nfse_municipal_registration' => '',
                'nfse_legal_name' => '',
            ])),
        );
    }

    public function testNullValuesFromConvertEmptyStringsToNullClearFiscalFields(): void
    {
        self::assertSame(
            ['municipal_registration' => '', 'legal_name' => 'EMPRESA DE TESTE LTDA'],
            ContactFiscalProfileInput::fromRequest(Request::create('/', 'POST', [
                'nfse_municipal_registration' => null,
                'nfse_legal_name' => 'EMPRESA DE TESTE LTDA',
            ])),
        );

        self::assertSame(
            ['municipal_registration' => '', 'legal_name' => ''],
            ContactFiscalProfileInput::fromRequest(Request::create('/', 'POST', [
                'nfse_municipal_registration' => null,
                'nfse_legal_name' => null,
            ])),
        );
    }

    public function testNonScalarFiscalFieldsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContactFiscalProfileInput::fromRequest(Request::create('/', 'POST', [
            'nfse_municipal_registration' => ['unexpected'],
            'nfse_legal_name' => 'Example Ltda',
        ]));
    }

    public function testPreservesUserProvidedRegistrationAndLegalName(): void
    {
        self::assertSame(
            ['municipal_registration' => '123.456-7', 'legal_name' => 'Cliente Exemplo S.A.'],
            ContactFiscalProfileInput::fromRequest(Request::create('/', 'POST', [
                'nfse_municipal_registration' => ' 123.456-7 ',
                'nfse_legal_name' => ' Cliente Exemplo S.A. ',
            ])),
        );
    }

    public function testRejectsOversizedValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContactFiscalProfileInput::fromRequest(Request::create('/', 'POST', [
            'nfse_municipal_registration' => str_repeat('9', 16),
            'nfse_legal_name' => 'Cliente',
        ]));
    }
}

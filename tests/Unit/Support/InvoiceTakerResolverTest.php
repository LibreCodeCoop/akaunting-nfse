<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\InvoiceTakerResolver;
use PHPUnit\Framework\TestCase;

final class InvoiceTakerResolverTest extends TestCase
{
    public function testFiscalProfileCanSupplyMunicipalRegistrationWithoutChangingCommercialContact(): void
    {
        $contact = (object) [
            'name' => 'Empresa Exemplo',
            'city_ibge' => '3550308',
            'zip_code' => '04578-000',
            'address' => 'Rua Exemplo, 10, Centro',
        ];
        $resolver = new InvoiceTakerResolver(
            static fn (?object $resolvedContact): array => [
                'municipal_registration' => '009001',
                'legal_name' => 'CLIENTE EXEMPLO LTDA',
            ],
        );
        self::assertSame('009001', $resolver->payload($contact)['inscricao_municipal']);
        self::assertSame('Empresa Exemplo', $contact->name);
    }

    public function testBuildsNationalTakerPayloadFromContactFields(): void
    {
        $contact = (object) [
            'city_ibge' => '3304557',
            'zip_code' => '20000-000',
            'address' => 'Rua de Teste',
            'number' => '10',
            'complement' => 'Sala 2',
            'district' => 'Centro',
            'municipal_registration' => 'IM-1',
            'phone' => '+55 (21) 99999-0000',
            'email' => 'tomador@example.test',
        ];

        $payload = (new InvoiceTakerResolver())->payload($contact);

        self::assertSame('3304557', $payload['codigo_municipio']);
        self::assertSame('20000000', $payload['cep']);
        self::assertSame('Rua de Teste', $payload['logradouro']);
        self::assertSame('10', $payload['numero']);
        self::assertSame('Sala 2', $payload['complemento']);
        self::assertSame('Centro', $payload['bairro']);
        self::assertSame('IM-1', $payload['inscricao_municipal']);
        self::assertSame('5521999990000', $payload['telefone']);
        self::assertSame('tomador@example.test', $payload['email']);
    }

    public function testIncompleteMunicipalityOrCepDoesNotSendPartialNationalAddress(): void
    {
        $payload = (new InvoiceTakerResolver())->payload((object) [
            'city_ibge' => 'invalid',
            'zip_code' => '20000-000',
            'address' => 'Must not leak',
        ]);

        self::assertSame('', $payload['codigo_municipio']);
        self::assertSame('', $payload['cep']);
        self::assertSame('', $payload['logradouro']);
    }

    public function testRealAkauntingAddressCanResolveMunicipalityAndSeparatedFields(): void
    {
        $contact = (object) [
            'city' => 'São Paulo',
            'state' => 'São Paulo',
            'zip_code' => '04578-000',
            'address' => 'Avenida Das Nacoes Unidas, 11541, Conj 61/62 e 71/72 Andar 6 e 7, Brooklin Novo',
        ];

        $payload = (new InvoiceTakerResolver())->payload($contact);

        self::assertSame('3550308', $payload['codigo_municipio']);
        self::assertSame('04578000', $payload['cep']);
        self::assertSame('Avenida Das Nacoes Unidas', $payload['logradouro']);
        self::assertSame('11541', $payload['numero']);
        self::assertSame('Conj 61/62 e 71/72 Andar 6 e 7', $payload['complemento']);
        self::assertSame('Brooklin Novo', $payload['bairro']);
    }

    public function testTextCityWithoutStateDoesNotGuessMunicipality(): void
    {
        $payload = (new InvoiceTakerResolver())->payload((object) [
            'city' => 'São Paulo',
            'zip_code' => '04578-000',
            'address' => 'Avenida Das Nacoes Unidas, 11541, Centro',
        ]);
        self::assertSame('', $payload['codigo_municipio']);
        self::assertSame('', $payload['cep']);
    }

    public function testFreeTextWithoutSeparatelyDelimitedAddressRemainsUnresolved(): void
    {
        $payload = (new InvoiceTakerResolver())->payload((object) [
            'city_ibge' => '3550308',
            'zip_code' => '04578-000',
            'address' => 'Avenida Das Nacoes Unidas 11541 Brooklin Novo',
        ]);
        self::assertSame('3550308', $payload['codigo_municipio']);
        self::assertSame('', $payload['numero']);
        self::assertSame('', $payload['bairro']);
    }

    public function testNormalizesCpfAndCnpjCompatibleDocuments(): void
    {
        $resolver = new InvoiceTakerResolver();

        self::assertSame('12345678901', $resolver->normalizeDocument('123.456.789-01'));
        self::assertSame('11222333000181', $resolver->normalizeDocument('11.222.333/0001-81'));
        self::assertSame('', $resolver->normalizeDocument('invalid'));
    }
}

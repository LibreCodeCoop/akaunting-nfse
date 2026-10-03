<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\ItemFiscalProfileInput;
use Modules\Nfse\Tests\TestCase;

final class ItemFiscalProfileInputTest extends TestCase
{
    public function testReturnsNullWhenFiscalFieldsAreAbsent(): void
    {
        $request = $this->request([]);

        self::assertNull(ItemFiscalProfileInput::fromRequest($request));
    }

    public function testNormalizesServiceAndNationalCodes(): void
    {
        $request = $this->request([
            'nfse_item_lista_servico' => '0107',
            'nfse_codigo_tributacao_nacional' => '010701',
        ]);

        self::assertSame([
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '010701',
        ], ItemFiscalProfileInput::fromRequest($request));
    }

    public function testPreservesLegacyNormalizationForFormattedThreeDigitServiceCode(): void
    {
        $request = $this->request([
            'nfse_item_lista_servico' => '1.07',
            'nfse_codigo_tributacao_nacional' => '010701',
        ]);

        self::assertSame([
            'item_lista_servico' => '107',
            'codigo_tributacao_nacional' => '010701',
        ], ItemFiscalProfileInput::fromRequest($request));
    }

    public function testUsesLastFourServiceDigitsForPrefixedCodes(): void
    {
        $request = $this->request([
            'nfse_item_lista_servico' => 'LC 116 / 01.07',
            'nfse_codigo_tributacao_nacional' => '701',
        ]);

        self::assertSame([
            'item_lista_servico' => '0107',
            'codigo_tributacao_nacional' => '000701',
        ], ItemFiscalProfileInput::fromRequest($request));
    }

    public function testEmptySubmittedFieldsRepresentProfileRemoval(): void
    {
        $request = $this->request([
            'nfse_item_lista_servico' => '',
            'nfse_codigo_tributacao_nacional' => '',
        ]);

        self::assertSame([
            'item_lista_servico' => null,
            'codigo_tributacao_nacional' => null,
        ], ItemFiscalProfileInput::fromRequest($request));
    }

    public function testExplicitNullFieldsRepresentProfileRemovalAfterLaravelMiddleware(): void
    {
        $request = $this->request([
            'nfse_item_lista_servico' => null,
            'nfse_codigo_tributacao_nacional' => null,
        ]);

        self::assertSame([
            'item_lista_servico' => null,
            'codigo_tributacao_nacional' => null,
        ], ItemFiscalProfileInput::fromRequest($request));
    }

    public function testNonRequestObjectIsIgnored(): void
    {
        self::assertNull(ItemFiscalProfileInput::fromRequest(new \stdClass()));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function request(array $values): object
    {
        return new class ($values) {
            /**
             * @param array<string, mixed> $values
             */
            public function __construct(private readonly array $values)
            {
            }

            public function input(string $key, mixed $default = null): mixed
            {
                return array_key_exists($key, $this->values)
                    ? $this->values[$key]
                    : $default;
            }

            public function exists(string $key): bool
            {
                return array_key_exists($key, $this->values);
            }
        };
    }
}

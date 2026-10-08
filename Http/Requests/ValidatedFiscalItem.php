<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Requests;

use App\Http\Requests\Common\Item as CoreItemRequest;
use Closure;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Domain\OfficialDomainCatalog;

/**
 * Keep Akaunting's native item rules and reject invalid fiscal input
 * before its CreateItem/UpdateItem job is dispatched.
 */
final class ValidatedFiscalItem extends CoreItemRequest
{
    /**
     * @return array<string,mixed>
     * @psalm-suppress MissingOverrideAttribute External Akaunting compatibility includes PHP 8.2.
     */
    #[\Override]
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['nfse_codigo_tributacao_nacional'] = [
            'nullable',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (!is_string($value) && !is_numeric($value)) {
                    $fail('O Código de Tributação Nacional deve conter seis dígitos.');
                    return;
                }

                $code = (string) $value;
                if (preg_match('/^\\d{6}$/D', $code) !== 1
                    || !(new OfficialDomainCatalog())->hasNationalService($code)) {
                    $fail('Selecione um Código de Tributação Nacional válido no catálogo oficial.');
                }
            },
        ];

        return $rules;
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Requests;

use App\Http\Requests\Common\Contact as CoreContactRequest;
use Closure;

/** Validate fiscal contact inputs before the native Core create/update job. */
final class ValidatedFiscalContact extends CoreContactRequest
{
    /** @return array<string,mixed> */
    #[\Override]
    public function rules(): array
    {
        $rules = parent::rules();
        $noControlCharacters = static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                $fail('O campo fiscal não pode conter caracteres de controle.');
            }
        };

        $rules['nfse_municipal_registration'] = ['nullable', 'string', 'max:15', $noControlCharacters];
        $rules['nfse_legal_name'] = ['nullable', 'string', 'max:255', $noControlCharacters];

        return $rules;
    }
}

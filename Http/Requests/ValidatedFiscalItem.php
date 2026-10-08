<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Requests;

use App\Http\Requests\Common\Item as CoreItemRequest;
use Closure;
use Modules\Nfse\Models\ItemFiscalProfile;
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
            function (string $attribute, mixed $value, Closure $fail): void {
                if (!is_string($value) && !is_numeric($value)) {
                    $fail('O Código de Tributação Nacional deve conter seis dígitos.');
                    return;
                }

                $code = (string) $value;
                if (!(preg_match('/^\d{6}$/D', $code) === 1
                    && (new OfficialDomainCatalog())->hasNationalService($code))
                    && !$this->isUnchangedPersistedNationalCode($code)) {
                    $fail('Selecione um Código de Tributação Nacional válido no catálogo oficial.');
                }
            },
        ];

        $rules['nfse_rtc_supply_category'] = [
            'nullable',
            'in:ordinary_lc116,digital_platform,non_iss_intangible,condominium_revenue,lease,residual_service',
        ];

        return $rules;
    }

    /**
     * Preserve a historic code only when its value is unchanged on this company's item.
     * @psalm-suppress UndefinedMethod Akaunting's Request stub omits Laravel's route() accessor.
     */
    private function isUnchangedPersistedNationalCode(string $code): bool
    {
        if ($code === '' || !function_exists('company_id')) {
            return false;
        }

        $item = request()->route('item');
        $id = is_object($item) ? ($item->id ?? null) : $item;
        if (!is_numeric($id) || (int) $id <= 0) {
            return false;
        }

        return ItemFiscalProfile::query()
            ->where('company_id', (int) company_id())
            ->where('item_id', (int) $id)
            ->where('codigo_tributacao_nacional', $code)
            ->first() instanceof ItemFiscalProfile;
    }
}

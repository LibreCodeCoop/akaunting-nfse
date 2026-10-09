<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\Nfse\Support;

use Illuminate\Http\Request;

/** Validates the two NFS-e-only customer fields without modifying Akaunting Contact. */
final class ContactFiscalProfileInput
{
    /** @return array{municipal_registration:string,legal_name:string}|null */
    public static function fromRequest(mixed $request): ?array
    {
        if (!$request instanceof Request
            || (!$request->has('nfse_municipal_registration') && !$request->has('nfse_legal_name'))) {
            return null;
        }

        $registration = $request->input('nfse_municipal_registration', '');
        $legalName = $request->input('nfse_legal_name', '');
        if (!is_string($registration) || !is_string($legalName)) {
            throw new \InvalidArgumentException('Invalid NFS-e contact fiscal fields.');
        }
        $registration = trim($registration);
        $legalName = trim($legalName);
        if (mb_strlen($registration) > 40 || mb_strlen($legalName) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $registration) === 1
            || preg_match('/[\x00-\x1f\x7f]/', $legalName) === 1) {
            throw new \InvalidArgumentException('Invalid NFS-e contact fiscal fields.');
        }

        return ['municipal_registration' => $registration, 'legal_name' => $legalName];
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Nfse\Support\PfxParser;
use Modules\Nfse\Support\VaultConfig;

class CertificateController extends Controller
{
    public function parsePfx(Request $request): JsonResponse
    {
        $request->validate([
            'pfx_file'     => 'required|file|mimes:pfx,p12|max:1024',
            'pfx_password' => 'required|string|max:255',
        ]);

        $file    = $request->file('pfx_file');
        $password = $request->input('pfx_password');

        $realPath = $file->getRealPath();
        if ($realPath === false) {
            return response()->json(['error' => trans('nfse::general.invalid_pfx')], 422);
        }

        $pfxContent = file_get_contents($realPath);
        if ($pfxContent === false) {
            return response()->json(['error' => trans('nfse::general.invalid_pfx')], 422);
        }

        try {
            $data = PfxParser::extractFromContent($pfxContent, $password);
        } catch (\RuntimeException) {
            return response()->json(['error' => trans('nfse::general.invalid_pfx')], 422);
        }

        return response()->json(['data' => $data]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'pfx_file'    => 'required|file|mimes:pfx,p12|max:1024',
            'pfx_password' => 'required|string|max:255',
        ]);

        $file     = $request->file('pfx_file');
        $password = $request->input('pfx_password');
        $cnpj     = setting('nfse.cnpj_prestador');

        // Verify PFX is readable before storing
        $realPath = $file->getRealPath();
        if ($realPath === false) {
            return back()->with('error', trans('nfse::general.invalid_pfx'));
        }

        $pfxContent = file_get_contents($realPath);
        if ($pfxContent === false) {
            return back()->with('error', trans('nfse::general.invalid_pfx'));
        }

        $certs = [];
        if (!openssl_pkcs12_read($pfxContent, $certs, $password)) {
            return back()->with('error', trans('nfse::general.invalid_pfx'));
        }

        // Store PFX file in private storage
        $storagePath = storage_path('app/nfse/pfx/' . $cnpj . '.pfx');
        if (!is_dir(dirname($storagePath))) {
            mkdir(dirname($storagePath), 0o700, true);
        }
        file_put_contents($storagePath, $pfxContent);
        chmod($storagePath, 0o600);

        // Store password in OpenBao — never in the application database
        $store = $this->makeSecretStore();
        $store->put('pfx/' . $cnpj, [
            'pfx_path' => $storagePath,
            'password' => $password,
        ]);

        return redirect()->route('nfse.settings.edit')
            ->with('success', trans('nfse::general.certificate_uploaded'));
    }

    public function destroy(): RedirectResponse
    {
        $cnpj        = setting('nfse.cnpj_prestador');
        $storagePath = storage_path('app/nfse/pfx/' . $cnpj . '.pfx');

        if (is_file($storagePath)) {
            unlink($storagePath);
        }

        $this->makeSecretStore()->delete('pfx/' . $cnpj);

        return redirect()->route('nfse.settings.edit')
            ->with('success', trans('nfse::general.certificate_deleted'));
    }

    private function makeSecretStore(): \LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface
    {
        $config = VaultConfig::secretStoreConfig();

        return new \LibreCodeCoop\NfsePHP\SecretStore\OpenBaoSecretStore(
            addr: $config['addr'],
            mount: $config['mount'],
            token: $config['token'],
            roleId: $config['roleId'],
            secretId: $config['secretId'],
        );
    }
}

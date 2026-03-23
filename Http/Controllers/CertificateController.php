<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Modules\Nfse\Support\PfxParser;
use Modules\Nfse\Support\PfxReader;
use Modules\Nfse\Support\VaultConfig;

class CertificateController extends Controller
{
    public function parsePfx(Request $request): JsonResponse
    {
        $request->validate([
            'pfx_file'     => 'required|file|extensions:pfx,p12|max:1024',
            'pfx_password' => 'required|string|max:255',
        ]);

        $file    = $request->file('pfx_file');
        $password = $request->input('pfx_password');

        try {
            $pfxContent = $this->readUploadedFile($file);
            $data = $this->parseUploadedCertificate($pfxContent, $password);
        } catch (\RuntimeException) {
            return $this->jsonResponse(['error' => trans('nfse::general.invalid_pfx')], 422);
        }

        return $this->jsonResponse(['data' => $data]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'pfx_file'    => 'required|file|extensions:pfx,p12|max:1024',
            'pfx_password' => 'required|string|max:255',
        ]);

        $file     = $request->file('pfx_file');
        $password = $request->input('pfx_password');

        try {
            $pfxContent = $this->readUploadedFile($file);
            $data = $this->parseUploadedCertificate($pfxContent, $password);
            $cnpj = $data['cnpj'] ?? null;

            if (!$cnpj) {
                return back()->with('error', trans('nfse::general.cnpj_not_found'));
            }
        } catch (\RuntimeException) {
            return back()->with('error', trans('nfse::general.invalid_pfx'));
        }

        try {
            $this->storeCertificate($cnpj, $pfxContent, $password);
            setting(['nfse.cnpj_prestador' => $cnpj]);
            setting()->save();
        } catch (\Throwable) {
            return back()->with('error', trans('nfse::general.certificate_store_failed'));
        }

        return redirect()->route('nfse.settings.edit', ['tab' => 'certificate'])
            ->with('success', trans('nfse::general.certificate_uploaded'));
    }

    protected function readUploadedFile(UploadedFile $file): string
    {
        $realPath = $file->getRealPath();

        if ($realPath === false) {
            throw new \RuntimeException('Invalid uploaded PFX path.');
        }

        $pfxContent = file_get_contents($realPath);

        if ($pfxContent === false) {
            throw new \RuntimeException('Failed to read uploaded PFX.');
        }

        return $pfxContent;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseUploadedCertificate(string $pfxContent, string $password): array
    {
        return PfxParser::extractFromContent($pfxContent, $password);
    }

    protected function validateUploadedCertificate(string $pfxContent, string $password): void
    {
        PfxReader::readCertificatePem($pfxContent, $password);
    }

    protected function jsonResponse(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status);
    }

    public function destroy(): RedirectResponse
    {
        $cnpj = (string) setting('nfse.cnpj_prestador', '');

        $this->clearStoredCertificate($cnpj);
        $this->clearNfseSettings();

        return redirect()->route('nfse.settings.edit')
            ->with('success', trans('nfse::general.certificate_deleted_and_settings_cleared'));
    }

    protected function clearStoredCertificate(string $cnpj): void
    {
        if ($cnpj === '') {
            return;
        }

        $storagePath = storage_path('app/nfse/pfx/' . $cnpj . '.pfx');

        if (is_file($storagePath)) {
            unlink($storagePath);
        }

        try {
            $this->makeSecretStore()->delete('pfx/' . $cnpj);
        } catch (\Throwable) {
            // Best-effort cleanup: continue clearing local settings even if remote secret is absent.
        }
    }

    protected function storeCertificate(string $cnpj, string $pfxContent, string $password): void
    {
        $storagePath = storage_path('app/nfse/pfx/' . $cnpj . '.pfx');

        if (!is_dir(dirname($storagePath))) {
            mkdir(dirname($storagePath), 0o700, true);
        }

        file_put_contents($storagePath, $pfxContent);
        chmod($storagePath, 0o600);

        $this->makeSecretStore()->put('pfx/' . $cnpj, [
            'pfx_path' => $storagePath,
            'password' => $password,
        ]);
    }

    protected function clearNfseSettings(): void
    {
        $nfseSettings = setting('nfse');
        if (is_array($nfseSettings)) {
            foreach (array_keys($nfseSettings) as $key) {
                setting()->forget('nfse.' . $key);
            }
        }

        setting()->save();
    }

    protected function makeSecretStore(): \LibreCodeCoop\NfsePHP\Contracts\SecretStoreInterface
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

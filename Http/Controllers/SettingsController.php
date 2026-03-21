<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Modules\Nfse\Support\IbgeLocalities;
use Modules\Nfse\Support\Lc116Catalog;
use Modules\Nfse\Support\PfxReader;
use Modules\Nfse\Support\VaultConfig;
use Throwable;

class SettingsController extends Controller
{
    private const IBGE_BASE_URL = 'https://servicodados.ibge.gov.br/api/v1/localidades';

    public function edit(): \Illuminate\View\View
    {
        $settings = setting('nfse');
        $certificateState = $this->certificateState();

        return view('nfse::settings.edit', compact('settings', 'certificateState'));
    }

    public function ufs(IbgeLocalities $ibgeLocalities): JsonResponse
    {
        try {
            $rows = $this->fetchUfsRows();

            return $this->jsonResponse([
                'data' => $ibgeLocalities->mapUfs(is_array($rows) ? $rows : []),
            ]);
        } catch (Throwable) {
            return $this->jsonResponse([
                'data' => [],
                'message' => 'Failed to load UFs from IBGE.',
            ], 502);
        }
    }

    public function municipalities(string $uf, IbgeLocalities $ibgeLocalities): JsonResponse
    {
        $normalizedUf = strtoupper(trim($uf));
        if (!preg_match('/^[A-Z]{2}$/', $normalizedUf)) {
            return $this->jsonResponse([
                'data' => [],
                'message' => 'Invalid UF.',
            ], 422);
        }

        try {
            $rows = $this->fetchMunicipalitiesRows($normalizedUf);

            return $this->jsonResponse([
                'data' => $ibgeLocalities->mapMunicipalities(is_array($rows) ? $rows : []),
            ]);
        } catch (Throwable) {
            return $this->jsonResponse([
                'data' => [],
                'message' => 'Failed to load municipalities from IBGE.',
            ], 502);
        }
    }

    public function lc116Services(Request $request, Lc116Catalog $lc116Catalog): JsonResponse
    {
        $query = $request->query('q');
        $limit = (int) $request->query('limit', 200);

        return response()->json([
            'data' => $lc116Catalog->search(is_string($query) ? $query : null, $limit),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $previousCnpj = (string) setting('nfse.cnpj_prestador', '');

        $request->validate([
            'nfse.cnpj_prestador'  => 'required|string|size:14',
            'nfse.uf'              => 'required|string|size:2',
            'nfse.municipio_nome'  => 'required|string|max:255',
            'nfse.municipio_ibge'  => 'required|string|size:7',
            'nfse.item_lista_servico' => 'required|string|size:4',
            'nfse.sandbox_mode'    => 'nullable|boolean',
            'nfse.bao_addr'        => 'required|url',
            'nfse.bao_mount'       => 'required|string',
            'nfse.bao_token'       => 'nullable|string',
            'nfse.bao_role_id'     => 'nullable|string',
            'nfse.bao_secret_id'   => 'nullable|string',
            'replace_certificate'  => 'nullable|boolean',
            'pfx_file'             => 'nullable|file|extensions:pfx,p12|max:1024',
            'pfx_password'         => 'nullable|string|max:255|required_with:pfx_file',
        ]);

        $certificatePayload = null;
        $certificateFile = $request->file('pfx_file');
        $isReplacingCertificate = $certificateFile instanceof UploadedFile;
        $nfseInput = $this->prepareNfseInput(
            $request->input('nfse', []),
            $isReplacingCertificate,
        );

        if ($certificateFile instanceof UploadedFile) {
            try {
                $certificatePayload = [
                    'content' => $this->readUploadedCertificate($certificateFile),
                    'password' => (string) $request->input('pfx_password', ''),
                ];

                $this->validateCertificatePayload(
                    $certificatePayload['content'],
                    $certificatePayload['password'],
                );
            } catch (\RuntimeException) {
                return redirect()->back()->withInput()->with('error', trans('nfse::general.invalid_pfx'));
            }
        }

        if ($isReplacingCertificate && $previousCnpj !== '') {
            $this->purgeCertificateArtifacts($previousCnpj);
            $this->clearNfseSettings();
        }

        foreach ($nfseInput as $key => $value) {
            setting(['nfse.' . $key => $value]);
        }

        setting()->save();

        if (is_array($certificatePayload)) {
            try {
                $this->storeCertificate(
                    (string) $nfseInput['cnpj_prestador'],
                    $certificatePayload['content'],
                    $certificatePayload['password'],
                );

                return redirect()->route('nfse.settings.edit')
                    ->with('success', trans('nfse::general.saved_and_certificate_uploaded'));
            } catch (Throwable) {
                return redirect()->route('nfse.settings.edit')
                    ->with('error', trans('nfse::general.certificate_store_failed'));
            }
        }

        return redirect()->route('nfse.settings.edit')
            ->with('success', trans('nfse::general.saved'));
    }

    /**
     * @param array<string, mixed> $nfseInput
     * @return array<string, mixed>
     */
    protected function prepareNfseInput(array $nfseInput, bool $isReplacingCertificate): array
    {
        $nfseInput['uf'] = strtoupper((string) ($nfseInput['uf'] ?? ''));
        $nfseInput['item_lista_servico'] = preg_replace('/\D/', '', (string) ($nfseInput['item_lista_servico'] ?? ''));
        $nfseInput['bao_mount'] = VaultConfig::normalizeMount((string) ($nfseInput['bao_mount'] ?? ''));
        unset($nfseInput['item_lista_servico_display']);

        if (($nfseInput['bao_token'] ?? '') === '' && $isReplacingCertificate === false) {
            unset($nfseInput['bao_token']);
        }

        if (($nfseInput['bao_secret_id'] ?? '') === '' && $isReplacingCertificate === false) {
            unset($nfseInput['bao_secret_id']);
        }

        return $nfseInput;
    }

    protected function fetchUfsRows(): array
    {
        $rows = Http::timeout(8)
            ->acceptJson()
            ->get(self::IBGE_BASE_URL . '/estados')
            ->throw()
            ->json();

        return is_array($rows) ? $rows : [];
    }

    protected function fetchMunicipalitiesRows(string $normalizedUf): array
    {
        $rows = Http::timeout(8)
            ->acceptJson()
            ->get(self::IBGE_BASE_URL . '/estados/' . $normalizedUf . '/municipios')
            ->throw()
            ->json();

        return is_array($rows) ? $rows : [];
    }

    protected function jsonResponse(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status);
    }

    private function certificateState(): array
    {
        $cnpj = (string) setting('nfse.cnpj_prestador', '');
        $path = $cnpj !== '' ? storage_path('app/nfse/pfx/' . $cnpj . '.pfx') : '';
        $hasLocalCertificate = $path !== '' && is_file($path);

        return [
            'cnpj' => $cnpj,
            'local_path' => $path,
            'has_local_certificate' => $hasLocalCertificate,
            'has_saved_settings' => $cnpj !== '',
        ];
    }

    protected function readUploadedCertificate(UploadedFile $file): string
    {
        $realPath = $file->getRealPath();

        if ($realPath === false) {
            throw new \RuntimeException('Invalid uploaded PFX path.');
        }

        $content = file_get_contents($realPath);

        if ($content === false) {
            throw new \RuntimeException('Failed to read uploaded PFX.');
        }

        return $content;
    }

    protected function validateCertificatePayload(string $pfxContent, string $password): void
    {
        PfxReader::readCertificatePem($pfxContent, $password);
    }

    protected function storeCertificate(string $cnpj, string $pfxContent, string $password): void
    {
        $storagePath = storage_path('app/nfse/pfx/' . $cnpj . '.pfx');

        if (!is_dir(dirname($storagePath))) {
            mkdir(dirname($storagePath), 0o700, true);
        }

        file_put_contents($storagePath, $pfxContent);
        chmod($storagePath, 0o600);

        $store = $this->makeSecretStore();
        $store->put('pfx/' . $cnpj, [
            'pfx_path' => $storagePath,
            'password' => $password,
        ]);
    }

    protected function purgeCertificateArtifacts(string $cnpj): void
    {
        $storagePath = storage_path('app/nfse/pfx/' . $cnpj . '.pfx');

        if (is_file($storagePath)) {
            unlink($storagePath);
        }

        try {
            $this->makeSecretStore()->delete('pfx/' . $cnpj);
        } catch (Throwable) {
            // Keep replacement flow resilient if old secret is already absent.
        }
    }

    protected function clearNfseSettings(): void
    {
        $nfseSettings = setting('nfse');

        if (!is_array($nfseSettings)) {
            return;
        }

        foreach (array_keys($nfseSettings) as $key) {
            setting()->forget('nfse.' . $key);
        }
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

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\Nfse\Support\IbgeLocalities;
use Modules\Nfse\Support\Lc116Catalog;
use Throwable;

class SettingsController extends Controller
{
    private const IBGE_BASE_URL = 'https://servicodados.ibge.gov.br/api/v1/localidades';

    public function edit(): \Illuminate\View\View
    {
        $settings = setting('nfse');

        return view('nfse::settings.edit', compact('settings'));
    }

    public function ufs(IbgeLocalities $ibgeLocalities): JsonResponse
    {
        try {
            $rows = Http::timeout(8)
                ->acceptJson()
                ->get(self::IBGE_BASE_URL . '/estados')
                ->throw()
                ->json();

            return response()->json([
                'data' => $ibgeLocalities->mapUfs(is_array($rows) ? $rows : []),
            ]);
        } catch (Throwable) {
            return response()->json([
                'data' => [],
                'message' => 'Failed to load UFs from IBGE.',
            ], 502);
        }
    }

    public function municipalities(string $uf, IbgeLocalities $ibgeLocalities): JsonResponse
    {
        $normalizedUf = strtoupper(trim($uf));
        if (!preg_match('/^[A-Z]{2}$/', $normalizedUf)) {
            return response()->json([
                'data' => [],
                'message' => 'Invalid UF.',
            ], 422);
        }

        try {
            $rows = Http::timeout(8)
                ->acceptJson()
                ->get(self::IBGE_BASE_URL . '/estados/' . $normalizedUf . '/municipios')
                ->throw()
                ->json();

            return response()->json([
                'data' => $ibgeLocalities->mapMunicipalities(is_array($rows) ? $rows : []),
            ]);
        } catch (Throwable) {
            return response()->json([
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
        ]);

        $nfseInput = $request->input('nfse', []);
        $nfseInput['uf'] = strtoupper((string) ($nfseInput['uf'] ?? ''));
        $nfseInput['item_lista_servico'] = preg_replace('/\D/', '', (string) ($nfseInput['item_lista_servico'] ?? ''));
        unset($nfseInput['item_lista_servico_display']);

        // Keep existing sensitive secrets unless user explicitly provides a new value.
        if (($nfseInput['bao_token'] ?? '') === '') {
            unset($nfseInput['bao_token']);
        }

        if (($nfseInput['bao_secret_id'] ?? '') === '') {
            unset($nfseInput['bao_secret_id']);
        }

        foreach ($nfseInput as $key => $value) {
            setting(['nfse.' . $key => $value]);
        }

        setting()->save();

        return redirect()->route('nfse.settings.edit')
            ->with('success', trans('nfse::general.saved'));
    }
}

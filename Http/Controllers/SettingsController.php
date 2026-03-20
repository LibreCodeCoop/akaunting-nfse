<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use App\Abstracts\Http\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LibreCodeCoop\NfsePHP\SecretStore\OpenBaoSecretStore;

class SettingsController extends Controller
{
    public function edit(): \Illuminate\View\View
    {
        $settings = setting('nfse');

        return view('nfse::settings.edit', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'nfse.cnpj_prestador'  => 'required|string|size:14',
            'nfse.municipio_ibge'  => 'required|string|size:7',
            'nfse.sandbox_mode'    => 'nullable|boolean',
            'nfse.bao_addr'        => 'required|url',
            'nfse.bao_mount'       => 'required|string',
            'nfse.bao_token'       => 'nullable|string',
            'nfse.bao_role_id'     => 'nullable|string',
            'nfse.bao_secret_id'   => 'nullable|string',
        ]);

        $nfseInput = $request->input('nfse', []);

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

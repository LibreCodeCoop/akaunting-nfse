<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use App\Models\Auth\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Modules\Nfse\Support\NfseCompanyProfileCatalog;
use Modules\Nfse\Support\NfseCompanyProfileManager;

/**
 * JSON backend for #420. Middleware owns settings permissions; membership
 * in the specified native Akaunting company is checked independently.
 */
final class CompanyProfileController extends Controller
{
    public function index(NfseCompanyProfileCatalog $catalog): JsonResponse
    {
        return response()->json($catalog->forUser($this->operator()));
    }

    public function store(
        Request $request,
        NfseCompanyProfileCatalog $catalog,
        NfseCompanyProfileManager $manager,
    ): JsonResponse {
        $data = $request->validate($this->rules($request, true));
        $companyId = (int) $data['company_id'];
        $catalog->assertAccessible($this->operator(), $companyId);

        try {
            $profile = $manager->create(
                $companyId,
                $this->uploadedContent($request->file('pfx_file')),
                (string) $data['pfx_password'],
                $data['fiscal_parameters'] ?? [],
            );
        } catch (\DomainException $error) {
            return $this->rejected($error);
        }

        return response()->json(['data' => $catalog->publicData($profile)], 201);
    }

    public function update(
        int $companyId,
        Request $request,
        NfseCompanyProfileCatalog $catalog,
        NfseCompanyProfileManager $manager,
    ): JsonResponse {
        $catalog->assertAccessible($this->operator(), $companyId);
        $data = $request->validate($this->rules($request, false));
        $file = $request->file('pfx_file');

        if (!$file instanceof UploadedFile && !array_key_exists('fiscal_parameters', $data)) {
            return response()->json(['reason' => 'empty_update'], 422);
        }

        try {
            $profile = $manager->update(
                $companyId,
                $data['fiscal_parameters'] ?? [],
                $file instanceof UploadedFile ? $this->uploadedContent($file) : null,
                $file instanceof UploadedFile ? (string) $data['pfx_password'] : null,
            );
        } catch (\DomainException $error) {
            return $this->rejected($error);
        }

        return response()->json(['data' => $catalog->publicData($profile)]);
    }

    public function destroy(
        int $companyId,
        NfseCompanyProfileCatalog $catalog,
        NfseCompanyProfileManager $manager,
    ): JsonResponse {
        $catalog->assertAccessible($this->operator(), $companyId);

        try {
            $manager->delete($companyId);
        } catch (\DomainException $error) {
            return $this->rejected($error);
        }

        return response()->json(null, 204);
    }

    public function cleanup(
        int $companyId,
        NfseCompanyProfileCatalog $catalog,
        NfseCompanyProfileManager $manager,
    ): JsonResponse {
        $catalog->assertAccessible($this->operator(), $companyId);

        try {
            $manager->cleanup($companyId);
        } catch (\DomainException $error) {
            return $this->rejected($error);
        }

        return response()->json(['state' => 'cleanup_completed']);
    }

    private function operator(): User
    {
        $operator = auth()->user();
        if (!$operator instanceof User) {
            abort(401);
        }

        return $operator;
    }

    /**
     * Metadata is a configuration assertion, not legal proof or permission
     * to emit a new DPS. No plaintext integration credential is accepted.
     *
     * @return array<string,string>
     */
    private function rules(Request $request, bool $creating): array
    {
        $fields = [
            'uf' => 'nullable|string|size:2|regex:/^[A-Za-z]{2}$/',
            'municipio_ibge' => 'nullable|string|regex:/^\d{7}$/',
            'municipio_nome' => 'nullable|string|max:255',
            'prestador_inscricao_municipal' => 'nullable|string|max:15',
            'prestador_telefone' => 'nullable|string|max:25',
            'prestador_email' => 'nullable|email|max:255',
            'sandbox_mode' => 'nullable|boolean',
            'emission_policy' => 'nullable|in:manual,emit_on_send',
            'opcao_simples_nacional' => 'nullable|in:1,2,3',
            'regime_especial_tributacao' => 'nullable|in:0,1,2,3,4,5,6,9',
            'regime_apuracao_simples_nacional' => 'nullable|in:1,2,3',
            'tributacao_issqn' => 'nullable|in:1,2,3,4',
            'tipo_retencao_iss' => 'nullable|in:1,2,3',
            'issqn_pais_resultado' => 'nullable|string|size:2|regex:/^[A-Za-z]{2}$/|not_regex:/^BR$/i',
            'issqn_tipo_imunidade' => 'nullable|in:1,2,3,4,5',
            'issqn_tipo_suspensao' => 'nullable|in:1,2',
            'issqn_numero_processo_suspensao' => 'nullable|string|regex:/^\d{30}$/',
            'ibs_cbs_ind_final' => 'nullable|in:0,1',
            'ibs_cbs_ind_dest' => 'nullable|in:0,1',
            'federal_piscofins_situacao_tributaria' => 'nullable|regex:/^\d+$/',
            'federal_piscofins_tipo_retencao' => 'nullable|regex:/^\d+$/',
            'ibs_cbs_enabled' => 'nullable|boolean',
            'ibs_cbs_c_ind_op' => 'nullable|regex:/^\d{6}$/',
            'ibs_cbs_cst' => 'nullable|regex:/^\d{3}$/',
            'ibs_cbs_c_class_trib' => 'nullable|regex:/^\d{6}$/',
        ];

        $rules = [
            'company_id' => $creating ? 'required|integer|min:1' : 'prohibited',
            'pfx_file' => ($creating ? 'required' : 'nullable') . '|file|extensions:pfx,p12|max:1024',
            'pfx_password' => ($request->hasFile('pfx_file') ? 'required' : 'prohibited')
                . '|string|max:255',
            'fiscal_parameters' => 'sometimes|array:' . implode(',', array_keys($fields)),
        ];

        foreach ($fields as $field => $rule) {
            $rules['fiscal_parameters.' . $field] = $rule;
        }

        return $rules;
    }

    private function uploadedContent(mixed $file): string
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new \DomainException('A valid PFX upload is required.');
        }

        $path = $file->getRealPath();
        $content = $path !== false ? file_get_contents($path) : false;

        if (!is_string($content) || $content === '') {
            throw new \DomainException('PFX upload content is unreadable.');
        }

        return $content;
    }

    private function rejected(\DomainException $error): JsonResponse
    {
        // Domain messages are fixed and sanitized. Never return upstream
        // Vault/OpenSSL responses or submitted certificate contents.
        return response()->json([
            'reason' => 'profile_operation_blocked',
            'message' => $error->getMessage(),
        ], 409);
    }
}

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Nfse\Models\CompanyService;
use Modules\Nfse\Support\Lc116Catalog;

class CompanyServiceController extends Controller
{
    /**
     * Create a new CompanyService record
     */
    public function create(): View
    {
        $catalog = new Lc116Catalog();
        $items = $catalog->search(); // Get all items

        return view('nfse::services.create', [
            'items' => $items,
        ]);
    }

    /**
     * Store a newly created CompanyService in storage
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'item_lista_servico' => ['required', 'string', 'max:10'],
            'codigo_tributacao_nacional' => ['nullable', 'string', 'size:6'],
            'aliquota' => ['required', 'numeric', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $validated['item_lista_servico'] = preg_replace('/\D+/', '', (string) $validated['item_lista_servico']) ?: '';
        $validated['codigo_tributacao_nacional'] = preg_replace('/\D+/', '', (string) ($validated['codigo_tributacao_nacional'] ?? '')) ?: '';
        $validated['codigo_tributacao_nacional'] = $validated['codigo_tributacao_nacional'] !== ''
            ? $validated['codigo_tributacao_nacional']
            : null;

        $companyId = $this->resolveCompanyId($request);

        if ($companyId <= 0) {
            abort(403);
        }

        // Check if this service already exists
        $existing = CompanyService::where('company_id', $companyId)
            ->where('item_lista_servico', $validated['item_lista_servico'])
            ->first();

        if ($existing) {
            return back()->withInput()->with('error', trans('nfse::general.settings.services.service_duplicate'));
        }

        CompanyService::create([
            'company_id' => $companyId,
            ...$validated,
            'is_active' => $validated['is_active'] ?? true,
            'is_default' => !CompanyService::where('company_id', $companyId)->where('is_default', true)->exists(),
        ]);

        $createdService = CompanyService::where('company_id', $companyId)
            ->where('item_lista_servico', $validated['item_lista_servico'])
            ->latest('id')
            ->first();

        if ($createdService !== null && $createdService->is_default) {
            $this->setDefaultService($createdService);
        }

        return redirect()->route('nfse.settings.edit', ['tab' => 'services'])
            ->with('success', trans('nfse::general.settings.services.service_added'));
    }

    /**
     * Show the form for editing a CompanyService
     */
    public function edit(Request $request, CompanyService $service): View
    {
        $this->authorizeServiceOwnership($request, $service);

        $catalog = new Lc116Catalog();
        $items = $catalog->search();

        return view('nfse::services.edit', [
            'service' => $service,
            'items' => $items,
        ]);
    }

    /**
     * Update a CompanyService in storage
     */
    public function update(Request $request, CompanyService $service): RedirectResponse
    {
        $this->authorizeServiceOwnership($request, $service);

        $validated = $request->validate([
            'codigo_tributacao_nacional' => ['nullable', 'string', 'size:6'],
            'aliquota' => ['required', 'numeric', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $validated['codigo_tributacao_nacional'] = preg_replace('/\D+/', '', (string) ($validated['codigo_tributacao_nacional'] ?? '')) ?: '';
        $validated['codigo_tributacao_nacional'] = $validated['codigo_tributacao_nacional'] !== ''
            ? $validated['codigo_tributacao_nacional']
            : null;

        $service->update($validated);

        if ($service->is_default) {
            $this->syncDefaultServiceSettings($service);
        }

        return redirect()->route('nfse.settings.edit', ['tab' => 'services'])
            ->with('success', trans('nfse::general.settings.services.service_updated'));
    }

    /**
     * Delete a CompanyService
     */
    public function destroy(Request $request, CompanyService $service): RedirectResponse
    {
        $this->authorizeServiceOwnership($request, $service);

        // If this was the default, unset default flag
        if ($service->is_default) {
            $service->update(['is_default' => false]);
        }

        $service->delete();

        return redirect()->route('nfse.settings.edit', ['tab' => 'services'])
            ->with('success', trans('nfse::general.settings.services.service_deleted'));
    }

    /**
     * Make a service the default for this company
     */
    public function makeDefault(Request $request, CompanyService $service): RedirectResponse
    {
        $this->authorizeServiceOwnership($request, $service);

        if (! $service->is_active) {
            $service->update(['is_active' => true]);
        }

        $this->setDefaultService($service);

        return redirect()->route('nfse.settings.edit', ['tab' => 'services'])
            ->with('success', trans('nfse::general.settings.services.service_made_default'));
    }

    /**
     * Toggle active status of a CompanyService.
     */
    public function toggleActive(Request $request, CompanyService $service): RedirectResponse
    {
        $this->authorizeServiceOwnership($request, $service);

        $newStatus = ! $service->is_active;
        $wasDefault = $service->is_default;

        $service->update([
            'is_active' => $newStatus,
            'is_default' => $newStatus ? $service->is_default : false,
        ]);

        if (! $newStatus && $wasDefault) {
            $fallback = CompanyService::where('company_id', $service->company_id)
                ->where('id', '!=', $service->id)
                ->where('is_active', true)
                ->orderBy('is_default', 'desc')
                ->orderBy('id')
                ->first();

            if ($fallback !== null) {
                $this->setDefaultService($fallback);
            }
        }

        if ($newStatus) {
            $hasDefault = CompanyService::where('company_id', $service->company_id)
                ->where('is_active', true)
                ->where('is_default', true)
                ->exists();

            if (! $hasDefault) {
                $this->setDefaultService($service);
            }
        }

        return redirect()->route('nfse.settings.edit', ['tab' => 'services'])
            ->with('success', $newStatus
                ? trans('nfse::general.settings.services.service_activated')
                : trans('nfse::general.settings.services.service_deactivated'));
    }

    private function resolveCompanyId(?Request $request = null): int
    {
        $companyId = 0;

        if ($request !== null) {
            $routeCompanyId = $request->route('company_id');
            $companyId = is_numeric($routeCompanyId) ? (int) $routeCompanyId : 0;
        }

        if ($companyId <= 0 && function_exists('company_id')) {
            $companyId = (int) (company_id() ?? 0);
        }

        if ($companyId <= 0) {
            $user = auth()->user();
            $companyId = $user !== null && isset($user->company_id) ? (int) $user->company_id : 0;
        }

        return $companyId;
    }

    private function authorizeServiceOwnership(?Request $request, CompanyService $service): void
    {
        $companyId = $this->resolveCompanyId($request);

        if ($companyId <= 0 || (int) $service->company_id !== $companyId) {
            abort(403);
        }
    }

    private function setDefaultService(CompanyService $service): void
    {
        CompanyService::where('company_id', $service->company_id)
            ->where('id', '!=', $service->id)
            ->update(['is_default' => false]);

        $service->update(['is_default' => true]);

        $this->syncDefaultServiceSettings($service);
    }

    private function syncDefaultServiceSettings(CompanyService $service): void
    {
        setting([
            'nfse.item_lista_servico' => (string) $service->item_lista_servico,
            'nfse.codigo_tributacao_nacional' => (string) $service->codigo_tributacao_nacional,
            'nfse.aliquota' => number_format((float) $service->aliquota, 2, '.', ''),
        ]);

        setting()->save();
    }
}

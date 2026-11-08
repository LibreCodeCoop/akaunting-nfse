<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Nfse\Application\CategoryIssuancePolicy;

final class CategoryIssuancePolicyController extends Controller
{
    public function index(CategoryIssuancePolicy $policies): \Illuminate\View\View
    {
        $companyId = (int) company_id();
        abort_unless($companyId > 0, 403);

        return view('nfse::settings.category-issuance', [
            'categories' => $policies->categories($companyId),
        ]);
    }

    public function show(CategoryIssuancePolicy $policies): JsonResponse
    {
        $companyId = (int) company_id();
        abort_unless($companyId > 0, 403);

        return response()->json(['data' => $policies->categories($companyId)]);
    }

    public function store(Request $request, CategoryIssuancePolicy $policies): JsonResponse|RedirectResponse
    {
        $companyId = (int) company_id();
        $actorId = (int) auth()->id();
        abort_unless($companyId > 0 && $actorId > 0, 403);

        $validated = $request->validate([
            'category_id' => 'required|integer|min:1',
            'mode' => 'required|in:issue,do_not_issue',
            'justification' => 'required|string|min:12|max:4000',
        ]);
        $row = $policies->record(
            $companyId,
            (int) $validated['category_id'],
            $actorId,
            $validated['mode'],
            $validated['justification'],
        );

        if ($request->expectsJson()) {
            return response()->json(['data' => $row], 201);
        }

        return redirect()->route('nfse.settings.category-issuance.index')
            ->with('success', trans('nfse::general.category_issuance.saved'));
    }
}

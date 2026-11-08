{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.category_issuance.title') }}</x-slot>
    <x-slot name="content">
        <div class="max-w-5xl space-y-5" data-nfse-category-issuance-settings="true">
            <div>
                <a class="text-sm text-indigo-700 underline" href="{{ route('nfse.settings.edit') }}">
                    {{ trans('nfse::general.category_issuance.back') }}
                </a>
                <h1 class="mt-2 text-xl font-semibold text-gray-900">{{ trans('nfse::general.category_issuance.title') }}</h1>
                <p class="mt-2 text-sm text-gray-600">{{ trans('nfse::general.category_issuance.help') }}</p>
                <p class="mt-1 text-sm font-medium text-amber-800">{{ trans('nfse::general.category_issuance.prospective_only') }}</p>
            </div>

            @if(session('success'))
                <p class="rounded border border-green-300 bg-green-50 px-4 py-3 text-sm" role="status">{{ session('success') }}</p>
            @endif
            @if($errors->any())
                <div role="alert" class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                    @foreach($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <section class="overflow-hidden rounded-lg border bg-white" aria-labelledby="nfse-category-settings-list-title">
                <h2 id="nfse-category-settings-list-title" class="border-b px-4 py-3 text-base font-semibold">
                    {{ trans('nfse::general.category_issuance.list_title') }}
                </h2>
                <div class="divide-y" data-nfse-category-issuance-list="true">
                    @forelse($categories as $category)
                        <article class="space-y-2 px-4 py-3" data-nfse-category-id="{{ $category['id'] }}">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h3 class="text-sm font-semibold">{{ $category['name'] }}</h3>
                                    <p class="text-xs text-gray-500">{{ $category['type'] }}</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-700"
                                        data-nfse-category-mode="{{ $category['policy']['mode'] ?? 'unconfigured' }}">
                                        {{ trans('nfse::general.category_issuance.' . ($category['policy']['mode'] ?? 'unconfigured')) }}
                                    </span>
                                    @can('update-nfse-settings')
                                        <details class="text-sm">
                                            <summary class="cursor-pointer font-medium text-indigo-700">{{ trans('nfse::general.category_issuance.edit') }}</summary>
                                            <form method="POST" action="{{ route('nfse.settings.category-issuance.store') }}"
                                                class="mt-3 min-w-64 space-y-3 rounded border bg-gray-50 p-3">
                                                @csrf
                                                <input type="hidden" name="category_id" value="{{ $category['id'] }}">
                                                <label class="block text-sm">
                                                    {{ trans('nfse::general.category_issuance.mode') }}
                                                    <select name="mode" class="mt-1 block w-full rounded border bg-white px-2 py-2" required>
                                                        <option value="issue" @selected(($category['policy']['mode'] ?? 'issue') === 'issue')>
                                                            {{ trans('nfse::general.category_issuance.issue') }}
                                                        </option>
                                                        <option value="do_not_issue" @selected(($category['policy']['mode'] ?? '') === 'do_not_issue')>
                                                            {{ trans('nfse::general.category_issuance.do_not_issue') }}
                                                        </option>
                                                    </select>
                                                </label>
                                                <label class="block text-sm">
                                                    {{ trans('nfse::general.category_issuance.justification') }}
                                                    <textarea name="justification" minlength="12" maxlength="4000" rows="2"
                                                        required class="mt-1 block w-full rounded border px-2 py-2"
                                                        placeholder="{{ trans('nfse::general.category_issuance.justification_hint') }}"></textarea>
                                                </label>
                                                <button type="submit" class="rounded bg-indigo-700 px-3 py-2 text-white">
                                                    {{ trans('nfse::general.category_issuance.save') }}
                                                </button>
                                            </form>
                                        </details>
                                    @endcan
                                </div>
                            </div>
                            @if(!empty($category['policy']))
                                <p class="text-xs text-gray-600">
                                    {{ trans('nfse::general.category_issuance.recorded_at') }}:
                                    {{ $category['policy']['recorded_at'] }} ·
                                    {{ $category['policy']['justification'] }}
                                </p>
                            @endif
                        </article>
                    @empty
                        <p class="px-4 py-4 text-sm text-gray-600">{{ trans('nfse::general.category_issuance.no_categories') }}</p>
                    @endforelse
                </div>
            </section>
        </div>
    </x-slot>
</x-layouts.admin>

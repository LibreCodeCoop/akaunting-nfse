{{--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
--}}
<x-layouts.admin>
    <x-slot name="title">{{ trans('nfse::general.settings.federal.approximate_title') }}</x-slot>
    <x-slot name="content">
        <section id="nfse-approximate-taxes" class="max-w-4xl space-y-5"
            data-list-url="{{ route('nfse.settings.approximate-taxes.resources') }}"
            data-store-url="{{ route('nfse.settings.approximate-taxes.store') }}">
            <a class="underline" href="{{ route('nfse.settings.edit', ['tab' => 'federal']) }}">&larr; {{ trans('nfse::general.go_to_settings') }}</a>
            <h1 class="font-semibold text-xl">{{ trans('nfse::general.settings.federal.approximate_title') }}</h1>
            <p class="text-sm">{{ trans('nfse::general.settings.federal.approximate_notice') }}</p>
            @if(session('success'))
                <p class="rounded border border-green-400 px-3 py-2">{{ session('success') }}</p>
            @endif
            @if($errors->any())
                <ul role="alert" class="rounded border border-red-400 px-3 py-2">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif
            <form id="approximate-taxes-form" action="{{ route('nfse.settings.approximate-taxes.store') }}" method="POST" class="rounded border p-5 space-y-3">
                @csrf
                <p class="font-semibold">Nova revisão de fonte (não altera revisões anteriores)</p>
                <label class="block">Código tributário nacional do serviço (cTribNac, 6 dígitos)
                    <input name="national_service_code" required pattern="[0-9]{6}" maxlength="6" class="block w-full rounded border p-2" value="{{ old('national_service_code', '') }}" placeholder="010701">
                </label>
                <label class="block">Regime do prestador
                    <select name="simples_option" required class="block w-full rounded border p-2">
                        <option value="1" @selected(old('simples_option') == '1')>1 - Não optante</option>
                        <option value="2" @selected(old('simples_option') == '2')>2 - MEI</option>
                        <option value="3" @selected(old('simples_option') == '3')>3 - Simples Nacional</option>
                    </select>
                </label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <label>Competência inicial <input name="competence_from" type="date" required class="block w-full rounded border p-2" value="{{ old('competence_from') }}"></label>
                    <label>Competência final (opcional) <input name="competence_until" type="date" class="block w-full rounded border p-2" value="{{ old('competence_until') }}"></label>
                </div>
                <label class="block">Natureza da origem
                    <select name="source_kind" required class="block w-full rounded border p-2">
                        <option value="independent_estimate">Estimativa de instituição identificada</option>
                        <option value="taxpayer_declaration">Declaração fundamentada do contribuinte</option>
                        <option value="documented_zero">Zero documentado (três percentuais)</option>
                    </select>
                </label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <label>Fonte / referência documental
                        <input name="source_name" required maxlength="160" class="block w-full rounded border p-2" value="{{ old('source_name') }}">
                    </label>
                    <label>Versão ou identificação do documento
                        <input name="source_version" required maxlength="80" class="block w-full rounded border p-2" value="{{ old('source_version') }}">
                    </label>
                </div>
                <label class="block">Referência documental ou identificador do estudo arquivado
                    <input name="source_reference" required minlength="5" maxlength="255" class="block w-full rounded border p-2" value="{{ old('source_reference') }}" placeholder="Documento interno, edição de estudo ou URL">
                </label>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <label>Federal (%) <input name="federal_percent" required class="block w-full rounded border p-2" placeholder="0.00" value="{{ old('federal_percent') }}"></label>
                    <label>Estadual (%) <input name="state_percent" required class="block w-full rounded border p-2" placeholder="0.00" value="{{ old('state_percent') }}"></label>
                    <label>Municipal (%) <input name="municipal_percent" required class="block w-full rounded border p-2" placeholder="0.00" value="{{ old('municipal_percent') }}"></label>
                </div>
                <p class="text-xs">Informe zero somente quando sustentado pela fonte identificada. O cadastro não comprova que a fonte é juridicamente válida, nem dispensa informação ao consumidor por outro meio quando exigida. As versões são preservadas e a nota autorizada mantém seu XML original.</p>
                <button type="submit" class="rounded bg-green-700 px-4 py-2 text-white">Registrar revisão</button>
                <p id="approximate-taxes-feedback" role="status" aria-live="polite" class="text-sm" hidden></p>
            </form>
            <h2 class="font-semibold">Revisões registradas nesta empresa</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr><th>Serviço / regime</th><th>Vigência</th><th>Fonte / versão</th><th>Federal / estadual / municipal</th></tr></thead>
                    <tbody id="approximate-taxes-body">
                        <tr><td colspan="4">Carregando revisões da API...</td></tr>
                    </tbody>
                </table>
            </div>
            <nav aria-label="Páginas das revisões de tributos aproximados" class="flex items-center gap-3">
                <button id="approximate-prev" type="button" class="rounded border px-3 py-2" disabled>Anterior</button>
                <span id="approximate-page" class="text-sm" aria-live="polite"></span>
                <button id="approximate-next" type="button" class="rounded border px-3 py-2" disabled>Próxima</button>
            </nav>
        </section>
        <script src="{{ asset('modules/Nfse/Resources/assets/js/fiscal-tax-admin.js?v=' . module_version('nfse')) }}" data-nfse-fiscal-tax-admin="true"></script>
    </x-slot>
</x-layouts.admin>

{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
<x-layouts.admin>
    <x-slot name="title">Associações fiscais de impostos</x-slot>
    <x-slot name="content">
        <div id="nfse-tax-associations" class="max-w-5xl space-y-5"
            data-list-url="{{ route('nfse.settings.tax-associations.resources') }}"
            data-store-url="{{ route('nfse.settings.tax-associations.store') }}">
            <a href="{{ route('nfse.settings.edit') }}" class="text-sm underline">&larr; Voltar às configurações NFS-e</a>
            <header>
                <h1 class="text-xl font-semibold">Associações fiscais dos impostos existentes</h1>
                <p class="mt-2 text-sm text-gray-600">
                    Identidade e papel fiscais exigem confirmação do responsável tributário. A classificação não calcula
                    incidência, não altera impostos ou faturas e não comprova conformidade legal. As revisões confirmadas
                    permanecem no histórico, com vigência e fonte documental.
                </p>
            </header>
            @if(session('success'))
                <p role="status" class="rounded border border-green-300 p-3">{{ session('success') }}</p>
            @endif
            @if($errors->any())
                <ul role="alert" class="rounded border border-red-300 p-3 text-sm text-red-800">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif
            <section class="rounded border bg-white p-4 space-y-3" aria-labelledby="existing-taxes-title">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="existing-taxes-title" class="font-semibold">Impostos cadastrados nesta empresa</h2>
                    <a href="{{ route('taxes.index') }}" class="text-sm underline">Abrir cadastro de impostos do Akaunting</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="text-left px-3 py-2">Imposto / ID</th>
                                <th class="text-left px-3 py-2">Tipo / alíquota contábil</th>
                                <th class="text-left px-3 py-2">Revisões fiscais confirmadas</th>
                            </tr>
                        </thead>
                        <tbody id="tax-associations-body">
                            <tr><td colspan="3" class="px-3 py-4 text-gray-600">Carregando impostos da API...</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
            @can('update-nfse-settings')
                <section class="rounded border bg-white p-4 space-y-3" aria-labelledby="confirm-tax-title">
                    <h2 id="confirm-tax-title" class="font-semibold">Confirmar uma associação fiscal</h2>
                    <p class="text-sm text-gray-600">
                        A revisão será adicionada sem modificar períodos existentes. Vigências sobrepostas ou mudanças
                        de enquadramento de um período já confirmado exigem revisão administrativa separada; não substitua
                        silenciosamente associações históricas.
                    </p>
                        <form id="tax-association-form" method="POST" action="{{ route('nfse.settings.tax-associations.store') }}" class="space-y-4">
                            @csrf
                            <label class="block text-sm">Imposto existente
                                <select name="tax_id" required class="mt-1 block w-full rounded border p-2">
                                    <option value="">Selecione um imposto desta empresa</option>
                                </select>
                            </label>
                            <div class="grid gap-3 md:grid-cols-2">
                                <label class="block text-sm">Identidade fiscal (revisada pelo responsável tributário)
                                    <select name="fiscal_identity" required class="mt-1 block w-full rounded border p-2">
                                        <option value="">Selecione</option>
                                    </select>
                                </label>
                                <label class="block text-sm">Papel fiscal
                                    <select name="fiscal_role" required class="mt-1 block w-full rounded border p-2">
                                        <option value="">Selecione</option>
                                    </select>
                                </label>
                            </div>
                            <div class="grid gap-3 md:grid-cols-2">
                                <label class="block text-sm">Vigência inicial
                                    <input type="date" name="effective_from" required value="{{ old('effective_from') }}" class="mt-1 block w-full rounded border p-2">
                                </label>
                                <label class="block text-sm">Vigência final (opcional)
                                    <input type="date" name="effective_until" value="{{ old('effective_until') }}" class="mt-1 block w-full rounded border p-2">
                                </label>
                            </div>
                            <label class="block text-sm">Referência documental da classificação
                                <input type="text" name="source_reference" maxlength="255" minlength="5" required
                                       value="{{ old('source_reference') }}" class="mt-1 block w-full rounded border p-2"
                                       placeholder="Parecer, documento fiscal interno ou fonte normativa">
                            </label>
                            <label class="flex items-start gap-2 text-sm">
                                <input type="checkbox" name="acknowledge" value="1" required class="mt-1">
                                <span>Confirmo que revisei a identidade, papel, vigência e fonte; esta ação não cria tributos, altera valores nem substitui análise jurídico-contábil.</span>
                            </label>
                            <button type="submit" class="rounded bg-green-700 px-4 py-2 text-white">Registrar confirmação</button>
                            <p id="tax-association-feedback" role="status" aria-live="polite" class="text-sm" hidden></p>
                        </form>
                </section>
            @endcan
        </div>
        <script src="{{ asset('modules/Nfse/Resources/assets/js/fiscal-tax-admin.js?v=' . module_version('nfse')) }}" data-nfse-fiscal-tax-admin="true"></script>
    </x-slot>
</x-layouts.admin>

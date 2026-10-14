{{-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
<x-layouts.admin>
    <x-slot name="title">Associações fiscais de impostos</x-slot>
    <x-slot name="content">
        <div class="max-w-5xl space-y-5">
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
                        <tbody>
                            @forelse($taxes as $tax)
                                <tr class="border-t">
                                    <td class="px-3 py-2">{{ $tax->name }} (#{{ $tax->id }})</td>
                                    <td class="px-3 py-2">{{ $tax->type }} / {{ $tax->rate }}%</td>
                                    <td class="px-3 py-2">
                                        @if(!isset($associationsByTax[$tax->id]) || $associationsByTax[$tax->id]->isEmpty())
                                            <span class="text-amber-800">Sem associação confirmada — identidade desconhecida</span>
                                        @else
                                            <ul class="space-y-2">
                                                @foreach($associationsByTax[$tax->id] as $association)
                                                    <li>
                                                        <strong>{{ $association->fiscal_identity }} / {{ $association->fiscal_role }}</strong>
                                                        · {{ $association->effective_from }} a {{ $association->effective_until ?: 'sem término' }}
                                                        <span class="block text-xs text-gray-600">
                                                            Fonte: {{ $association->source_reference }} · Confirmado por #{{ $association->confirmed_by }}
                                                            @if($association->confirmed_at) em {{ $association->confirmed_at->format('Y-m-d') }} @endif
                                                        </span>
                                                        @if(($tax->type === 'withholding') !== ($association->fiscal_role === 'withheld_by_payer'))
                                                            <span class="block text-xs text-amber-800">Revisar compatibilidade entre tipo contábil e papel fiscal.</span>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-3 py-4 text-gray-600">Nenhum imposto cadastrado nesta empresa. Não será criado imposto automaticamente.</td></tr>
                            @endforelse
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
                    @if($taxes->isNotEmpty())
                        <form method="POST" action="{{ route('nfse.settings.tax-associations.store') }}" class="space-y-4">
                            @csrf
                            <label class="block text-sm">Imposto existente
                                <select name="tax_id" required class="mt-1 block w-full rounded border p-2">
                                    <option value="">Selecione um imposto desta empresa</option>
                                    @foreach($taxes as $tax)
                                        <option value="{{ $tax->id }}" @selected((string) old('tax_id') === (string) $tax->id)>
                                            {{ $tax->name }} (#{{ $tax->id }}) — {{ $tax->type }} / {{ $tax->rate }}%
                                        </option>
                                    @endforeach
                                </select>
                            </label>
                            <div class="grid gap-3 md:grid-cols-2">
                                <label class="block text-sm">Identidade fiscal (revisada pelo responsável tributário)
                                    <select name="fiscal_identity" required class="mt-1 block w-full rounded border p-2">
                                        <option value="">Selecione</option>
                                        @foreach($identities as $identity)
                                            <option value="{{ $identity->value }}" @selected(old('fiscal_identity') === $identity->value)>{{ strtoupper($identity->value) }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="block text-sm">Papel fiscal
                                    <select name="fiscal_role" required class="mt-1 block w-full rounded border p-2">
                                        <option value="">Selecione</option>
                                        @foreach($roles as $role)
                                            <option value="{{ $role->value }}" @selected(old('fiscal_role') === $role->value)>{{ $role->value }}</option>
                                        @endforeach
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
                        </form>
                    @endif
                </section>
            @endcan
        </div>
    </x-slot>
</x-layouts.admin>

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
const admin = require('../Resources/assets/js/company-profile-admin.js');

const company = (id) => ({ company_id: id, name: 'Empresa sintética ' + id });
const eligible = (...ids) => ({ profiles: [], eligible_companies: ids.map(company) });

test('Add follows the authorized company eligibility matrix', () => {
    assert.equal(admin.showAdd(eligible(), true), false);
    assert.equal(admin.showAdd(eligible(1), true), true);
    assert.equal(admin.showAdd({
        profiles: [{ company_id: 1 }], eligible_companies: [],
    }, true), false);
    assert.equal(admin.showAdd({
        profiles: [{ company_id: 1 }], eligible_companies: [company(2)],
    }, true), true);
    assert.equal(admin.showAdd({
        profiles: [{ company_id: 1 }, { company_id: 2 }],
        eligible_companies: [],
    }, true), false);
    assert.equal(admin.showAdd(eligible(1, 2), false), false);
});

test('Vault availability restricts new registration, not eligible enumeration', () => {
    const snapshot = eligible(2);
    for (const state of ['failed', 'unconfigured', 'unverified']) {
        assert.equal(admin.canCreate(snapshot, state, true), false);
        assert.deepEqual(admin.visibleEligible(snapshot), [company(2)]);
    }
    assert.equal(admin.canCreate(snapshot, 'operational', true), true);
    assert.equal(admin.canCreate(snapshot, 'operational', false), false);
});

test('fiscal status is distinct from generic Vault availability', () => {
    const profile = {
        fiscal_readiness: { pending: ['uf', 'certificate_validity'] },
    };
    assert.deepEqual(admin.pendingLabels(profile), ['UF do prestador', 'Validade do certificado']);
});

test('ISSQN-specific pending fields have actionable labels', () => {
    const profile = {
        fiscal_readiness: { pending: [
            'issqn_pais_resultado',
            'issqn_tipo_imunidade',
            'issqn_tipo_suspensao',
            'issqn_numero_processo_suspensao',
        ] },
    };
    assert.deepEqual(admin.pendingLabels(profile), [
        'País do resultado do serviço',
        'Tipo de imunidade do ISSQN',
        'Tipo de suspensão do ISSQN',
        'Processo de suspensão do ISSQN',
    ]);
});

test('API permission, validation, and domain errors remain intelligible', () => {
    assert.match(admin.errorMessage(403, { message: 'internal secret' }), /não autorizado/);
    assert.equal(admin.errorMessage(422, { errors: { company_id: ['Empresa inválida'] } }), 'Empresa inválida');
    assert.equal(admin.errorMessage(409, { message: 'Profile deletion remains pending certificate cleanup.' }),
        'Profile deletion remains pending certificate cleanup.');
});

test('fiscal regime transitions keep incompatible saved choices visible for review', () => {
    const state = admin.specialRegimeUiState;
    assert.deepEqual(state('3', '1', '9', ''), {
        canSelectSpecial: false, hasIncompatibleSelection: true,
        requiresRuntimeReview: true, reason: 'sn_apuration_missing',
    });
    assert.equal(state('1', '1', '9').canSelectSpecial, true);
    assert.equal(state('2', '1', '9').hasIncompatibleSelection, true);
    assert.equal(state('3', '1', '9', '1').hasIncompatibleSelection, true);
    assert.equal(state('3', '1', '9', '2').canSelectSpecial, true);
    assert.equal(state('3', '1', '9', '3').canSelectSpecial, true);
    assert.equal(state('3', '3', '9', '2').hasIncompatibleSelection, true);
    assert.equal(state('3', '3', '0', '2').hasIncompatibleSelection, false);
});

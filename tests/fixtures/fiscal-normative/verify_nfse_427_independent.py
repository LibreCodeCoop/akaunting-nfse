#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Independent conditional reference checks for #427; synthetic only.

No Akaunting/nfse-php code is executed. All rates and start dates must be
re-reviewed against the primary cited legislation before being used to issue
fiscal documents. This is NOT an implementable tax engine.
"""
from __future__ import annotations
from datetime import date
from decimal import Decimal, ROUND_HALF_EVEN
import json
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parent
ORACLES = ROOT / 'nfse_427_oraculos_sinteticos_2026-10-12.json'
TEMPORAL = ROOT / 'nfse_427_matriz_temporal_sintetica_2026-10-12.json'
CENT = Decimal('0.01')

def money(base: str, rate: str) -> str:
    return str((Decimal(base) * Decimal(rate) / 100).quantize(CENT, rounding=ROUND_HALF_EVEN))

def check_financial(fixtures: dict[str, Any]) -> tuple[int, int]:
    cases = fixtures['cases']
    by_id = {row['id']: row for row in cases}
    assert len(by_id) == len(cases), 'Duplicate financial case IDs'
    for row in cases:
        assert row.get('source_refs') or row.get('source'), f"No authority for {row['id']}"
        for code in row.get('source_refs', []):
            assert code in fixtures['source_catalog'], f"Unknown source {code} for {row['id']}"
    expected = {
        'RET-01': {'pis_retido': money('10000', '0.65'), 'cofins_retido': money('10000','3'), 'csll_retida': money('10000','1'), 'vRetCSLL': money('10000','4.65'), 'tpRetPisCofins':3},
        'RET-02': {'pis_retido': money('10000','0.65'), 'cofins_retido': money('10000','3'), 'csll_retida': '0.00', 'vRetCSLL': money('10000','3.65'), 'tpRetPisCofins':4},
        'RET-03': {'calculated_candidate':money('200','4.65'), 'actually_withheld':'0.00'},
        'RET-04': {'actually_withheld':money('215.27','4.65')},
        'IRRF-01': {'irrf':money('10000','1.5')},
        'IRRF-02': {'irrf':money('10000','4.8')},
        'IRRF-03': {'irrf':money('10000','4.8')},
        'CP-01': {'retencao_previdenciaria':money('10000','11')},
        'CP-03': {'retencao_previdenciaria_condicional':money('10000','11')},
        'PISCOFINS-01': {'pis_proprio_bruto':money('10000','0.65'), 'cofins_propria_bruta':money('10000','3')},
        'PISCOFINS-02': {'pis_proprio_bruto':money('10000','1.65'), 'cofins_propria_bruta':money('10000','7.6')},
        'IBSCBS-01': {'ibs_test':money('10000','0.1'), 'cbs_test':money('10000','0.9')},
        'IBS-2027': {'ibs_estadual':money('10000','0.05'), 'ibs_municipal':money('10000','0.05')},
        'ISS-ARITH': {'iss_if_rate_3pct':money('10000','3')},
        'ISS-SP-2026-101': {'iss_proprio_condicional':money('10000','2.9')},
        'ISS-SP-2026-109': {'iss_proprio_condicional':money('10000','2')},
        'NT007-HALFEVEN-01': {'rounded_value':str(Decimal('1.005').quantize(CENT,rounding=ROUND_HALF_EVEN))},
        'NT007-HALFEVEN-02': {'rounded_value':str(Decimal('1.015').quantize(CENT,rounding=ROUND_HALF_EVEN))},
    }
    checked = 0
    for key, expectation in expected.items():
        got = by_id[key]['expected']
        for field, correct in expectation.items():
            assert got.get(field)==correct, f'Financial {key}/{field}: {got.get(field)} != {correct}'
            checked += 1
    # Non-monetary cases are deliberately not certified as law by arithmetic.
    for case_id,expected_status in [
        ('RET-05','not_required'), ('RET-06','not_required')
    ]:
        assert by_id[case_id]['expected']['social_withholding_under_art30'] == expected_status
    assert by_id['TRP-01']['expected']['tax_approximation'] == 'unverifiable'
    assert by_id['TRP-01']['expected']['cannot_autofill_zero'] is True
    assert by_id['TRP-03']['expected']['disclosure_under_decree_8264']=='optional'
    assert by_id['ISS-ARITH']['expected']['fiscal_status']=='unverifiable'
    assert by_id['IBS-2027']['expected']['cbs_rate']=='unverifiable_without_relevant_2027_rate'
    return len(cases), checked

DEFERRED={'1.03','1.05','1.09','16.01'}

def rtc_obligation(row:dict[str,Any])->bool|str:
    regime=row['regime']; category=row['operation']; when=row['taxable_event_date'];subitem=row['lc116_subitem']
    if regime not in {'simples','non_simples'} or category=='unknown' or when is None:
        return 'unverifiable'
    d=date.fromisoformat(when)
    if regime=='simples':
        return d>=date(2027,1,1)
    if category in {'digital_platform_supply_subject_to_art22','real_estate_rental','condominium_legal_charges'}:
        return d>=date(2026,12,1)
    if category=='iss_deferred_subitem' and subitem in DEFERRED:
        return d>=date(2026,12,1)
    if category=='ordinary_iss_service' and subitem not in DEFERRED:
        return d>=date(2026,10,1)
    return 'unverifiable'

def check_temporal(f: dict[str,Any])->int:
    rows=f['cases'];ids=[c['id'] for c in rows]
    assert len(ids)==len(set(ids)), 'Duplicate RTC IDs'
    for row in rows:
        assert row['primary_source'] in f['sources']
        expected=rtc_obligation(row)
        assert expected==row['rtc_document_obligation_started'], f"RTC {row['id']}: {row['rtc_document_obligation_started']} != {expected}"
        assert row['protocol_contract']=='independent_environment_specific_verification_required', f"Schema gate confusion in {row['id']}"
    assert set(ids)==set([x['id'] for x in rows]), 'Malformed data'
    return len(rows)

if __name__=='__main__':
    ff=json.loads(ORACLES.read_text(encoding='utf-8'))
    tf=json.loads(TEMPORAL.read_text(encoding='utf-8'))
    totalf,checked=check_financial(ff)
    totalt=check_temporal(tf)
    print(json.dumps({
        'status':'pass','financial_cases_reviewed':totalf,
        'exact_monetary_field_assertions':checked,
        'rtc_cases_asserted':totalt,
        'caveats':['All assumptions are conditional, not eligibility proofs',
          'This checks independent arithmetic/RTC cutovers, not operational XML/XSD activation',
          'Code-level business rule tests and fiscal approval belong to their own issues'],
        'source_urls':[tf['sources']['ACT-04']['url'],ff['source_catalog']['NT007']]
    },ensure_ascii=False,indent=2))

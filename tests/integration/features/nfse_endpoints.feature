# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

@auth
Feature: NFS-e endpoints contract
  In order to avoid regressions in module routing and basic API flows
  As a module maintainer
  I want HTTP-level checks that are lighter than browser E2E

  Background:
    Given I am authenticated in Akaunting as configured user
    And I use company id "1"

  Scenario: Settings page renders the expected NFS-e form fields
    When I send "GET" request to "/<company_id>/nfse/settings"
    Then the response status should be 200
    And the response body should contain "nfse[cnpj_prestador]"
    And the response body should contain "nfse[uf]"
    And the response body should contain "nfse[municipio_nome]"
    And the response body should contain "nfse[municipio_ibge]"

  Scenario: Settings update endpoint accepts valid payload and redirects back
    When I send "PATCH" request to "/<company_id>/nfse/settings" with form data:
      | nfse[cnpj_prestador] | 12345678901234      |
      | nfse[uf] | SP                             |
      | nfse[municipio_nome] | Sao Paulo         |
      | nfse[municipio_ibge] | 3550308             |
      | nfse[item_lista_servico] | 0107            |
      | nfse[aliquota] | 5.00                     |
      | nfse[sandbox_mode] | 1                     |
      | nfse[bao_addr] | https://vault.local.test |
      | nfse[bao_mount] | nfse                    |
      | nfse[bao_token] |                         |
      | nfse[bao_role_id] | role-ci               |
      | nfse[bao_secret_id] |                     |
    Then the response status should be 302
    And the response should redirect to "/<company_id>/nfse/settings"

  Scenario: IBGE lookup endpoints respond with data contract
    When I send "GET" request to "/<company_id>/nfse/ibge/ufs"
    Then the response status should be one of 200,502
    And the response body should contain "data"
    When I send "GET" request to "/<company_id>/nfse/ibge/municipalities/SP"
    Then the response status should be one of 200,502
    And the response body should contain "data"

  Scenario: Certificate upload endpoint handles invalid fixture safely
    When I upload fixture "invalid-cert.p12" to "/<company_id>/nfse/certificate" using password "invalid-password"
    Then the response status should be 302
    And the response should redirect to "/<company_id>/nfse/settings"

  Scenario: Certificate delete endpoint is reachable with method override
    When I send "POST" request to "/<company_id>/nfse/certificate" with form data:
      | _method | DELETE |
    Then the response status should be 302
    And the response should redirect to "/<company_id>/nfse/settings"

  Scenario: Invoice endpoints are reachable and non-existing invoice IDs fail with 404
    When I send "GET" request to "/<company_id>/nfse/invoices"
    Then the response status should be 200
    When I send "GET" request to "/<company_id>/nfse/invoices/999999"
    Then the response status should be 404
    When I send "POST" request to "/<company_id>/nfse/invoices/999999/emit"
    Then the response status should be 404
    When I send "POST" request to "/<company_id>/nfse/invoices/999999" with form data:
      | _method | DELETE |
    Then the response status should be 404

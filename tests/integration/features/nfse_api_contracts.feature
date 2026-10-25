# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

@api
Feature: NFS-e JSON contracts respect authentication, tenant boundaries and fiscal state
  HTTP endpoints used by the Akaunting module must enforce permissions,
  return stable JSON and never leak another company's fiscal profile.
  Every scenario provisions its own session and reads disposable synthetic data.

  Scenario: Anonymous clients cannot enumerate company fiscal profiles
    Given the API client is a guest
    When I make a "GET" JSON request to "/<company_id>/nfse/settings/company-profiles"
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Invalid login credentials cannot access fiscal settings
    When I attempt authentication with an incorrect password
    Then authentication should be rejected
    When I make a "GET" JSON request to "/<company_id>/nfse/settings/company-profiles"
    Then the API response status should be 401

  Scenario: Authorized company administrator can read its fiscal profile in JSON
    Given the "administrator" synthetic Akaunting user is authenticated
    When I make a "GET" JSON request to "/<company_id>/nfse/settings/company-profiles"
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | profiles                   | array   |
      | profiles.0.company_id      | integer |
      | profiles.0.status          | string  |
      | eligible_companies         | array   |
    And the JSON array at "profiles" should contain company "company"
    And the JSON array at "profiles" should contain only company "company"
    And the JSON array at "eligible_companies" should contain only company "company"
    And the JSON response should not include "profiles.0.certificate_ref"

  Scenario: An administrator of another company cannot read the first company's profile
    Given the "other-company-administrator" synthetic Akaunting user is authenticated
    When I make a "GET" JSON request to "/<other_company_id>/nfse/settings/company-profiles"
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON array at "profiles" should contain company "other-company"
    And the JSON array at "profiles" should contain only company "other-company"
    And the JSON array at "eligible_companies" should contain only company "other-company"

  Scenario: A user cannot modify a foreign company profile even with a valid fiscal payload
    Given the "administrator" synthetic Akaunting user is authenticated
    When I make a "PATCH" JSON request to "/<company_id>/nfse/settings/company-profiles/<other_company_id>" with body:
      """
      {"fiscal_parameters":{"sandbox_mode":false}}
      """
    Then the API response status should be 404
    And the API response should be JSON

  Scenario: A synthetic user without fiscal permissions cannot enumerate profiles
    Given the "no-permission" synthetic Akaunting user is authenticated
    When I make a "GET" JSON request to "/<company_id>/nfse/settings/company-profiles"
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: A synthetic user without fiscal permissions cannot change a company profile
    Given the "no-permission" synthetic Akaunting user is authenticated
    When I make a "PATCH" JSON request to "/<company_id>/nfse/settings/company-profiles/<company_id>" with body:
      """
      {"fiscal_parameters":{"sandbox_mode":false}}
      """
    Then the API response status should be 403

  Scenario: API validates required fields on profile creation without touching secrets
    Given the "administrator" synthetic Akaunting user is authenticated
    When I make a "POST" JSON request to "/<company_id>/nfse/settings/company-profiles" with body:
      """
      {"fiscal_parameters":{"uf":"ZZ"}}
      """
    Then the API response status should be 422
    And the API response should be JSON
    And the JSON response should have these field types:
      | message             | string |
      | errors              | object |
      | errors.company_id   | array  |
      | errors.pfx_file     | array  |

  Scenario: Empty fiscal profile update returns a specific structured error
    Given the "administrator" synthetic Akaunting user is authenticated
    When I make a "PATCH" JSON request to "/<company_id>/nfse/settings/company-profiles/<company_id>" with body:
      """
      {}
      """
    Then the API response status should be 422
    And the API response should be JSON
    And the JSON value at "reason" should be "empty_update"

  Scenario: LC116 search respects the requested result bound and JSON schema
    Given the "administrator" synthetic Akaunting user is authenticated
    When I make a "GET" JSON request to "/<company_id>/nfse/lc116/services?limit=2"
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | data | array |
    And the JSON array at "data" should contain at most 2 items

  Scenario: A synthetic unissued invoice has no post-emission side effects
    Given the "administrator" synthetic Akaunting user is authenticated
    When I make a "GET" JSON request to "/<company_id>/nfse/invoices/<pending_invoice_id>/post-emission-status"
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON value at "data.status" should be "idle"
    And the JSON boolean at "data.poll" should be "false"
    And the JSON response should have these field types:
      | data.receipt_id    | null    |
      | data.stages        | object  |
      | data.artifacts.xml.ready | boolean |

  Scenario: Repeated status queries do not change an unissued invoice
    Given the "administrator" synthetic Akaunting user is authenticated
    When I make a "GET" JSON request to "/<company_id>/nfse/invoices/<pending_invoice_id>/post-emission-status"
    Then the JSON value at "data.status" should be "idle"
    When I make a "GET" JSON request to "/<company_id>/nfse/invoices/<pending_invoice_id>/post-emission-status"
    Then the API response status should be 200
    And the JSON value at "data.status" should be "idle"

  Scenario: A user without issuance permission cannot change pending fiscal state
    Given the "no-permission" synthetic Akaunting user is authenticated
    When I make a "POST" JSON request to "/<company_id>/nfse/invoices/<pending_invoice_id>/emit" with body:
      """
      {}
      """
    Then the API response status should be 403
    Given the "administrator" synthetic Akaunting user is authenticated
    When I make a "GET" JSON request to "/<company_id>/nfse/invoices/<pending_invoice_id>/post-emission-status"
    Then the API response status should be 200
    And the JSON value at "data.status" should be "idle"

  Scenario: Emission journal stays a bounded JSON array for an unissued invoice
    Given the "administrator" synthetic Akaunting user is authenticated
    When I make a "GET" JSON request to "/<company_id>/nfse/invoices/<pending_invoice_id>/emission-attempts"
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | data | array |

  Scenario: An unknown invoice cannot be issued
    Given the "administrator" synthetic Akaunting user is authenticated
    When I make a "POST" JSON request to "/<company_id>/nfse/invoices/999999/emit" with body:
      """
      {}
      """
    Then the API response status should be 404

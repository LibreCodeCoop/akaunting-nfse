# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

@api
Feature: Native NFS-e catalog uses Akaunting API authentication and the shared fiscal representation
  The browser remains on its session-protected route and uses the same controller.
  An external client must authenticate independently under Akaunting read-api and company ACL.

  Scenario: Anonymous native API request returns JSON without exposing catalog
    Given the API client is a guest
    When I make a "GET" JSON request to "/<company_id>/api/nfse/national-services?q=0101"
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Authenticated native API client reads fiscal catalog JSON
    When I make a native API GET request to "/<company_id>/api/nfse/national-services?lc116=0101" with synthetic "administrator" credentials
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | data | array |

  Scenario: Native Akaunting item API includes authorized fiscal profile data
    When I make a native API GET request to "/api/items/<valid_item_id>?company_id=<company_id>" with synthetic "administrator" credentials
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON value at "data.nfse_fiscal_profile.codigo_tributacao_nacional" should be "010701"

  Scenario: Native item API persists a valid fiscal profile on creation
    When I make a native API "POST" JSON request to "/api/items?company_id=<company_id>" with synthetic "administrator" credentials and body:
      """
      {"type":"service","name":"Synthetic native API fiscal item","sale_price":"100.00","nfse_item_lista_servico":"0107","nfse_codigo_tributacao_nacional":"010701"}
      """
    Then the API response status should be 201
    And the API response should be JSON
    And the JSON value at "data.nfse_fiscal_profile.codigo_tributacao_nacional" should be "010701"

  Scenario: Native item API update preserves fiscal fields omitted by the request
    When I make a native API "PATCH" JSON request to "/api/items/<valid_item_id>?company_id=<company_id>" with synthetic "administrator" credentials and body:
      """
      {"type":"service","name":"NFSE E2E Fiscal Item","sale_price":"100.00"}
      """
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON value at "data.nfse_fiscal_profile.codigo_tributacao_nacional" should be "010701"

  Scenario: Native item API rejects an invalid national taxation code
    When I make a native API "POST" JSON request to "/api/items?company_id=<company_id>" with synthetic "administrator" credentials and body:
      """
      {"type":"service","name":"Synthetic invalid fiscal item","sale_price":"100.00","nfse_codigo_tributacao_nacional":"999999"}
      """
    Then the API response status should be 422
    And the API response should be JSON

  Scenario: Native API writes cannot switch to another company
    When I make a native API "POST" JSON request to "/api/items?company_id=<other_company_id>" with synthetic "administrator" credentials and body:
      """
      {"type":"service","name":"Synthetic cross-company item","sale_price":"100.00","nfse_codigo_tributacao_nacional":"010701"}
      """
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Native contact API persists a fiscal customer profile
    When I make a native API "POST" JSON request to "/api/contacts?company_id=<company_id>" with synthetic "administrator" credentials and body:
      """
      {"type":"customer","name":"Synthetic native API customer","currency_code":"USD","nfse_legal_name":"Synthetic Customer Ltd","nfse_municipal_registration":"123456"}
      """
    Then the API response status should be 201
    And the API response should be JSON
    And the JSON value at "data.nfse_fiscal_profile.legal_name" should be "Synthetic Customer Ltd"

  Scenario: Native contact API rejects an invalid municipal registration
    When I make a native API "POST" JSON request to "/api/contacts?company_id=<company_id>" with synthetic "administrator" credentials and body:
      """
      {"type":"customer","name":"Synthetic invalid API customer","currency_code":"USD","nfse_municipal_registration":"1234567890123456"}
      """
    Then the API response status should be 422
    And the API response should be JSON

  Scenario: Native item API cannot cross the company boundary
    When I make a native API GET request to "/api/items/<valid_item_id>?company_id=<company_id>" with synthetic "other-company-administrator" credentials
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Native API credential cannot access another company by changing path
    When I make a native API GET request to "/<other_company_id>/api/nfse/national-services?q=0101" with synthetic "administrator" credentials
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Native API rejects a user without fiscal permission
    When I make a native API GET request to "/<company_id>/api/nfse/national-services?q=0101" with synthetic "no-permission" credentials
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Browser session cannot read the fiscal catalog without fiscal permission
    Given the "no-permission" synthetic Akaunting user is authenticated
    When I make a "GET" JSON request to "/<company_id>/nfse/national-services?lc116=0101"
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Browser session endpoint preserves the fiscal JSON contract
    Given the "administrator" synthetic Akaunting user is authenticated
    When I make a "GET" JSON request to "/<company_id>/nfse/national-services?lc116=0101"
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | data | array |

  Scenario: Session cookie alone cannot authenticate against the external API
    Given the "administrator" synthetic Akaunting user is authenticated
    When I make a "GET" JSON request to "/<company_id>/api/nfse/national-services?limit=500"
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: External API can retrieve the bounded national catalog for a native select
    When I make a native API GET request to "/<company_id>/api/nfse/national-services?limit=500" with synthetic "administrator" credentials
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON array at "data" should contain at most 500 items

  Scenario: Browser session can retrieve the same bounded catalog contract
    Given the "administrator" synthetic Akaunting user is authenticated
    When I make a "GET" JSON request to "/<company_id>/nfse/national-services?limit=500"
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON array at "data" should contain at most 500 items

  Scenario: Invalid native API limit is rejected without leaking the catalog
    When I make a native API GET request to "/<company_id>/api/nfse/national-services?limit=501" with synthetic "administrator" credentials
    Then the API response status should be 422
    And the API response should be JSON
    And the JSON array at "data" should contain exactly 0 item

  Scenario: Invalid structured LC 116 input is rejected
    When I make a native API GET request to "/<company_id>/api/nfse/national-services?lc116%5B%5D=0101" with synthetic "administrator" credentials
    Then the API response status should be 422
    And the API response should be JSON

  Scenario: Unrecognized LC 116 codes do not turn into an unfiltered catalog query
    When I make a native API GET request to "/<company_id>/api/nfse/national-services?lc116=foobar" with synthetic "administrator" credentials
    Then the API response status should be 422
    And the API response should be JSON

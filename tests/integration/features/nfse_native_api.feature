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

  Scenario: Native API credential cannot access another company by changing path
    When I make a native API GET request to "/<other_company_id>/api/nfse/national-services?q=0101" with synthetic "administrator" credentials
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Native API rejects a user without fiscal permission
    When I make a native API GET request to "/<company_id>/api/nfse/national-services?q=0101" with synthetic "no-permission" credentials
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Browser session endpoint preserves the fiscal JSON contract
    Given the "administrator" synthetic Akaunting user is authenticated
    When I make a "GET" JSON request to "/<company_id>/nfse/national-services?lc116=0101"
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | data | array |

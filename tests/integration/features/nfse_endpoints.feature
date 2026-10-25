# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

@auth @web
Feature: Authenticated NFS-e administrative pages remain available
  Browser-facing routes are not API contracts. These checks exercise only
  read-only pages and validation rejection; JSON contracts are in @api.

  Background:
    Given I am authenticated in Akaunting as configured user
    And I use company id "1"

  Scenario: Authorized administrator can open fiscal settings
    When I send "GET" request to "/<company_id>/nfse/settings"
    Then the response status should be 200

  Scenario: Authorized administrator can view pending invoice page
    When I send "GET" request to "/<company_id>/nfse/invoices/pending"
    Then the response status should be 200

  Scenario: Unknown invoices remain inaccessible from administrative pages
    When I send "GET" request to "/<company_id>/nfse/invoices/999999"
    Then the response status should be 404

  Scenario: Invalid synthetic certificate is rejected before it can be configured
    When I upload fixture "invalid-cert.p12" to "/<company_id>/nfse/certificate/parse" using password "invalid-password"
    Then the response status should be 422

  Scenario: Removed legacy fiscal service mapping endpoint stays unavailable
    When I send "GET" request to "/<company_id>/nfse/settings?tab=services"
    Then the response status should be 200
    And the response body should not contain "settings/item-services"
    When I send "PATCH" request to "/<company_id>/nfse/settings/item-services" with form data:
      | item_services[0] | 0 |
    Then the response status should be 404

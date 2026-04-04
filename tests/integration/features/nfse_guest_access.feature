# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

Feature: NFS-e guest access guard
  In order to enforce security at middleware level
  As a maintainer
  I want unauthenticated requests redirected to login

  Scenario: Guest cannot access settings endpoint directly
    When I send "GET" request to "/1/nfse/settings"
    Then the response status should be one of 302,303
    And the response should redirect to "/auth/login"

  Scenario: Guest cannot access invoice service preview endpoint directly
    When I send "GET" request to "/1/nfse/invoices/999999/service-preview"
    Then the response status should be one of 302,303
    And the response should redirect to "/auth/login"

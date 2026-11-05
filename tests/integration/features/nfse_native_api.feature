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
    When I make a native API "POST" JSON request to "/api/contacts?company_id=<company_id>&search=type:customer" with synthetic "administrator" credentials and body:
      """
      {"type":"customer","name":"Synthetic native API customer","currency_code":"USD","nfse_legal_name":"Synthetic Customer Ltd","nfse_municipal_registration":"123456"}
      """
    Then the API response status should be 201
    And the API response should be JSON
    And the JSON value at "data.nfse_fiscal_profile.legal_name" should be "Synthetic Customer Ltd"

  Scenario: Native contact API rejects an invalid municipal registration
    When I make a native API "POST" JSON request to "/api/contacts?company_id=<company_id>&search=type:customer" with synthetic "administrator" credentials and body:
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

  Scenario: Native fiscal assessment preview is explicitly read-only
    When I make a native API GET request to "/<company_id>/api/nfse/invoices/<pending_invoice_id>/fiscal-assessment" with synthetic "administrator" credentials
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | data | array |

  Scenario: Native fiscal assessment preview rejects anonymous access
    Given the API client is a guest
    When I make a "GET" JSON request to "/<company_id>/api/nfse/invoices/<pending_invoice_id>/fiscal-assessment"
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Native fiscal assessment preview rejects users without invoice permissions
    When I make a native API GET request to "/<company_id>/api/nfse/invoices/<pending_invoice_id>/fiscal-assessment" with synthetic "no-permission" credentials
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Native fiscal assessment preview cannot be queried across companies
    When I make a native API GET request to "/<company_id>/api/nfse/invoices/<pending_invoice_id>/fiscal-assessment" with synthetic "other-company-administrator" credentials
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Native fiscal event evidence rejects incomplete attestation without mutation
    When I make a native API "POST" JSON request to "/<company_id>/api/nfse/invoices/<pending_invoice_id>/fiscal-event-evidence" with synthetic "administrator" credentials and body:
      """
      {"fiscal_group_key":"synthetic","taxable_event_date":"2026-10-01","source_reference":"Synthetic evidence"}
      """
    Then the API response status should be 422
    And the API response should be JSON

  Scenario: Native fiscal event evidence cannot be written without invoice permission
    When I make a native API "POST" JSON request to "/<company_id>/api/nfse/invoices/<pending_invoice_id>/fiscal-event-evidence" with synthetic "no-permission" credentials and body:
      """
      {"fiscal_group_key":"synthetic","taxable_event_date":"2026-10-01","source_reference":"Synthetic evidence","acknowledge":true}
      """
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Native API client reads bounded fiscal emission attempt history
    When I make a native API GET request to "/<company_id>/api/nfse/invoices/<pending_invoice_id>/emission-attempts" with synthetic "administrator" credentials
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | data | array |
    And the JSON array at "data" should contain at most 100 items

  Scenario: Native API fiscal attempt history rejects missing authentication
    Given the API client is a guest
    When I make a "GET" JSON request to "/<company_id>/api/nfse/invoices/<pending_invoice_id>/emission-attempts"
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Native API fiscal attempt history rejects users without invoice permissions
    When I make a native API GET request to "/<company_id>/api/nfse/invoices/<pending_invoice_id>/emission-attempts" with synthetic "no-permission" credentials
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Native API fiscal attempt history cannot be queried across companies
    When I make a native API GET request to "/<company_id>/api/nfse/invoices/<pending_invoice_id>/emission-attempts" with synthetic "other-company-administrator" credentials
    Then the API response status should be 401
    And the API response should be JSON

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


  Scenario: Native fiscal tax associations list retains a separate read permission
    When I make a native API GET request to "/<company_id>/api/nfse/settings/tax-associations" with synthetic "administrator" credentials
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | data.taxes | array |
      | data.identities | array |
      | data.roles | array |

  Scenario: Native client cannot confirm fiscal tax association without permissions
    When I make a native API "POST" JSON request to "/<company_id>/api/nfse/settings/tax-associations" with synthetic "no-permission" credentials and body:
      """
      {"tax_id":1,"fiscal_identity":"pis","fiscal_role":"provider_due","effective_from":"2026-01-01","source_reference":"synthetic-evidence","acknowledge":true}
      """
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Native tax association refuses a request without explicit review
    When I make a native API "POST" JSON request to "/<company_id>/api/nfse/settings/tax-associations" with synthetic "administrator" credentials and body:
      """
      {"tax_id":1,"fiscal_identity":"pis","fiscal_role":"provider_due","effective_from":"2026-01-01","source_reference":"synthetic-evidence"}
      """
    Then the API response status should be 422
    And the API response should be JSON

  Scenario: Native approximate tax sources expose bounded, company-specific provenance
    When I make a native API GET request to "/<company_id>/api/nfse/settings/approximate-taxes?limit=50" with synthetic "administrator" credentials
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | data | array |
      | meta | object |

  Scenario: Native approximate tax source rejects invalid disclosure evidence
    When I make a native API "POST" JSON request to "/<company_id>/api/nfse/settings/approximate-taxes" with synthetic "administrator" credentials and body:
      """
      {"national_service_code":"010701","simples_option":"1","competence_from":"2026-01-01","source_kind":"documented_zero","source_name":"Synthetic study","source_version":"v1","source_reference":"synthetic-evidence","federal_percent":"1.00","state_percent":"0.00","municipal_percent":"0.00"}
      """
    Then the API response status should be 422
    And the API response should be JSON

  Scenario: Native tax association cannot access a foreign company
    When I make a native API GET request to "/<other_company_id>/api/nfse/settings/tax-associations" with synthetic "administrator" credentials
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Native client reads safe global Vault metadata
    When I make a native API GET request to "/<company_id>/api/nfse/settings/vault" with synthetic "administrator" credentials
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | data | object |
      | data.addr | string |
      | data.auth_mode | string |

  Scenario: Native client reads company fiscal profiles from shared contract
    When I make a native API GET request to "/<company_id>/api/nfse/settings/company-profiles" with synthetic "administrator" credentials
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | profiles | array |
      | eligible_companies | array |

  Scenario: Native fiscal settings API rejects unauthenticated access
    Given the API client is a guest
    When I make a "GET" JSON request to "/<company_id>/api/nfse/settings/vault"
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Native fiscal settings write requires independent permission
    When I make a native API "PATCH" JSON request to "/<company_id>/api/nfse/settings/vault" with synthetic "no-permission" credentials and body:
      """
      {"auth_mode_ui":"token","nfse":{"bao_addr":"https://vault.example.test","bao_mount":"/nfse"}}
      """
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Native fiscal settings cannot be read under a foreign company
    When I make a native API GET request to "/<other_company_id>/api/nfse/settings/company-profiles" with synthetic "administrator" credentials
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Native closing report returns structured fiscal and commercial records
    When I make a native API GET request to "/<company_id>/api/nfse/closing?competence=2026-10" with synthetic "administrator" credentials
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | data.summary | object |
      | data.rows | array |
      | data.commercial_review.rows | array |
      | meta | object |

  Scenario: Native closing rejects unauthenticated access
    Given the API client is a guest
    When I make a "GET" JSON request to "/<company_id>/api/nfse/closing?competence=2026-10"
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Native closing rejects users without invoice read permission
    When I make a native API GET request to "/<company_id>/api/nfse/closing?competence=2026-10" with synthetic "no-permission" credentials
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Native closing rejects malformed competence
    When I make a native API GET request to "/<company_id>/api/nfse/closing?competence=2026-13" with synthetic "administrator" credentials
    Then the API response status should be 422
    And the API response should be JSON

  Scenario: Native closing cannot select another company
    When I make a native API GET request to "/<other_company_id>/api/nfse/closing?competence=2026-10" with synthetic "administrator" credentials
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Native fiscal ledger returns paginated current-company receipt records
    When I make a native API GET request to "/<company_id>/api/nfse/ledger?status=all&limit=25" with synthetic "administrator" credentials
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | data | array |
      | meta | object |

  Scenario: Native fiscal ledger rejects invalid filters
    When I make a native API GET request to "/<company_id>/api/nfse/ledger?status=invalid" with synthetic "administrator" credentials
    Then the API response status should be 422
    And the API response should be JSON

  Scenario: Native fiscal ledger requires invoice read permission
    When I make a native API GET request to "/<company_id>/api/nfse/ledger" with synthetic "no-permission" credentials
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Native fiscal ledger rejects unauthenticated requests
    Given the API client is a guest
    When I make a "GET" JSON request to "/<company_id>/api/nfse/ledger"
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Native fiscal ledger cannot read foreign company receipts
    When I make a native API GET request to "/<other_company_id>/api/nfse/ledger" with synthetic "administrator" credentials
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Native ADN document list requires external authentication
    Given the API client is a guest
    When I make a "GET" JSON request to "/<company_id>/api/nfse/adn/documents"
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Native ADN document list exposes bounded review metadata without raw XML
    When I make a native API GET request to "/<company_id>/api/nfse/adn/documents?limit=50" with synthetic "administrator" credentials
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | data | array |
      | meta | object |
    And the JSON array at "data" should contain at most 50 items

  Scenario: Native ADN document list rejects unauthorized fiscal operators
    When I make a native API GET request to "/<company_id>/api/nfse/adn/documents" with synthetic "no-permission" credentials
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Native ADN document list cannot switch company contexts
    When I make a native API GET request to "/<other_company_id>/api/nfse/adn/documents" with synthetic "administrator" credentials
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Browser ADN document list uses session and JSON instead of Basic credentials
    Given the "administrator" synthetic Akaunting user is authenticated
    When I make a "GET" JSON request to "/<company_id>/nfse/adn/documents"
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | data | array |
      | meta | object |

  Scenario: Native ADN review import requires external authentication
    Given the API client is a guest
    When I make a "POST" JSON request to "/<company_id>/api/nfse/adn/documents/99999999/import"
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Native ADN review ignore rejects unauthorized fiscal operators
    When I make a native API "POST" JSON request to "/<company_id>/api/nfse/adn/documents/99999999/ignore" with synthetic "no-permission" credentials and body:
      """
      {"confirmed":true}
      """
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Native ADN review options are company-scoped JSON
    When I make a native API GET request to "/<company_id>/api/nfse/adn/review-options" with synthetic "administrator" credentials
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | data.vendors | array |
      | data.categories | array |
      | data.items | array |

  Scenario: Native ADN review options reject unauthorized clients
    When I make a native API GET request to "/<company_id>/api/nfse/adn/review-options" with synthetic "no-permission" credentials
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Browser ADN review list accepts optional accounting previews
    Given the "administrator" synthetic Akaunting user is authenticated
    When I make a "GET" JSON request to "/<company_id>/nfse/adn/documents?review_status=pending&include_preview=1"
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | data | array |
      | meta | object |


  Scenario: Native ADN review history requires external authentication
    Given the API client is a guest
    When I make a "GET" JSON request to "/<company_id>/api/nfse/adn/documents/99999999/review-history"
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Native ADN review history rejects users without bill permission
    When I make a native API GET request to "/<company_id>/api/nfse/adn/documents/99999999/review-history" with synthetic "no-permission" credentials
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Native bulk API lists scoped emission runs without issuing NFS-e
    When I make a native API GET request to "/<company_id>/api/nfse/bulk/runs" with synthetic "administrator" credentials
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | data | array |
      | meta | object |

  Scenario: Native bulk plan rejects invalid selections before creating a run
    When I make a native API "POST" JSON request to "/<company_id>/api/nfse/bulk/plan" with synthetic "administrator" credentials and body:
      """
      {"invoice_ids":[]}
      """
    Then the API response status should be 422
    And the API response should be JSON

  Scenario: Native bulk dispatch requires fiscal write permission
    When I make a native API "POST" JSON request to "/<company_id>/api/nfse/bulk/dispatch" with synthetic "no-permission" credentials and body:
      """
      {"invoice_ids":[1]}
      """
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Native bulk API refuses a foreign company context
    When I make a native API GET request to "/<other_company_id>/api/nfse/bulk/runs" with synthetic "administrator" credentials
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Browser bulk progress and native API use equivalent JSON resources
    Given the "administrator" synthetic Akaunting user is authenticated
    When I make a "GET" JSON request to "/<company_id>/nfse/bulk/runs"
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | data | array |
      | meta | object |

  Scenario: Native NFS-e issuance preview shares the browser fiscal representation
    When I make a native API GET request to "/<company_id>/api/nfse/invoices/<pending_invoice_id>/service-preview" with synthetic "administrator" credentials
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | fiscal_groups | array |
      | fiscal_profile_validation | object |
      | email_defaults | object |

  Scenario: Native NFS-e receipt processing status is read-only for a pending invoice
    When I make a native API GET request to "/<company_id>/api/nfse/invoices/<pending_invoice_id>/post-emission-status" with synthetic "administrator" credentials
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON value at "data.status" should be "idle"
    And the JSON boolean at "data.poll" should be "false"

  Scenario: Native fiscal preview rejects a client without invoice read permission
    When I make a native API GET request to "/<company_id>/api/nfse/invoices/<pending_invoice_id>/service-preview" with synthetic "no-permission" credentials
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Native fiscal processing status cannot cross tenant boundaries
    When I make a native API GET request to "/<other_company_id>/api/nfse/invoices/<pending_invoice_id>/post-emission-status" with synthetic "administrator" credentials
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Native API fiscal issuance rejects an unauthenticated write
    Given the API client is a guest
    When I make a "POST" JSON request to "/<company_id>/api/nfse/invoices/<ready_invoice_id>/emit" with body:
      """
      {}
      """
    Then the API response status should be 401
    And the API response should be JSON

  Scenario: Native API fiscal issuance requires sales-invoice write permission
    When I make a native API "POST" JSON request to "/<company_id>/api/nfse/invoices/<ready_invoice_id>/emit" with synthetic "no-permission" credentials and body:
      """
      {"nfse_force_ajax":true}
      """
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Native API uses the same idempotent SEFIN-fake invoice issuance operation as the Web UI
    When I make a native API "POST" JSON request to "/<company_id>/api/nfse/invoices/<ready_invoice_id>/emit" with synthetic "administrator" credentials and body:
      """
      {"nfse_force_ajax":true}
      """
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON boolean at "success" should be "true"
    And the JSON response should have these field types:
      | data.invoice_id | integer |
      | data.receipt_id | integer |
      | links.status | string |
      | links.attempts | string |
    When I make a native API "POST" JSON request to "/<company_id>/api/nfse/invoices/<ready_invoice_id>/emit" with synthetic "administrator" credentials and body:
      """
      {"nfse_force_ajax":true}
      """
    Then the API response status should be 200
    And the JSON boolean at "success" should be "true"
    When I make a native API GET request to "/<company_id>/api/nfse/invoices/<ready_invoice_id>/emission-attempts" with synthetic "administrator" credentials
    Then the API response status should be 200
    And the JSON array at "data" should contain exactly 1 item

  Scenario: Native fiscal cancellation is not available to a user without write permission
    When I make a native API "DELETE" JSON request to "/<company_id>/api/nfse/invoices/<ready_invoice_id>" with synthetic "no-permission" credentials and body:
      """
      {"nfse_receipt_id":99999999,"cancel_reason":"9","cancel_justification":"Cancelamento sintetico autorizado pelo operador"}
      """
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Native cancellation refuses a missing or outdated receipt without transmission
    When I make a native API "DELETE" JSON request to "/<company_id>/api/nfse/invoices/<ready_invoice_id>" with synthetic "administrator" credentials and body:
      """
      {"nfse_receipt_id":99999999,"cancel_reason":"9","cancel_justification":"Cancelamento sintetico autorizado pelo operador"}
      """
    Then the API response status should be 409
    And the API response should be JSON
    And the JSON value at "status" should be "not_confirmed"

  Scenario: Native reemission refuses an outdated receipt before any new DPS reservation
    When I make a native API "POST" JSON request to "/<company_id>/api/nfse/invoices/<ready_invoice_id>/reemit" with synthetic "administrator" credentials and body:
      """
      {"nfse_receipt_id":99999999}
      """
    Then the API response status should be 409
    And the API response should be JSON

  Scenario: Native substitution requires the current authorized fiscal receipt
    When I make a native API "POST" JSON request to "/<company_id>/api/nfse/invoices/<ready_invoice_id>/substitute" with synthetic "administrator" credentials and body:
      """
      {"nfse_substitution_receipt_id":99999999,"nfse_substitution_reason":"01","nfse_substitution_description":"Substituicao sintetica da nota fiscal emitida"}
      """
    Then the API response status should be 409
    And the API response should be JSON

  Scenario: Native synchronization refuses a non-current fiscal receipt
    When I make a native API "POST" JSON request to "/<company_id>/api/nfse/invoices/<ready_invoice_id>/refresh" with synthetic "administrator" credentials and body:
      """
      {"nfse_receipt_id":99999999}
      """
    Then the API response status should be 409
    And the API response should be JSON

  Scenario: Native WebDAV artifact configuration exposes only safe metadata
    When I make a native API GET request to "/<company_id>/api/nfse/settings/artifacts" with synthetic "administrator" credentials
    Then the API response status should be 200
    And the API response should be JSON
    And the JSON response should have these field types:
      | data | object |
      | data.configured | boolean |
      | data.username_configured | boolean |
      | data.password_configured | boolean |
      | data.store_xml | boolean |
      | data.store_pdf | boolean |

  Scenario: Native WebDAV artifact configuration denies read without permission
    When I make a native API GET request to "/<company_id>/api/nfse/settings/artifacts" with synthetic "no-permission" credentials
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Native WebDAV artifact configuration denies writes without permission
    When I make a native API "PATCH" JSON request to "/<company_id>/api/nfse/settings/artifacts" with synthetic "no-permission" credentials and body:
      """
      {"nfse":{"webdav_store_xml":false}}
      """
    Then the API response status should be 403
    And the API response should be JSON

  Scenario: Native WebDAV artifact configuration cannot read another company
    When I make a native API GET request to "/<other_company_id>/api/nfse/settings/artifacts" with synthetic "administrator" credentials
    Then the API response status should be 401
    And the API response should be JSON

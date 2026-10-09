<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# NFS-e fiscal fields on native Akaunting customers

The Akaunting `contacts` model, original table, and core source are unchanged.

Native customer creation/edit now exposes **municipal registration (IM)** and
**legal name for fiscal issuance**. Both are optional and must be verified
against official records; do not copy a historic PDF or guess values.

The module stores these fields in `nfse_contact_fiscal_profiles`, uniquely by
`(company_id, contact_id)`, and resolves them only for NFS-e payloads.
The commercial customer name is unaffected.

Events ContactCreating/ContactUpdating stage fiscal form values and Eloquent
Contact::saving/saved hooks validate company/transaction and persist the
extension inside the native transaction. Other operations without NFS-e
fields do not mutate an existing profile.

When editing core contact address fields, prefer complete street, number,
complement, district, city/state and ZIP. Existing parsing fallback remains
in NationalTakerAddressParser, not in the DANFSe renderer.

Changes to the customer affect only FUTURE DPS. Existing authorized XML is
immutable. Review this module's address view override if the Akaunting
native component evolves.

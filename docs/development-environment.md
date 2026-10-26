<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# NFS-e development environment

The NFS-e module can be developed against any supported Akaunting runtime.
[LibreCode's generic Docker environment](https://github.com/LibreCodeCoop/akaunting-docker)
is one option, not a runtime requirement. Reuse the existing shared local
instance where one is already running; do not create a competing environment
or reset shared data.

For a development instance, confirm the installed Akaunting Core version,
the NFS-e module revision, the `nfse-php` scoped library reference and
Composer lock before testing. Run PHP, Artisan and dependency commands using
the chosen runtime's documented process, not an assumed host PHP binary.
The module's scoped runtime is rebuilt with `composer thirdparty:build:prod`
**only when its dependency pin or build requires it**.

Use the module's Behat/API and PHPUnit integration suites to verify the
current implementation. Run them against the intended development environment
according to the CI and testing documentation. Do not run fiscal issuance
against a real taxpayer during a smoke test.

Existing local `.env` and Compose overrides, volumes, certificates and
secrets are not replaceable templates. Review config before modifying any
shared state. Keep local runtime data under the owning project's ignored `volumes/` directory. Do not commit machine-specific
addresses, credentials or real fiscal/customer data to this module.

For an optional secret store see [OpenBao deployment](openbao-deployment.md).
For global infrastructure settings and company profiles see
[Module configuration](operacao-e-configuracao.md).

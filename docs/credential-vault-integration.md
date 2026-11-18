<!-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
     SPDX-License-Identifier: AGPL-3.0-or-later -->

# Shared CredentialVault integration

The NFS-e module can reuse the CredentialVault module to access OpenBao
without losing existing company-specific fiscal certificate revisions.

## Two independent changes

1. Transport (opt-in): NFSE_USE_SHARED_CREDENTIAL_VAULT=yes selects
   the neutral KV v2 client. By default it still reads the existing encrypted
   nfse_vault_infrastructure configuration and identical Vault paths.
2. Configuration (separate opt-in): NFSE_SHARED_VAULT_PROFILE=yes reads
   the shared encrypted profile named "nfse" in
   lcc_credential_vault_profiles, only after a verified explicit import.

Default behavior when both switches are absent is unchanged.
If an explicitly enabled shared profile or module is missing, NFS-e fails
closed. No migration takes place automatically on startup.

## Controlled migration

Install the CredentialVault module beside NFS-e, without altering Akaunting
core. Back up the database, then run the standard module migrations.

First inspect the legacy fiscal configuration with no changes:

    php artisan credential-vault:import-nfse-config

After confirming the dry-run, explicitly authorize the ciphertext copy:

    CREDENTIAL_VAULT_ALLOW_NFSE_IMPORT=yes \
      php artisan credential-vault:import-nfse-config --apply

The command copies the existing encrypted payload byte-for-byte. The
original nfse_vault_infrastructure row remains intact for rollback and no
OpenBao KV request is made. Repeated identical imports are safe; divergent
profiles are rejected.

Enable the two switches separately in staging. Verify read-only company
certificate checks, permissions and normal fiscal configuration loading.
Do not issue actual NFS-e merely to test Vault wiring. Enable production
only after validating the configured mount and authorized role.

## Rollback

Unset NFSE_SHARED_VAULT_PROFILE to return to the original encrypted fiscal
configuration. Unset NFSE_USE_SHARED_CREDENTIAL_VAULT to return to the
existing nfse-php OpenBao implementation. Neither action deletes the
existing encrypted record or changes Vault secrets. Clear the application
configuration cache and restart PHP/queue processes as appropriate.

## What remains in local storage

The current NFS-e design stores PFX passwords and PFX private-file paths
in OpenBao, while the PFX binary itself remains in a protected local
filesystem. Moving the binary into KV v2 requires a separate verified
certificate materialization and migration procedure, backup/restore and
recovery, and must not silently delete existing files.

OpenBao is shared infrastructure. Separate KV mounts (including the
legacy /nfse mount) are beneficial security boundaries, not duplicate
servers. A bank AppRole must not read fiscal certificate secrets.

## Automated checks

Run the standalone NFS-e adapter contract with the shared module available
under CREDENTIAL_VAULT_TEST_ROOT. It tests opt-in/rollback, missing-profile
fail-closed behavior, and identical legacy secret paths with synthetic data.
The shared integration test runs against a disposable OpenBao 2.7.1
instance with separate fiscal/bank AppRoles.

The shared implementation is resolved through the Laravel container's
VaultClientFactory, with purpose-specific OpenBao credentials. No NFS-e
client embeds its own copy of the OpenBao transport logic after opt-in.

The additional RealScopedVaultCompatibility test exercises the adapter
against the actual pinned/scoped nfse-php interface, not a test stub.

This is not a substitute for the complete NFS-e test suite and authorized
deployment verification.

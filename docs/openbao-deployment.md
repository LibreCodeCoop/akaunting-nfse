<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Optional OpenBao deployment for NFS-e

The NFS-e module uses a KV v2 secret store for certificate passwords. **OpenBao
and HashiCorp Vault are alternatives**, not dependencies of Akaunting itself.
An existing Vault/OpenBao service should be reused where feasible; do not
deploy another server just to install the module.

This directory contains an **optional standalone OpenBao example**. It does
not install Akaunting, does not modify the application's Compose project and
does not expose OpenBao on a public host port. Consult
[Module configuration](operacao-e-configuracao.md) for the application's
current global infrastructure settings, access control and company profiles.

## New OpenBao instance only

The sample is in `deploy/openbao/`:

- `compose.yml`: OpenBao 2.7.1, isolated Docker network, persistent PebbleDB,
  static-key auto-unseal, security restrictions and health check.
- `openbao.hcl`: listener, storage and seal configuration.
- `scripts/bootstrap-openbao.sh`: one-time initialization and NFS-e-scoped
  KV v2/AppRole bootstrap. It operates **only** on this standalone example.

The example uses the same `volumes/` convention as LibreCode's other
Docker projects. Persistent OpenBao state is stored under
`volumes/openbao/data/` and the static seal key under
`volumes/openbao/seal.key`. The entire `volumes/` directory is
ignored by Git.

For a **new, dedicated installation only**, from the repository root:

```sh
sudo bash scripts/bootstrap-openbao.sh
docker compose -f deploy/openbao/compose.yml ps
docker compose -f deploy/openbao/compose.yml exec -T openbao bao status
```

The helper requires an interactive terminal and host privileges to prepare
storage for UID 100. It refuses to initialize existing state. If an
installation already uses a shared OpenBao/Vault service, configure the
module to use that service instead of starting another instance.

By default, OpenBao is accessible only on the internal Docker network
`nfse-openbao`. Give the Akaunting/PHP runtime access only to that
network through the deployment's own private Compose override. Its
application-facing address is `http://openbao:8200`; for an external
Vault/OpenBao deployment use its secured HTTPS endpoint instead.

The standalone example intentionally has **no Akaunting/PHP service**, no
database or credentials from the host application, and no automatic changes
to the module's settings. Configure the admin-managed NFS-e infrastructure
using the Role ID and Secret ID returned by the bootstrap; consult the
module's current settings documentation for the correct UI/API. Never
paste these secrets into a command line, Git commit, issue or CI log.

Static Key Auto Unseal trusts possession of the static key. Treat the host
and backups as highly privileged. Keep a separately protected recovery copy
of the seal key, **separate** from a backup of PebbleDB data, and secure
the recovery key and initial administrative token outside that host.
A host breach exposing both key and storage can compromise the secrets.
Do not use the example's HTTP listener outside its isolated network.

If bootstrap fails, inspect the existing state rather than rerunning it. Never
reinitialize existing OpenBao storage or regenerate its seal key.

## Scope and recovery

The generic [Akaunting Docker runtime](https://github.com/LibreCodeCoop/akaunting-docker)
owns the application stack. This example is optional module infrastructure.
Keep persistent state under `volumes/` (ignored by Git), never in
tracked source files. The helper refuses to initialize an existing state
directory. Use an established OpenBao backup and recovery procedure to restore
existing storage and its corresponding seal key.

Do not use fiscal issuance as a deployment smoke test.

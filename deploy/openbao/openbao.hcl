# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Example for the optional NFS-e OpenBao integration.
ui = false
api_addr = "http://openbao:8200"

listener "tcp" {
  address     = "0.0.0.0:8200"
  tls_disable = true
}

storage "pebbledb" {
  path = "/openbao/file/pebbledb"
}

# The trusted Docker host/root account is the local source of trust for
# auto-unseal. Keep this key outside the OpenBao data directory.
seal "static" {
  current_key_id = "nfse-static-v1"
  current_key    = "file:///run/secrets/openbao_static_seal_key"
}

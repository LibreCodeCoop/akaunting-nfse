#!/bin/sh
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Validates the optional fixture without starting services or touching secrets.
set -eu
root=$(cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$root"

bash -n scripts/bootstrap-openbao.sh

# Reject invalid policy identifiers before contacting a Docker daemon.
if NFSE_ROLE='invalid/role' bash scripts/bootstrap-openbao.sh >/dev/null 2>&1; then
    echo "Bootstrap must reject invalid role identifiers." >&2
    exit 1
fi
test -s deploy/openbao/openbao.hcl
test -s deploy/openbao/compose.yml

docker compose -f deploy/openbao/compose.yml config --quiet

docker compose -f deploy/openbao/compose.yml config --format json |
    python3 -c '
import json,sys
config=json.load(sys.stdin)
assert list(config["services"])==["openbao"], "Akaunting must not be part of the example"
svc=config["services"]["openbao"]
assert "ports" not in svc, "OpenBao must not publish a host port"
assert svc["read_only"] is True
assert any(v["target"]=="/openbao/file" and "/volumes/openbao/data" in v["source"] for v in svc["volumes"])
assert any(v["target"]=="/openbao/config/openbao.hcl" and v["read_only"] for v in svc["volumes"])
assert config["networks"]["openbao-backend"]["internal"] is True
assert any(v["source"]=="openbao_static_seal_key" for v in svc["secrets"])
assert "/volumes/openbao/seal.key" in config["secrets"]["openbao_static_seal_key"]["file"]
'
if bash scripts/bootstrap-openbao.sh >/dev/null 2>&1; then
    echo "Bootstrap must refuse noninteractive execution." >&2
    exit 1
fi
echo "NFS-e optional deployment fixture PASS"

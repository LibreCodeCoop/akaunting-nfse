#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail
# This optional helper belongs to the NFS-e integration example, not to the
# Akaunting host. Run it only for a NEW dedicated OpenBao instance.
script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
module_dir="$(cd -- "$script_dir/.." && pwd)"
cd -- "$module_dir/deploy/openbao"

# Persistent runtime data stays under the standard, Git-ignored volumes/
# directory at the module root. This helper is only for a NEW installation.
OPENBAO_STATE_DIR="$module_dir/volumes/openbao"
OPENBAO_SERVICE=openbao
OPENBAO_UID=100
OPENBAO_GID=100
OPENBAO_DATA_DIR="$OPENBAO_STATE_DIR/data"
OPENBAO_STATIC_SEAL_KEY_FILE="$OPENBAO_STATE_DIR/seal.key"
OPENBAO_CONFIG_FILE=./openbao.hcl
if [[ ! -t 1 ]]; then
    echo "Bootstrap requires an interactive terminal for recovery material." >&2
    exit 2
fi
NFSE_MOUNT="${NFSE_MOUNT:-nfse}"
NFSE_POLICY="${NFSE_POLICY:-nfse}"
NFSE_ROLE="${NFSE_ROLE:-nfse}"

# Names become Vault mount, policy and AppRole identifiers. Reject unexpected
# characters before creating directories or starting a privileged container.
for value in "$NFSE_MOUNT" "$NFSE_POLICY" "$NFSE_ROLE"; do
    if [[ ! "$value" =~ ^[a-z][a-z0-9-]*$ ]]; then
        echo "Invalid NFS-e OpenBao identifier: expected [a-z][a-z0-9-]*." >&2
        exit 2
    fi
done

compose() {
    docker compose -f compose.yml "$@"
}

wait_for_status() {
    local attempts=30
    local output=""

    for ((i = 1; i <= attempts; i++)); do
        output="$(compose exec -T "${OPENBAO_SERVICE}" bao status 2>&1 || true)"
        if grep -q "Initialized" <<<"${output}"; then
            printf '%s\n' "${output}"
            return 0
        fi
        sleep 1
    done

    echo "OpenBao did not become reachable." >&2
    compose logs --tail=100 "${OPENBAO_SERVICE}" >&2 || true
    return 1
}

# Supply the admin token on stdin, never as a docker CLI argument.
bao_admin() {
    printf '%s\n' "$root_token" |
        compose exec -T "$OPENBAO_SERVICE" sh -c             'IFS= read -r BAO_TOKEN; export BAO_TOKEN; exec bao "$@"' sh "$@"
}

if [ ! -f "${OPENBAO_CONFIG_FILE}" ]; then
    echo "OpenBao config is missing or is not a regular file: ${OPENBAO_CONFIG_FILE}" >&2
    exit 1
fi

if ! grep -q 'storage "pebbledb"' "${OPENBAO_CONFIG_FILE}"; then
    echo "OpenBao config does not declare the expected PebbleDB storage backend." >&2
    exit 1
fi

# This helper is exclusively for a NEW installation. Never alter or
# reinitialize preexisting data, even if the seal key is missing.
if [[ -e "$OPENBAO_DATA_DIR" || -e "$OPENBAO_STATIC_SEAL_KEY_FILE" ]]; then
    echo "Existing OpenBao state detected. Bootstrap is for a new installation only." >&2
    exit 1
fi
install -d -m 0700 "$OPENBAO_STATE_DIR"
install -d -m 0700 -o "$OPENBAO_UID" -g "$OPENBAO_GID" "$OPENBAO_DATA_DIR"
(
    umask 077
    openssl rand -out "$OPENBAO_STATIC_SEAL_KEY_FILE" 32
)
chmod 0400 "$OPENBAO_STATIC_SEAL_KEY_FILE"
echo "Created seal key at a protected runtime location."

compose config --quiet
compose up -d "${OPENBAO_SERVICE}"

status_output="$(wait_for_status)"
printf '%s\n' "${status_output}"

root_token="${BAO_ROOT_TOKEN:-}"

if grep -Eq 'Initialized[[:space:]]+false' <<<"${status_output}"; then
    echo
    echo "Initializing OpenBao exactly once..."
    init_output="$(compose exec -T "${OPENBAO_SERVICE}" bao operator init -recovery-shares=1 -recovery-threshold=1)"
    # Show the recovery material once on an interactive terminal. It must be
    # stored outside the host; never redirect this output to a CI log.
    printf '%s\n' "${init_output}"

    root_token="$(sed -n 's/^Initial Root Token:[[:space:]]*//p' <<<"${init_output}")"

    if [ -z "${root_token}" ]; then
        echo "Could not extract the initial root token from initialization output." >&2
        exit 1
    fi

    echo
    echo "Store the recovery key and initial root token outside this VPS."
    echo "Keep the seal key and data backups in separately protected locations."
elif [ -z "${root_token}" ]; then
    read -r -s -p "OpenBao root token for bootstrap: " root_token
    echo
fi

if [ -z "${root_token}" ]; then
    echo "A root token is required to configure the initial NFSe policy and AppRole." >&2
    exit 1
fi

bao_admin token lookup >/dev/null

if ! bao_admin secrets list -format=json | grep -Fq "\"$NFSE_MOUNT/\""; then
    bao_admin secrets enable -path="${NFSE_MOUNT}" kv-v2
else
    echo "KV v2 mount already enabled at ${NFSE_MOUNT}/"
fi

if ! bao_admin auth list -format=json | grep -Fq '"approle/"'; then
    bao_admin auth enable approle
else
    echo "AppRole auth method already enabled."
fi

# The single-quoted command is evaluated by the shell inside OpenBao.
# shellcheck disable=SC2016
{
    printf '%s\n' "$root_token"
    printf 'path "%s/data/*" {\n  capabilities = ["create", "read", "update", "delete"]\n}\n' "$NFSE_MOUNT"
    printf 'path "%s/metadata/*" {\n  capabilities = ["read", "list"]\n}\n' "$NFSE_MOUNT"
} | compose exec -T "$OPENBAO_SERVICE" sh -c     'IFS= read -r BAO_TOKEN; export BAO_TOKEN; exec bao policy write "$1" -' sh "$NFSE_POLICY"

bao_admin write "auth/approle/role/${NFSE_ROLE}" token_policies="${NFSE_POLICY}" token_ttl=1h token_max_ttl=4h >/dev/null

role_id="$(bao_admin read -field=role_id "auth/approle/role/${NFSE_ROLE}/role-id")"
secret_id="$(bao_admin write -field=secret_id -f "auth/approle/role/${NFSE_ROLE}/secret-id")"

echo
echo "NFS-e AppRole credentials (sensitive; do not log or commit)"
echo "Role ID:   ${role_id}"
echo "Secret ID: ${secret_id}"
echo
echo "Configure Akaunting NFS-e with:"
echo "  Address:   http://openbao:8200"
echo "  KV mount:  ${NFSE_MOUNT}"
echo "  Token:     leave empty"
echo "  Role ID:   value printed above"
echo "  Secret ID: value printed above"

unset root_token
unset BAO_ROOT_TOKEN 2>/dev/null || true

echo
echo "Restarting OpenBao to verify Static Key Auto Unseal..."
compose restart "${OPENBAO_SERVICE}" >/dev/null

status_output="$(wait_for_status)"
printf '%s\n' "${status_output}"

if ! grep -Eq 'Initialized[[:space:]]+true' <<<"${status_output}" ||
   ! grep -Eq 'Sealed[[:space:]]+false' <<<"${status_output}"; then
    echo "OpenBao did not return initialized and unsealed after restart." >&2
    exit 1
fi

echo
echo "OpenBao bootstrap completed successfully."

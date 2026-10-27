#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail

# Bootstrap a NEW, dedicated OpenBao instance. Never operate on existing state.
script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
module_dir="$(cd -- "$script_dir/.." && pwd)"
state_dir="$module_dir/volumes/openbao"
data_dir="$state_dir/data"
seal_key="$state_dir/seal.key"
config_dir="$module_dir/deploy/openbao"
mount="${NFSE_MOUNT:-nfse}"
policy="${NFSE_POLICY:-nfse}"
role="${NFSE_ROLE:-nfse}"

compose() {
    docker compose -f "$config_dir/compose.yml" "$@"
}

# The admin token is passed on stdin, never in Docker arguments or environment.
bao_exec() {
    compose exec -T openbao sh -c \
        'IFS= read -r BAO_TOKEN || exit 1; export BAO_TOKEN; exec bao "$@"' sh "$@"
}

bao_admin() {
    printf '%s\n' "$root_token" | bao_exec "$@"
}

validate_input() {
    if [[ ! -t 0 || ! -t 1 ]]; then
        echo "Bootstrap requires an interactive terminal for recovery material." >&2
        return 2
    fi
    local name
    for name in "$mount" "$policy" "$role"; do
        if [[ ! "$name" =~ ^[a-z][a-z0-9-]*$ ]]; then
            echo "Invalid OpenBao identifier: expected [a-z][a-z0-9-]*." >&2
            return 2
        fi
    done
    if [[ ! -f "$config_dir/openbao.hcl" ]] ||
       ! grep -q 'storage "pebbledb"' "$config_dir/openbao.hcl"; then
        echo "Expected OpenBao PebbleDB configuration is missing." >&2
        return 1
    fi
    if [[ -L "$module_dir/volumes" ]]; then
        echo "Refusing a symlinked volumes directory." >&2
        return 1
    fi
}

create_state() {
    mkdir -p "$module_dir/volumes"
    # Atomic mkdir: concurrent runs and partially initialized installations
    # must never overwrite the original seal key or persistent storage.
    if ! mkdir -m 0700 "$state_dir" 2>/dev/null; then
        echo "OpenBao state already exists; refusing to initialize it." >&2
        return 1
    fi
    install -d -m 0700 -o 100 -g 100 "$data_dir"
    (umask 077; openssl rand -out "$seal_key" 32)
    # File-backed Compose secrets retain host ownership. OpenBao runs as UID 100.
    chown 100:100 "$seal_key"
    chmod 0400 "$seal_key"
}

wait_for_status() {
    local output attempt
    for ((attempt = 0; attempt < 30; attempt++)); do
        output="$(compose exec -T openbao bao status 2>&1)" || :
        if grep -Eq '^Initialized[[:space:]]+(true|false)' <<<"$output"; then
            printf '%s\n' "$output"
            return 0
        fi
        sleep 1
    done
    echo "OpenBao did not become reachable." >&2
    compose logs --tail=100 openbao >&2 || :
    return 1
}

initialize() {
    local status_output init_output
    status_output="$(wait_for_status)"
    if ! grep -Eq '^Initialized[[:space:]]+false' <<<"$status_output"; then
        echo "OpenBao is already initialized; refusing to bootstrap." >&2
        return 1
    fi

    init_output="$(compose exec -T openbao bao operator init -recovery-shares=1 -recovery-threshold=1)"
    root_token="$(sed -n 's/^Initial Root Token:[[:space:]]*//p' <<<"$init_output")"
    if [[ -z "$root_token" ]]; then
        echo "OpenBao initialization did not return an administrative token." >&2
        return 1
    fi
    printf '%s\n' "$init_output"
    unset init_output
    echo "Store the recovery key and initial root token outside this host."
    echo "Back up the seal key and PebbleDB storage separately."
}

write_policy() {
    # KV v2 access is restricted to the module's pfx/ prefix, including
    # its synthetic capability probe. No access to unrelated KV paths.
    {
        printf '%s\n' "$root_token"
        printf 'path "%s/data/pfx/*" {\n  capabilities = ["create", "read", "update", "delete"]\n}\n' "$mount"
        printf 'path "%s/metadata/pfx/*" {\n  capabilities = ["read", "list"]\n}\n' "$mount"
    } | bao_exec policy write "$policy" -
}

configure_access() {
    local role_id secret_id
    bao_admin token lookup >/dev/null
    bao_admin secrets enable -path="$mount" kv-v2
    bao_admin auth enable approle
    write_policy
    bao_admin write "auth/approle/role/$role" \
        token_policies="$policy" token_ttl=1h token_max_ttl=4h >/dev/null

    role_id="$(bao_admin read -field=role_id "auth/approle/role/$role/role-id")"
    secret_id="$(bao_admin write -field=secret_id -f "auth/approle/role/$role/secret-id")"
    printf '\nNFS-e AppRole credentials (sensitive; do not log or commit)\n'
    printf 'Role ID:   %s\nSecret ID: %s\n' "$role_id" "$secret_id"
    printf 'Internal address: http://openbao:8200\nKV mount: %s\n' "$mount"
    echo "The module's administrative diagnostic requires HTTPS; configure a"
    echo "trusted TLS endpoint before using these credentials in that interface."
    echo "Leave the token field empty and configure the Role ID / Secret ID."
}

verify_restart() {
    local status_output
    compose restart openbao >/dev/null
    status_output="$(wait_for_status)"
    if ! grep -Eq '^Initialized[[:space:]]+true' <<<"$status_output" ||
       ! grep -Eq '^Sealed[[:space:]]+false' <<<"$status_output"; then
        echo "OpenBao did not auto-unseal after restart." >&2
        return 1
    fi
}

main() {
    local existing_containers
    validate_input
    compose config --quiet
    # Never replace a container already owned by this Compose project.
    if ! existing_containers="$(compose ps -a -q)"; then
        echo "Unable to inspect the OpenBao Compose project; refusing to continue." >&2
        return 1
    fi
    if [[ -n "$existing_containers" ]]; then
        echo "OpenBao Compose project already has containers; refusing to replace them." >&2
        return 1
    fi
    create_state
    compose up -d openbao
    initialize
    configure_access
    unset root_token
    verify_restart
    echo "OpenBao bootstrap completed successfully."
}

main "$@"

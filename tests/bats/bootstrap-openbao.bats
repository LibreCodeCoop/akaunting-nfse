#!/usr/bin/env bats
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

setup() {
    local repo
    repo="$(cd -- "$BATS_TEST_DIRNAME/../.." && pwd)"
    project="$BATS_TEST_TMPDIR/project"
    export MOCK_BAO_STATE="$BATS_TEST_TMPDIR/bao-state"
    export MOCK_DOCKER_LOG="$BATS_TEST_TMPDIR/docker.log"
    mkdir -p "$project/scripts" "$project/deploy/openbao" "$MOCK_BAO_STATE" "$BATS_TEST_TMPDIR/bin"
    cp "$repo/scripts/bootstrap-openbao.sh" "$project/scripts/"
    cp "$repo/deploy/openbao/compose.yml" "$repo/deploy/openbao/openbao.hcl" "$project/deploy/openbao/"
    cp "$BATS_TEST_DIRNAME/fixtures/docker" "$BATS_TEST_TMPDIR/bin/docker"
    chmod +x "$BATS_TEST_TMPDIR/bin/docker"
    : > "$MOCK_DOCKER_LOG"

    # Rootless test double for install -d -o 100 -g 100.
    cat > "$BATS_TEST_TMPDIR/bin/install" <<'SH'
#!/usr/bin/env bash
printf 'install %s\n' "$*" >> "$MOCK_DOCKER_LOG"
mkdir -m 0700 "${@: -1}"
SH
    cat > "$BATS_TEST_TMPDIR/bin/sleep" <<'SH'
#!/usr/bin/env bash
exit 0
SH
    cat > "$BATS_TEST_TMPDIR/bin/chown" <<'SH'
#!/usr/bin/env bash
printf 'chown %s\n' "$*" >> "$MOCK_DOCKER_LOG"
SH
    chmod +x "$BATS_TEST_TMPDIR/bin/install" "$BATS_TEST_TMPDIR/bin/sleep" "$BATS_TEST_TMPDIR/bin/chown"
    export PATH="$BATS_TEST_TMPDIR/bin:$PATH"
}

run_bootstrap() {
    # util-linux script supplies a PTY without requiring a privileged host.
    run script -q -e -c "bash '$project/scripts/bootstrap-openbao.sh'" /dev/null
}

@test "refuses a noninteractive session before touching storage or Docker" {
    run bash "$project/scripts/bootstrap-openbao.sh"
    [ "$status" -eq 2 ]
    [[ "$output" == *"interactive terminal"* ]]
    [ ! -e "$project/volumes/openbao" ]
    [ ! -s "$MOCK_DOCKER_LOG" ]
}

@test "rejects invalid mount, policy and role names" {
    export NFSE_ROLE='invalid/role'
    run_bootstrap
    [ "$status" -eq 2 ]
    [[ "$output" == *"Invalid OpenBao identifier"* ]]
    [ ! -e "$project/volumes/openbao" ]
    [ ! -s "$MOCK_DOCKER_LOG" ]
}

@test "rejects a missing or incompatible OpenBao configuration" {
    rm "$project/deploy/openbao/openbao.hcl"
    run_bootstrap
    [ "$status" -ne 0 ]
    [ ! -e "$project/volumes/openbao" ]

    printf 'storage "file" {}\n' > "$project/deploy/openbao/openbao.hcl"
    run_bootstrap
    [ "$status" -ne 0 ]
    [ ! -e "$project/volumes/openbao" ]
}

@test "refuses a symlinked volumes directory" {
    mkdir "$BATS_TEST_TMPDIR/external"
    ln -s "$BATS_TEST_TMPDIR/external" "$project/volumes"
    run_bootstrap
    [ "$status" -ne 0 ]
    [ ! -e "$BATS_TEST_TMPDIR/external/openbao" ]
}

@test "rejects an existing empty state directory without modifying it" {
    mkdir -p "$project/volumes/openbao"
    run_bootstrap
    [ "$status" -ne 0 ]
    [[ "$output" == *"state already exists"* ]]
    [ ! -e "$project/volumes/openbao/seal.key" ]
    ! grep -q 'up -d' "$MOCK_DOCKER_LOG"
}

@test "preserves an existing seal key and storage" {
    mkdir -p "$project/volumes/openbao/data"
    printf 'original-key' > "$project/volumes/openbao/seal.key"
    run_bootstrap
    [ "$status" -ne 0 ]
    [ "$(cat "$project/volumes/openbao/seal.key")" = original-key ]
    [ -d "$project/volumes/openbao/data" ]
    ! grep -q 'up -d' "$MOCK_DOCKER_LOG"
}

@test "fails Compose validation without creating persistent state" {
    export MOCK_CONFIG_FAILURE=1
    run_bootstrap
    [ "$status" -ne 0 ]
    [ ! -e "$project/volumes/openbao" ]
    ! grep -q 'up -d' "$MOCK_DOCKER_LOG"
}

@test "refuses to create state when Docker project inspection fails" {
    export MOCK_PS_FAILURE=1
    run_bootstrap
    [ "$status" -ne 0 ]
    [[ "$output" == *"Unable to inspect"* ]]
    [ ! -e "$project/volumes/openbao" ]
    ! grep -q 'up -d' "$MOCK_DOCKER_LOG"
}

@test "refuses an existing Compose project before creating state" {
    export MOCK_EXISTING_PROJECT=1
    run_bootstrap
    [ "$status" -ne 0 ]
    [[ "$output" == *"already has containers"* ]]
    [ ! -e "$project/volumes/openbao" ]
    ! grep -q 'up -d' "$MOCK_DOCKER_LOG"
}

@test "creates protected volumes, initializes once and verifies auto-unseal" {
    run_bootstrap
    [ "$status" -eq 0 ] || { echo "$output"; return 1; }
    [[ "$output" == *"completed successfully"* ]]
    [ "$(stat -c %a "$project/volumes/openbao")" = 700 ]
    [ "$(stat -c %a "$project/volumes/openbao/data")" = 700 ]
    [ "$(stat -c %a "$project/volumes/openbao/seal.key")" = 400 ]
    [ "$(wc -c < "$project/volumes/openbao/seal.key")" -eq 32 ]
    grep -q 'install -d -m 0700 -o 100 -g 100' "$MOCK_DOCKER_LOG"
    grep -q 'chown 100:100 .*seal.key' "$MOCK_DOCKER_LOG"
    grep -q 'secrets enable -path=nfse kv-v2' "$MOCK_DOCKER_LOG"
    grep -q 'auth enable approle' "$MOCK_DOCKER_LOG"
    grep -q 'restart openbao' "$MOCK_DOCKER_LOG"
    [ -f "$MOCK_BAO_STATE/initialized" ]
}

@test "grants KV v2 rights only under the NFS-e pfx prefix" {
    run_bootstrap
    [ "$status" -eq 0 ] || { echo "$output"; return 1; }
    grep -Fq 'path "nfse/data/pfx/*"' "$MOCK_BAO_STATE/policy"
    grep -Fq 'path "nfse/metadata/pfx/*"' "$MOCK_BAO_STATE/policy"
    grep -Fq '["create", "read", "update", "delete"]' "$MOCK_BAO_STATE/policy"
    ! grep -Fq 'path "nfse/data/*"' "$MOCK_BAO_STATE/policy"
}

@test "never passes the administrative token or AppRole secret on the Docker command line" {
    run_bootstrap
    [ "$status" -eq 0 ] || { echo "$output"; return 1; }
    ! grep -Fq 'fake-root-token' "$MOCK_DOCKER_LOG"
    ! grep -Fq 'fake-secret-id' "$MOCK_DOCKER_LOG"
    ! grep -Fq 'fake-recovery-key' "$MOCK_DOCKER_LOG"
    [[ "$output" == *"fake-role-id"* ]]
    [[ "$output" == *"fake-secret-id"* ]]
}

@test "supports safe custom KV mount, policy and AppRole identifiers" {
    export NFSE_MOUNT=certificates NFSE_POLICY=issuer NFSE_ROLE=issuer
    run_bootstrap
    [ "$status" -eq 0 ] || { echo "$output"; return 1; }
    grep -Fq 'path "certificates/data/pfx/*"' "$MOCK_BAO_STATE/policy"
    grep -q 'policy write issuer' "$MOCK_DOCKER_LOG"
    grep -q 'auth/approle/role/issuer' "$MOCK_DOCKER_LOG"
}

@test "refuses to initialize a server that is unexpectedly already initialized" {
    export MOCK_ALREADY_INITIALIZED=1
    run_bootstrap
    [ "$status" -ne 0 ]
    [[ "$output" == *"already initialized"* ]]
    ! grep -q 'operator init' "$MOCK_DOCKER_LOG"
    [ ! -f "$MOCK_BAO_STATE/policy" ]
}

@test "does not configure access if OpenBao initialization fails" {
    export MOCK_INIT_FAILURE=1
    run_bootstrap
    [ "$status" -ne 0 ]
    [ ! -f "$MOCK_BAO_STATE/policy" ]
    ! grep -q 'auth enable' "$MOCK_DOCKER_LOG"
}

@test "does not configure access without an administrative token" {
    export MOCK_INIT_NO_TOKEN=1
    run_bootstrap
    [ "$status" -ne 0 ]
    [[ "$output" == *"did not return an administrative token"* ]]
    [ ! -f "$MOCK_BAO_STATE/policy" ]
}

@test "stops before publishing AppRole credentials when authentication setup fails" {
    export MOCK_AUTH_FAILURE=1
    run_bootstrap
    [ "$status" -ne 0 ]
    [ ! -f "$MOCK_BAO_STATE/policy" ]
    ! grep -q 'restart openbao' "$MOCK_DOCKER_LOG"
}

@test "reports a failed auto-unseal without deleting existing storage" {
    export MOCK_RESTART_SEALED=1
    run_bootstrap
    [ "$status" -ne 0 ]
    [[ "$output" == *"did not auto-unseal"* ]]
    [ -f "$project/volumes/openbao/seal.key" ]
    [ -f "$MOCK_BAO_STATE/policy" ]
}

@test "times out when OpenBao never becomes reachable" {
    export MOCK_STATUS_TIMEOUT=1
    run_bootstrap
    [ "$status" -ne 0 ]
    [[ "$output" == *"did not become reachable"* ]]
    [ "$(grep -c 'bao status' "$MOCK_DOCKER_LOG")" -eq 30 ]
    [ -f "$project/volumes/openbao/seal.key" ]
}

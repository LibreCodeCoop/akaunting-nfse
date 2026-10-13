#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

set -euo pipefail

# Must run from the disposable Akaunting checkout root.
root="${NFSE_FEATURE_ROOT:-modules/Nfse/Tests/Feature}"
index="${NFSE_FEATURE_SHARD_INDEX:?Set NFSE_FEATURE_SHARD_INDEX}"
count="${NFSE_FEATURE_SHARD_COUNT:?Set NFSE_FEATURE_SHARD_COUNT}"

if ! [[ "$index" =~ ^[0-9]+$ && "$count" =~ ^[1-9][0-9]*$ ]] ||
    (( count > 16 || index >= count )); then
    echo "Invalid feature shard index/count." >&2
    exit 2
fi
if [[ ! -d "$root" ]]; then
    echo "NFS-e feature test directory does not exist." >&2
    exit 2
fi

mapfile -t files < <(find "$root" -type f -name '*Test.php' -print | LC_ALL=C sort)
if (( ${#files[@]} < count )); then
    echo "Feature shards exceed available test classes." >&2
    exit 2
fi

# Largest-class-first assignment balances the number of test methods without
# sharing SQLite databases or temporary certificate directories among workers.
declare -A seen_class=()
records=()
for file in "${files[@]}"; do
    class="$(basename "$file" .php)"
    if ! [[ "$class" =~ ^[A-Za-z_][A-Za-z0-9_]*Test$ ]] ||
        [[ -n "${seen_class[$class]:-}" ]]; then
        echo "Duplicate or invalid NFS-e test class: $class" >&2
        exit 2
    fi
    seen_class["$class"]=1
    tests="$(grep -Ec 'function[[:space:]]+test[A-Za-z0-9_]*[[:space:]]*\(' "$file" || true)"
    if (( tests < 1 )); then tests=1; fi
    if (( tests > 100000 )); then
        echo "Unreasonable NFS-e test weight." >&2
        exit 2
    fi
    records+=("$(printf '%06d|%s|%d' "$((100000 - tests))" "$file" "$tests")")
done

mapfile -t sorted < <(printf '%s\n' "${records[@]}" | LC_ALL=C sort)
declare -a weights=()
declare -a counts=()
selected_classes=()
for (( shard = 0; shard < count; shard++ )); do
    weights[shard]=0
    counts[shard]=0
done

for record in "${sorted[@]}"; do
    IFS='|' read -r _ file tests <<< "$record"
    smallest=0
    for (( shard = 1; shard < count; shard++ )); do
        if (( weights[shard] < weights[smallest] )); then
            smallest="$shard"
        fi
    done
    weights[smallest]=$(( weights[smallest] + tests ))
    counts[smallest]=$(( counts[smallest] + 1 ))
    if (( smallest == index )); then
        selected_classes+=("$(basename "$file" .php)")
    fi
done

if (( ${#selected_classes[@]} == 0 )); then
    echo "Feature shard selected no test classes." >&2
    exit 2
fi

filter="$(IFS='|'; printf '%s' "${selected_classes[*]}")"
printf 'NFS-e Feature shard %d/%d: %d files, %d estimated test methods, isolated runner\n' \
    "$((index + 1))" "$count" "${counts[index]}" "${weights[index]}"

if [[ "${NFSE_FEATURE_DRY_RUN:-0}" == '1' ]]; then
    printf '%s\n' "$filter"
    exit 0
fi

# PHPUnit runs one selection per disposable runner. Never add --parallel to
# this command: Laravel and the module also write to local certificate paths.
php artisan test "$root" --filter="($filter)::"

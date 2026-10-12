#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -euo pipefail

if [[ $# -ne 1 || ! "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+([+-][0-9A-Za-z.-]+)?$ ]]; then
  echo 'Usage: bash tools/bin/build-module-archive.sh <version>' >&2
  exit 2
fi

version="$1"
module_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
mkdir -p "${module_root}/build/Nfse"

rsync -a --delete "${module_root}/" "${module_root}/build/Nfse/" \
  --exclude '.git/' \
  --exclude '.github/' \
  --exclude 'build/' \
  --exclude 'akaunting-nfse-*.zip' \
  --exclude 'e2e/' \
  --exclude 'node_modules/' \
  --exclude 'tests/' \
  --exclude 'vendor/' \
  --exclude 'vendor-bin/'

(
  cd "${module_root}/build"
  zip -qr "${module_root}/akaunting-nfse-${version}.zip" Nfse
)

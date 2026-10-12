<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Modules\Nfse\Tools\DanfsePackageEvidence;

require_once dirname(__DIR__) . '/src/DanfsePackageEvidence.php';

if (count($argv) !== 2) {
    fwrite(STDERR, "Usage: php tools/bin/verify-danfse-package.php <archive.zip>\n");
    exit(2);
}

$errors = DanfsePackageEvidence::inspect($argv[1]);
foreach ($errors as $message) {
    fwrite(STDERR, $message . "\n");
}

if ($errors !== []) {
    exit(1);
}

fwrite(STDOUT, "Packaged DANFSe fonts, license, logo and renderer verified.\n");

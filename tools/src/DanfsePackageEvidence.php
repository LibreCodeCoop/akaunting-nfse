<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tools;

use ZipArchive;

/** @internal Validate the same archive that is uploaded as a module release. */
final class DanfsePackageEvidence
{
    public const FONT_SHA256 = [
        '3rdparty/scoped/librecodeoop/nfse-php/src/Danfse/Assets/fonts/LiberationSans-Regular.ttf' => '76d04c18ea243f426b7de1f3ad208e927008f961dc5945e5aad352d0dfde8ee8',
        '3rdparty/scoped/librecodeoop/nfse-php/src/Danfse/Assets/fonts/LiberationSans-Bold.ttf' => '788abee4c806d660e8aee46689dd8540cd4bb98da03dcc9d171ce3efd99a9173',
    ];

    public const REQUIRED_CONTENT = [
        '3rdparty/scoped/librecodeoop/nfse-php/src/Danfse/Assets/fonts/NOTICE.md' => 'Liberation Sans',
        '3rdparty/scoped/librecodeoop/nfse-php/LICENSES/OFL-1.1.txt' => 'SIL OPEN FONT LICENSE Version 1.1',
        '3rdparty/scoped/librecodeoop/nfse-php/src/Danfse/Config/BundledDanfseFonts.php' => 'BundledDanfseFonts',
        '3rdparty/scoped/librecodeoop/nfse-php/src/Danfse/Assets/nfse-horizontal.png' => "\x89PNG\r\n\x1a\n",
        '3rdparty/scoped/dompdf/php-font-lib/src/FontLib/Font.php' => 'FontLib',
        '3rdparty/scoped/dompdf/dompdf/src/Dompdf.php' => 'Dompdf',
        '3rdparty/scoped/autoload.php' => '',
    ];

    /**
     * @return list<string>
     */
    public static function inspect(string $archivePath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            return ['Cannot open module archive.'];
        }

        $errors = [];
        try {
            foreach (self::FONT_SHA256 as $path => $sha256) {
                $font = $zip->getFromName('Nfse/' . $path);
                if (!is_string($font)) {
                    $errors[] = 'Missing font: ' . $path;
                } elseif (!hash_equals($sha256, hash('sha256', $font))) {
                    $errors[] = 'Invalid font SHA-256: ' . $path;
                }
            }

            foreach (self::REQUIRED_CONTENT as $path => $needle) {
                $content = $zip->getFromName('Nfse/' . $path);
                if (!is_string($content)) {
                    $errors[] = 'Missing runtime resource: ' . $path;
                } elseif ($needle !== '' && !str_contains($content, $needle)) {
                    $errors[] = 'Invalid runtime resource: ' . $path;
                }
            }
        } finally {
            $zip->close();
        }

        return $errors;
    }
}

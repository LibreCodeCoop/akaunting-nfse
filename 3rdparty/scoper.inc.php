<?php

declare(strict_types=1);

$vendorDirectory = __DIR__ . '/vendor';
$outputDirectory = __DIR__ . '/scoped';

$finderClass = class_exists('Isolated\\Symfony\\Component\\Finder\\Finder')
    ? 'Isolated\\Symfony\\Component\\Finder\\Finder'
    : 'Symfony\\Component\\Finder\\Finder';

return [
    'prefix' => 'Modules\\Nfse\\Vendor',
    'output-dir' => $outputDirectory,
    'finders' => [
        $finderClass::create()->files()
            ->exclude([
                'bin',
                'humbug',
            ])
            ->in($vendorDirectory),
    ],
    'patchers' => [
        static function (string $filePath, string $prefix, string $content): string {
            if (!str_ends_with($filePath, 'dompdf/dompdf/src/Frame/Factory.php')) {
                return $content;
            }

            $escapedPrefix = str_replace('\\', '\\\\', $prefix);

            return str_replace(
                [
                    '$decorator = "Dompdf\\\\FrameDecorator\\\\{$decorator}";',
                    '$reflower = "Dompdf\\\\FrameReflower\\\\{$reflower}";',
                    '$class = \'\\\\Dompdf\\\\Positioner\\\\\'.$type;',
                    '$class = \'\\Dompdf\\Positioner\\\\\' . $type;',
                ],
                [
                    '$decorator = "' . $escapedPrefix . '\\\\Dompdf\\\\FrameDecorator\\\\{$decorator}";',
                    '$reflower = "' . $escapedPrefix . '\\\\Dompdf\\\\FrameReflower\\\\{$reflower}";',
                    '$class = \'\\\\' . $escapedPrefix . '\\\\Dompdf\\\\Positioner\\\\\'.$type;',
                    '$class = \'\\\\' . $escapedPrefix . '\\\\Dompdf\\\\Positioner\\\\\' . $type;',
                ],
                $content,
            );
        },
        static function (string $filePath, string $prefix, string $content): string {
            if (!str_ends_with($filePath, 'composer/autoload_real.php')) {
                return $content;
            }

            return str_replace(
                "if ('Composer\\Autoload\\ClassLoader' === \$class) {",
                "if ('Composer\\Autoload\\ClassLoader' === \$class || '" . $prefix . "\\Composer\\Autoload\\ClassLoader' === \$class) {",
                $content,
            );
        },
        // php-font-lib resolves font handlers and table types dynamically.
        // Scoping can rewrite the inner type while leaving the outer FontLib prefix unchanged.
        static function (string $filePath, string $prefix, string $content): string {
            $escapedPrefix = str_replace('\\', '\\\\', $prefix);

            if (str_ends_with($filePath, 'dompdf/php-font-lib/src/FontLib/Font.php')) {
                return str_replace(
                    '$class = "FontLib\\\\$class";',
                    '$scopedFontPrefix = "' . $escapedPrefix . '\\\\";' . "\n"
                        . '      if (str_starts_with($class, $scopedFontPrefix)) {' . "\n"
                        . '          $class = substr($class, strlen($scopedFontPrefix));' . "\n"
                        . '      }' . "\n"
                        . '      $class = $scopedFontPrefix . "FontLib\\\\$class";',
                    $content,
                );
            }

            if (str_ends_with($filePath, 'dompdf/php-font-lib/src/FontLib/TrueType/File.php')) {
                return str_replace(
                    [
                        'return $class_parts[1];',
                        '$class = "FontLib\\\\$type\\\\TableDirectoryEntry";',
                        '$class = "FontLib\\\\Table\\\\Type\\\\$name_canon";',
                        '$class = "FontLib\\\\Table\\\\Table";',
                    ],
                    [
                        'return $class_parts[count($class_parts) - 2];',
                        '$class = "' . $escapedPrefix . '\\\\FontLib\\\\$type\\\\TableDirectoryEntry";',
                        '$class = "' . $escapedPrefix . '\\\\FontLib\\\\Table\\\\Type\\\\$name_canon";',
                        '$class = "' . $escapedPrefix . '\\\\FontLib\\\\Table\\\\Table";',
                    ],
                    $content,
                );
            }

            return $content;
        },
    ],
];

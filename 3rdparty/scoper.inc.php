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
        // Normalize php-font-lib class names at their use sites. PHP-Scoper
        // may rewrite interpolated class-name fragments before patchers run.
        static function (string $filePath, string $prefix, string $content): string {
            $escapedPrefix = str_replace('\\', '\\\\', $prefix);
            $normalizeClass = '$scopedFontPrefix = "' . $escapedPrefix . '\\\\";' . "\n"
                . '      $class = $scopedFontPrefix . "FontLib\\\\" . str_replace(["FontLib\\\\", $scopedFontPrefix], "", ltrim($class, "\\\\"));' . "\n";

            if (str_ends_with($filePath, 'src/FontLib/Font.php')) {
                return str_replace(
                    '$obj = new $class',
                    $normalizeClass . '      $obj = new $class',
                    $content,
                );
            }

            if (str_ends_with($filePath, 'src/FontLib/TrueType/File.php')) {
                return str_replace(
                    [
                        'return $class_parts[1];',
                        '$entry = new $class',
                        'if (!isset($this->directory[$tag])',
                        '$table = new $class',
                    ],
                    [
                        'return $class_parts[count($class_parts) - 2];',
                        $normalizeClass . '      $entry = new $class',
                        $normalizeClass . '      if (!isset($this->directory[$tag])',
                        $normalizeClass . '      $table = new $class',
                    ],
                    $content,
                );
            }

            return $content;
        },
    ],
];

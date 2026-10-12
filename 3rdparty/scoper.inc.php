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
        // php-font-lib builds some class names from string fragments. PHP-Scoper
        // can prefix a relative type (TrueType\File) without prefixing its
        // FontLib namespace. Follow the tested WCPOS scoping pattern:
        // fix the string literals after scoping, not executable control flow.
        static function (string $filePath, string $prefix, string $content): string {
            $fontLoader = str_ends_with($filePath, 'dompdf/php-font-lib/src/FontLib/Font.php');
            $trueTypeFile = str_ends_with($filePath, 'dompdf/php-font-lib/src/FontLib/TrueType/File.php');
            if (!$fontLoader && !$trueTypeFile) {
                return $content;
            }

            $escapedPrefix = str_replace('\\', '\\\\', $prefix);
            $pattern = '/(?<!' . preg_quote($escapedPrefix . '\\\\', '/') . ')FontLib\\\\\\\\/';
            $patched = preg_replace_callback(
                $pattern,
                static fn (array $match): string => $escapedPrefix . '\\\\FontLib\\\\',
                $content,
            );
            if (!is_string($patched)) {
                throw new \RuntimeException('Unable to scope dynamic FontLib class references.');
            }

            if ($fontLoader) {
                // These fragments identify subtypes within FontLib, not FQCNs.
                foreach (['TrueType', 'OpenType', 'WOFF', 'EOT'] as $subNamespace) {
                    $patched = str_replace(
                        '"' . $escapedPrefix . '\\\\' . $subNamespace . '\\\\',
                        '"' . $subNamespace . '\\\\',
                        $patched,
                    );
                }
            } else {
                // The subtype is the penultimate namespace segment, even
                // when PHP-Scoper adds its prefix before FontLib.
                $patched = str_replace(
                    'return $class_parts[1];',
                    'return $class_parts[count($class_parts) - 2];',
                    $patched,
                );
            }

            return $patched;
        },
    ],
];

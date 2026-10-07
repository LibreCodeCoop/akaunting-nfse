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
    ],
];

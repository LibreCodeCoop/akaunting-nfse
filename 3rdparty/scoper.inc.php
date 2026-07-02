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

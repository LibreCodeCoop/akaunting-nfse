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
];

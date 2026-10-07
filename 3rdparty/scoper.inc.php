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

            $replacements = [
                '/^\\s*\\$decorator\\s*=\\s*".*FrameDecorator.*";\\s*$/m'
                    => '        $decorator = sprintf(\'%s\\\\FrameDecorator\\\\%s\', substr(__NAMESPACE__, 0, strrpos(__NAMESPACE__, \'\\\\Frame\')), $decorator);',
                '/^\\s*\\$reflower\\s*=\\s*".*FrameReflower.*";\\s*$/m'
                    => '        $reflower = sprintf(\'%s\\\\FrameReflower\\\\%s\', substr(__NAMESPACE__, 0, strrpos(__NAMESPACE__, \'\\\\Frame\')), $reflower);',
                '/^\\s*\\$class\\s*=\\s*.*Positioner.*\\$type;\\s*$/m'
                    => '            $class = sprintf(\'%s\\\\Positioner\\\\%s\', substr(__NAMESPACE__, 0, strrpos(__NAMESPACE__, \'\\\\Frame\')), $type);',
            ];

            foreach ($replacements as $pattern => $replacement) {
                $patched = preg_replace_callback(
                    $pattern,
                    static fn (): string => $replacement,
                    $content,
                );

                if (is_string($patched)) {
                    $content = $patched;
                }
            }

            return $content;
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

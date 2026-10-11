<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Application;

use Modules\Nfse\Application\ArtifactPathBuilder;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\ReceiptData;
use PHPUnit\Framework\TestCase;

final class ArtifactPathBuilderTest extends TestCase
{
    public function testBuildsDeterministicPathFromFiscalAndInvoiceMetadata(): void
    {
        $receipt = new ReceiptData(
            nfseNumber: 'NFS 42/2026',
            chaveAcesso: 'ABC 123/XYZ',
            dataEmissao: '2026-10-05T10:30:00-03:00',
        );

        $builder = new ArtifactPathBuilder();

        $base = $builder->basePath(
            'nfse/{cnpj}/{year}/{month}/{day}/{customer_name}',
            '11222333000181',
            'Cliente Árvore Ltda.',
            $receipt,
        );

        self::assertSame(
            'nfse/11222333000181/2026/10/05/cliente-árvore-ltda',
            $base,
        );
        self::assertSame(
            'nfse/11222333000181/2026/10/05/cliente-árvore-ltda/nfs-42-2026-abc-123-xyz.xml',
            $builder->filePath(
                $base,
                '{nfse_number}-{chave_acesso}',
                '11222333000181',
                'Cliente Árvore Ltda.',
                $receipt,
                'xml',
            ),
        );
    }

    public function testFallsBackToCanonicalTemplatesAndAvoidsDuplicateExtension(): void
    {
        $receipt = new ReceiptData(
            nfseNumber: '100',
            chaveAcesso: 'KEY100',
            dataEmissao: '2026-01-02T10:30:00-03:00',
        );

        $builder = new ArtifactPathBuilder();
        $base = $builder->basePath('', '123', 'Cliente', $receipt);

        self::assertSame('nfse/123/2026/01/02', $base);
        self::assertSame(
            'nfse/123/2026/01/02/key100.pdf',
            $builder->filePath($base, '{chave_acesso}.pdf', '123', 'Cliente', $receipt, 'pdf'),
        );
    }

    public function testReceiptNumberFallsBackToAuthorizedXml(): void
    {
        $receipt = new ReceiptData(
            nfseNumber: '',
            chaveAcesso: 'KEY',
            dataEmissao: '2026-03-14T10:30:00-03:00',
            rawXml: '<NFSe><infNFSe><nNFSe>987</nNFSe></infNFSe></NFSe>',
        );

        $replacements = (new ArtifactPathBuilder())->replacements('123', 'Cliente', $receipt);

        self::assertSame('987', $replacements['{nfse_number}']);
        self::assertSame('marco', $replacements['{month_name}']);
    }
}

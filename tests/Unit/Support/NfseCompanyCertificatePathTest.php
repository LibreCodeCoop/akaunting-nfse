<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Unit\Support;

use Modules\Nfse\Support\NfseCompanyCertificatePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NfseCompanyCertificatePathTest extends TestCase
{
    public function testCompaniesWithSameCnpjCannotShareCertificatePaths(): void
    {
        $reference = NfseCompanyCertificatePath::newReference();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $reference);
        self::assertSame('pfx/companies/11/' . $reference, NfseCompanyCertificatePath::vaultPath(11, $reference));
        self::assertNotSame(
            NfseCompanyCertificatePath::vaultPath(11, $reference),
            NfseCompanyCertificatePath::vaultPath(12, $reference),
        );
    }

    #[DataProvider('invalidReferences')]
    public function testInvalidIdsNeverBecomeFilesystemOrVaultPaths(int $companyId, string $reference): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NfseCompanyCertificatePath::vaultPath($companyId, $reference);
    }

    /** @return array<string,array{int,string}> */
    public static function invalidReferences(): array
    {
        return [
            'missing company' => [0, str_repeat('a', 32)],
            'relative traversal' => [1, '../pfx'],
            'nonhex reference' => [1, str_repeat('z', 32)],
            'uppercase unexpected revision' => [1, str_repeat('A', 32)],
            'empty reference' => [1, ''],
        ];
    }
}

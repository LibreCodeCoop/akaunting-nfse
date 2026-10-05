<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Feature;

use Modules\Nfse\Support\FiscalClientFactory;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\HttpTransportInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Http\NativeStreamTransport;
use Tests\Feature\FeatureTestCase;

final class FiscalClientCompositionFeatureTest extends FeatureTestCase
{
    public function testModuleProviderComposesFiscalTransportAndFactoryInRealAkauntingContainer(): void
    {
        $transport = app(HttpTransportInterface::class);
        $factory = app(FiscalClientFactory::class);

        self::assertInstanceOf(NativeStreamTransport::class, $transport);
        self::assertInstanceOf(FiscalClientFactory::class, $factory);
        self::assertSame(
            $transport,
            app(HttpTransportInterface::class),
            'The module container should consistently resolve the configured fiscal transport.',
        );
    }
}

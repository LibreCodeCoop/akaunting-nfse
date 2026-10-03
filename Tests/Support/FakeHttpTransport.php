<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Tests\Support;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Contracts\HttpTransportInterface;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\HttpRequestData;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\HttpResponseData;

final class FakeHttpTransport implements HttpTransportInterface
{
    /** @var list<HttpRequestData> */
    public array $requests = [];

    /** @var list<HttpResponseData> */
    private array $responses;

    public function __construct(HttpResponseData ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function request(HttpRequestData $request): HttpResponseData
    {
        $this->requests[] = $request;

        if ($this->responses === []) {
            throw new \RuntimeException('Synthetic fiscal transport has no queued response.');
        }

        return array_shift($this->responses);
    }
}

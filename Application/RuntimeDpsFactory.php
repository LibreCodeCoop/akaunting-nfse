<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Dto\DpsData;

/**
 * Adapts the module payload to the installed nfse-php DpsData runtime contract.
 *
 * Optional fields unknown to an older compatible runtime are ignored. Fields
 * explicitly required by the current fiscal scenario fail closed.
 */
final class RuntimeDpsFactory
{
    /**
     * @param array<string, mixed> $payload
     * @param list<string> $requiredFields
     */
    public function make(array $payload, array $requiredFields = []): DpsData
    {
        $constructor = new \ReflectionMethod(DpsData::class, '__construct');
        $supportedPayload = [];
        $supportedFields = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $supportedFields[$name] = true;

            if (array_key_exists($name, $payload)) {
                $supportedPayload[$name] = $payload[$name];
            }
        }

        foreach ($requiredFields as $field) {
            if (!isset($supportedFields[$field])) {
                throw new \LogicException(
                    'Installed nfse-php runtime does not support required DPS field: ' . $field,
                );
            }
        }

        return new DpsData(...$supportedPayload);
    }
}

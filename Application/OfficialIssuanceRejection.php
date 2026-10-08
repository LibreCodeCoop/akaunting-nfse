<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\GatewayException;
use Modules\Nfse\Vendor\LibreCodeCoop\NfsePHP\Exception\IssuanceException;

/**
 * A structured SEFIN issuance response, not a generic HTTP/gateway error,
 * is the sole evidence for a rejected DPS.
 */
final class OfficialIssuanceRejection
{
    /** @return array{code:string,message:string,http_status:int}|null */
    public function fromException(GatewayException $exception): ?array
    {
        if (!$exception instanceof IssuanceException || !in_array($exception->httpStatus, [400, 422], true)) {
            return null;
        }

        $payload = $exception->upstreamPayload;
        $errors = $payload['erros'] ?? $payload['erro'] ?? $payload;

        if (!is_array($errors)) {
            return null;
        }

        if (isset($errors['codigo']) || isset($errors['Codigo'])) {
            $errors = [$errors];
        }

        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $code = strtoupper($this->text($error['codigo'] ?? $error['Codigo'] ?? null));
            $description = $this->text($error['descricao'] ?? $error['Descricao'] ?? null);
            $complement = $this->text($error['complemento'] ?? $error['Complemento'] ?? null);

            if (!preg_match('/^[A-Z][A-Z0-9_-]{2,23}$/D', $code) || $description === '') {
                continue;
            }

            $message = $this->sanitize($description . ($complement !== '' ? ' - ' . $complement : ''));

            if ($message !== '') {
                return [
                    'code' => $code,
                    'message' => $message,
                    'http_status' => $exception->httpStatus,
                ];
            }
        }

        return null;
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function sanitize(string $message): string
    {
        // Upstream messages can contain private fiscal data; store only a
        // bounded, sanitized diagnostic. Never persist raw upstream JSON/XML.
        if (str_contains($message, '<') || str_contains($message, '>')) {
            return '[redacted-structured-content]';
        }

        $message = preg_replace('/-----BEGIN[\\s\\S]*?-----END[^-]*-----/i', '[redacted-certificate]', $message) ?? '';
        $message = preg_replace('/\\b(?:\\d{11}|\\d{14})\\b/', '[redacted-document]', $message) ?? '';
        $message = preg_replace('/\\b[\\w.+-]+@[\\w.-]+\\.[a-z]{2,}\\b/i', '[redacted-email]', $message) ?? '';
        $message = preg_replace('/\\b(?:bearer|token|senha|password|secret)\\s*[:=]\\s*\\S+/i', '[redacted-secret]', $message) ?? '';
        $message = preg_replace('/\\s+/u', ' ', $message) ?? '';

        return trim(function_exists('mb_substr') ? mb_substr($message, 0, 1500) : substr($message, 0, 1500));
    }
}

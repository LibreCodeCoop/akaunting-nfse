<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

final class NfseEmailFooter
{
    public const DEFAULT_MESSAGE = 'Conheça nossos produtos e soluções em software livre.';

    public const DEFAULT_URL = 'https://librecode.coop';

    public function isSafeUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && is_string($parts['host'] ?? null) && $parts['host'] !== ''
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    public function render(string $message, string $url): string
    {
        $message = trim($message) !== '' ? trim($message) : self::DEFAULT_MESSAGE;
        $url = $this->isSafeUrl($url) ? $url : self::DEFAULT_URL;
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" '
            . 'style="width:100%;margin-top:16px;background-color:#f8f9fa;">'
            . '<tr><td style="padding:12px;text-align:center;font-size:13px;color:#454545;">'
            . '<strong>LibreCode</strong> — ' . $safeMessage
            . ' <a href="' . $safeUrl . '" style="color:#25635c;text-decoration:underline;">'
            . 'Conheça nossas soluções</a></td></tr></table>';
    }
}

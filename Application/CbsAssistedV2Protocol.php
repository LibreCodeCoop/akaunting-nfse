<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Transport-independent contract of Receita Federal's asynchronous CBS v2 API.
 * Builds official URL/method/body specifications but NEVER makes a request,
 * stores credentials, retries a POST, or downloads a pre-signed artifact.
 *
 * The executing adapter must supply short-lived OAuth2 credentials from an
 * approved secret store, enforce 4 starts/day/API/credential and retain
 * durable incremental cursors before enabling actual scheduled synchronization.
 *
 * Source: https://docs.receitafederal.gov.br/apuracao-cbs/
 * asynchronous guide v1.1 (14 Oct 2026).
 */
final class CbsAssistedV2Protocol
{
    private const BASES = [
        'production' => 'https://api.receitafederal.gov.br/apuracao-cbs/v2',
        'restricted' => 'https://api.receitafederal.gov.br/apuracao-cbs-prr/v2',
    ];

    private const KINDS = ['debitos', 'creditos', 'pagamentos', 'recolhimentos'];

    /**
     * @return array{method:string,url:string,json:array{urlRetorno:string}}
     */
    public function begin(string $kind, string $cnpjBase, string $environment = 'restricted'): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unrecognized CBS consultation type.');
        }
        if (preg_match('/^[0-9]{8}$/D', $cnpjBase) !== 1) {
            throw new \InvalidArgumentException('CBS API requires the eight-digit CNPJ base.');
        }

        return [
            'method' => 'POST',
            'url' => $this->base($environment) . '/' . $kind . '/' . $cnpjBase,
            'json' => ['urlRetorno' => ''],
        ];
    }

    /** @return array{method:string,url:string} */
    public function status(string $ticket, string $environment = 'restricted'): array
    {
        return $this->get('situacao', $ticket, $environment);
    }

    /** @return array{method:string,url:string} */
    public function downloadLink(string $ticket, string $environment = 'restricted'): array
    {
        return $this->get('download', $ticket, $environment);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{ticket:string,estimated_seconds:int|null}
     */
    public function accepted(int $httpStatus, array $data): array
    {
        if ($httpStatus !== 201 || !is_string($data['tiqueteSolicitacao'] ?? null)) {
            throw new \DomainException('CBS consultation was not confirmed by the official API.');
        }

        $ticket = $this->ticket($data['tiqueteSolicitacao']);
        $eta = $data['tEASegundos'] ?? null;
        if ($eta !== null && (!is_int($eta) || $eta < -1)) {
            throw new \DomainException('Invalid CBS consultation estimated duration.');
        }

        return [
            'ticket' => $ticket,
            'estimated_seconds' => $eta === -1 ? null : $eta,
        ];
    }

    /** @param array<string,mixed> $data */
    public function statusFromResponse(int $httpStatus, array $data): string
    {
        if ($httpStatus !== 200 || !is_string($data['situacao'] ?? null)) {
            throw new \DomainException('CBS status response was not confirmed by the official API.');
        }

        $status = $data['situacao'];
        if (!in_array($status, ['PENDENTE', 'EM_PROCESSAMENTO', 'CONCLUIDA', 'ERRO', 'EXPIRADA'], true)) {
            throw new \DomainException('Unsupported CBS consultation status.');
        }

        return $status;
    }

    private function get(string $action, string $ticket, string $environment): array
    {
        return ['method' => 'GET', 'url' => $this->base($environment) . '/'
            . $action . '/' . rawurlencode($this->ticket($ticket))];
    }

    private function base(string $environment): string
    {
        if (!isset(self::BASES[$environment])) {
            throw new \InvalidArgumentException('Invalid CBS API environment.');
        }

        return self::BASES[$environment];
    }

    private function ticket(string $ticket): string
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/D', $ticket) !== 1) {
            throw new \InvalidArgumentException('Invalid CBS consultation ticket.');
        }

        return $ticket;
    }
}

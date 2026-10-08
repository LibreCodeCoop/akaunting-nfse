<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Pure, non-networked evidence contract for one *exact* DPS attempt.
 *
 * ADN parameter consultations are not a municipal authorization decision.
 * Only a matched SEFIN issuance outcome can establish acceptance or E0312
 * rejection of that exact DPS. Callers must authenticate and persist the
 * original official response before constructing issuance evidence.
 */
final class MunicipalAdmissibilityEvaluator
{
    /**
     * @param array<string,mixed> $context Company, environment, incidence municipality,
     *   competence, six-digit national code, three-digit complement, nine-digit
     *   service code and (for issuance evidence) DPS ID and SHA-256 digest.
     * @param array<string,mixed>|null $evidence
     * @return array<string,mixed>
     */
    public function evaluate(array $context, ?array $evidence = null): array
    {
        $normalized = $this->normalizeContext($context);
        $complete = $this->isComplete($normalized);
        $source = [
            'kind' => is_string($evidence['kind'] ?? null) ? $evidence['kind'] : '',
            'url' => is_string($evidence['source_url'] ?? null) ? $evidence['source_url'] : '',
            'http_status' => is_int($evidence['http_status'] ?? null) ? $evidence['http_status'] : null,
            'consulted_at' => is_string($evidence['consulted_at'] ?? null) ? $evidence['consulted_at'] : '',
            'contract_version' => is_string($evidence['contract_version'] ?? null) ? $evidence['contract_version'] : '',
            'valid_from' => is_string($evidence['valid_from'] ?? null) ? $evidence['valid_from'] : '',
            'valid_until' => is_string($evidence['valid_until'] ?? null) ? $evidence['valid_until'] : '',
            'fallback_reason' => is_string($evidence['fallback_reason'] ?? null) ? $evidence['fallback_reason'] : '',
        ];

        $result = [
            'decision' => 'unverifiable',
            'can_attempt' => true,
            'authorized' => false,
            'binding' => 'none',
            'reason' => $complete ? 'official_evidence_missing' : 'municipal_context_incomplete',
            'context' => $normalized,
            'source' => $source,
        ];

        if (!$complete || $evidence === null) {
            return $result;
        }

        if (($evidence['kind'] ?? '') !== 'sefin_issuance_result') {
            $result['reason'] = match (true) {
                ($evidence['stale'] ?? false) === true => 'municipal_snapshot_stale',
                ($evidence['fallback_reason'] ?? '') !== '' => 'municipal_source_unavailable',
                ($evidence['http_status'] ?? null) === 404 => 'municipal_404_inconclusive',
                default => 'municipal_parameters_consultative_only',
            };

            return $result;
        }

        // A rejection or authorization for another company, municipality,
        // competence, environment, service or DPS cannot be reused.
        $actual = is_array($evidence['context'] ?? null)
            ? $this->normalizeContext($evidence['context'])
            : [];
        $dpsBound = $normalized['dps_id'] !== ''
            && preg_match('/^[a-f0-9]{64}$/', $normalized['dps_sha256']) === 1;
        $url = $source['url'];
        $host = parse_url($url, PHP_URL_HOST);
        $expectedHost = $normalized['environment'] === 'sandbox'
            ? 'sefin.producaorestrita.nfse.gov.br'
            : 'sefin.nfse.gov.br';
        $official = is_string($host) && $host === $expectedHost
            && parse_url($url, PHP_URL_SCHEME) === 'https';

        if (!$dpsBound || !$official || $actual !== $normalized || $source['consulted_at'] === '') {
            $result['reason'] = 'official_evidence_context_unverified';

            return $result;
        }

        $result['binding'] = 'exact';
        $decision = $evidence['outcome'] ?? null;
        $httpStatus = $source['http_status'];

        if ($decision === 'authorized'
            && in_array($httpStatus, [200, 201], true)
            && is_string($evidence['access_key'] ?? null)
            && trim($evidence['access_key']) !== '') {
            return array_merge($result, [
                'decision' => 'authorized',
                'authorized' => true,
                'reason' => 'sefin_issued_this_dps',
            ]);
        }

        if ($decision === 'rejected'
            && ($evidence['error_code'] ?? null) === 'E0312'
            && is_int($httpStatus)
            && $httpStatus >= 400 && $httpStatus < 500) {
            return array_merge($result, [
                'decision' => 'rejected',
                'can_attempt' => false,
                'reason' => 'sefin_e0312_this_dps',
            ]);
        }

        $result['reason'] = 'official_result_not_decisive';

        return $result;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function normalizeContext(array $input): array
    {
        $national = trim((string) ($input['national_code'] ?? ''));
        $complement = trim((string) ($input['municipal_complement'] ?? ''));
        $complement = $complement === '' ? '000' : $complement;

        return [
            'company_id' => is_numeric($input['company_id'] ?? null) ? (int) $input['company_id'] : 0,
            'environment' => trim((string) ($input['environment'] ?? '')),
            'incidence_municipality' => trim((string) ($input['incidence_municipality'] ?? '')),
            'competence' => trim((string) ($input['competence'] ?? '')),
            'national_code' => $national,
            'municipal_complement' => $complement,
            'service_code' => trim((string) ($input['service_code'] ?? '')),
            'dps_id' => trim((string) ($input['dps_id'] ?? '')),
            'dps_sha256' => strtolower(trim((string) ($input['dps_sha256'] ?? ''))),
        ];
    }

    /** @param array<string,mixed> $context */
    private function isComplete(array $context): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $context['competence']);

        return $context['company_id'] > 0
            && in_array($context['environment'], ['sandbox', 'production'], true)
            && preg_match('/^\d{7}$/', $context['incidence_municipality']) === 1
            && $date instanceof \DateTimeImmutable
            && $date->format('Y-m-d') === $context['competence']
            && preg_match('/^\d{6}$/', $context['national_code']) === 1
            && preg_match('/^\d{3}$/', $context['municipal_complement']) === 1
            && preg_match('/^\d{9}$/', $context['service_code']) === 1
            && $context['service_code'] === $context['national_code'] . $context['municipal_complement'];
    }
}

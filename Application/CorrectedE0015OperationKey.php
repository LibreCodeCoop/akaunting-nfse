<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/**
 * Only a confirmed E0015 of an ordinary manual issuance may start a successor.
 * Previously transmitted DPS numbers and operation keys remain immutable.
 */
final class CorrectedE0015OperationKey
{
    private const MAX_REVISIONS = 32;

    /**
     * @param callable(string):(?array{id:int,status:string,official_code:?string,http_status:?int,competence_date:?string,started_at:?string}) $lastAttempt
     */
    public function resolve(string $baseKey, string $competence, string $issueDate, callable $lastAttempt): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $baseKey) !== 1) {
            throw new \InvalidArgumentException('A valid fiscal operation key is required.');
        }

        $current = $baseKey;
        $seen = [];

        for ($revision = 0; $revision < self::MAX_REVISIONS; $revision++) {
            if (isset($seen[$current])) {
                throw new \LogicException('E0015 correction chain contains a cycle.');
            }
            $seen[$current] = true;

            $attempt = $lastAttempt($current);
            if (!$this->confirmedE0015($attempt)) {
                return $current;
            }

            if (!DpsCompetencePreflight::validDate($competence)
                || !DpsCompetencePreflight::validDate($issueDate)) {
                throw new FiscalIssuanceBlocked(
                    'E0015: informe uma competência fiscal e uma data de emissão válidas (AAAA-MM-DD).',
                );
            }

            if ($competence > $issueDate) {
                throw new FiscalIssuanceBlocked(
                    'E0015: a competência da DPS (' . $this->display($competence) . ') é posterior '
                    . 'à data de emissão (' . $this->display($issueDate) . '). '
                    . 'Corrija a data da fatura/competencia antes de emitir. '
                    . 'A DPS rejeitada já consumiu sua numeração e não será reenviada.',
                );
            }

            $previousCompetence = (string) ($attempt['competence_date'] ?? '');
            $attemptDay = substr((string) ($attempt['started_at'] ?? ''), 0, 10);
            $dateChanged = DpsCompetencePreflight::validDate($previousCompetence)
                && $previousCompetence !== $competence;
            // A previously future competence also becomes valid after midnight.
            $dayAdvanced = DpsCompetencePreflight::validDate($attemptDay)
                && $attemptDay < $competence
                && $competence <= $issueDate;

            if (!$dateChanged && !$dayAdvanced) {
                throw new FiscalIssuanceBlocked(
                    'E0015: a DPS anterior foi rejeitada e sua identidade fiscal está consumida. '
                    . 'Confira a data de competência na fatura; para tentar novamente, ela precisa '
                    . 'estar correta e não pode ser posterior à data da nova emissão. '
                    . 'Nenhuma nova DPS foi transmitida.',
                );
            }

            $current = hash('sha256', json_encode([
                'version' => 1,
                'kind' => 'corrected_e0015',
                'previous_operation' => $current,
                'rejected_attempt_id' => (int) $attempt['id'],
            ], JSON_THROW_ON_ERROR));
        }

        throw new \LogicException('E0015 correction chain exceeded its reviewed limit.');
    }

    /** @param array<string,mixed>|null $attempt */
    private function confirmedE0015(?array $attempt): bool
    {
        return is_array($attempt)
            && is_numeric($attempt['id'] ?? null)
            && (int) $attempt['id'] > 0
            && ($attempt['status'] ?? null) === 'rejected'
            && ($attempt['official_code'] ?? null) === 'E0015'
            && in_array((int) ($attempt['http_status'] ?? 0), [400, 422], true);
    }

    private function display(string $date): string
    {
        return substr($date, 8, 2) . '/' . substr($date, 5, 2) . '/' . substr($date, 0, 4);
    }
}

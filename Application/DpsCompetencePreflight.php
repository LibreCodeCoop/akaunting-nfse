<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Application;

/** Validates DPS fiscal competence without allocating a fiscal number. */
final class DpsCompetencePreflight
{
    public function assertCanAllocate(?string $competence, string $issueDate): void
    {
        if (!self::validDate($competence) || !self::validDate($issueDate)) {
            throw new FiscalIssuanceBlocked(
                'E0015: a competência fiscal da DPS ou a data de emissão está ausente ou inválida. '
                . 'Confira a data de emissão da fatura antes de emitir.',
            );
        }

        if ($competence > $issueDate) {
            $formattedCompetence = substr($competence, 8, 2) . '/' . substr($competence, 5, 2) . '/' . substr($competence, 0, 4);
            $formattedIssueDate = substr($issueDate, 8, 2) . '/' . substr($issueDate, 5, 2) . '/' . substr($issueDate, 0, 4);
            throw new FiscalIssuanceBlocked(
                'E0015: a competência informada na DPS (' . $formattedCompetence
                . ') é posterior à data de emissão (' . $formattedIssueDate . '). '
                . 'Ajuste a data da fatura ou a competência fiscal antes de emitir. '
                . 'O vencimento da fatura não interfere nesta regra. Nenhuma DPS foi enviada.',
            );
        }
    }

    public static function validDate(?string $date): bool
    {
        if ($date === null || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date) !== 1) {
            return false;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }
}

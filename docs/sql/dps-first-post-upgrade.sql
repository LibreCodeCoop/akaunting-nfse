-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
-- SPDX-License-Identifier: AGPL-3.0-or-later

-- Legacy upgrade reference only. New installations use the normal Akaunting
-- migration in the SAME database; do not apply this file on a fresh installation.
-- Take a verified backup and confirm that the columns do not already exist.
-- Existing allocations remain unclaimed: do NOT activate an installation
-- with historical outbound POSTs until those allocations are reconciled and
-- their previously transmitted state is established by the operator.
ALTER TABLE nfse_dps_coordination_allocations
    ADD COLUMN first_post_claimed_at TIMESTAMP(6) NULL,
    ADD COLUMN claimed_generation BIGINT UNSIGNED NULL,
    ADD COLUMN first_post_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL;

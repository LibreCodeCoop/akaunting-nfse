-- SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
-- SPDX-License-Identifier: AGPL-3.0-or-later

-- Reference DDL for the DPS authority inside the SAME Akaunting MySQL schema.
-- Normal installations use the versioned Laravel migration rather than
-- manually executing this file; do not install a separate coordination DB.
-- Neither migration nor reference DDL activates a series automatically.
-- See docs/dps-distributed-coordination.md for provisioning and restore gates.

CREATE TABLE IF NOT EXISTS nfse_dps_coordination_series (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    environment TINYINT UNSIGNED NOT NULL,
    issuer_municipality CHAR(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    issuer_registration_type TINYINT UNSIGNED NOT NULL,
    issuer_registration CHAR(14) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    series CHAR(5) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    coordination_reference CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'inactive',
    attested_floor BIGINT UNSIGNED NOT NULL,
    evidence_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_number BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY nfse_dps_global_issuer (environment, issuer_municipality,
        issuer_registration_type, issuer_registration, series),
    CONSTRAINT nfse_dps_global_number_limit CHECK (last_number <= 999999999999999),
    CONSTRAINT nfse_dps_global_floor_limit CHECK (attested_floor <= last_number)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS nfse_dps_coordination_members (
    series_id BIGINT UNSIGNED NOT NULL,
    installation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    generation BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'inactive',
    PRIMARY KEY (series_id, installation_id),
    CONSTRAINT nfse_dps_global_members_series_fk FOREIGN KEY (series_id)
        REFERENCES nfse_dps_coordination_series (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS nfse_dps_coordination_allocations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    series_id BIGINT UNSIGNED NOT NULL,
    installation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    number BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    first_post_claimed_at TIMESTAMP(6) NULL,
    claimed_generation BIGINT UNSIGNED NULL,
    first_post_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    UNIQUE KEY nfse_dps_global_fiscal_number (series_id, number),
    UNIQUE KEY nfse_dps_global_operation (installation_id, operation_key),
    CONSTRAINT nfse_dps_global_allocation_member_fk FOREIGN KEY (series_id, installation_id)
        REFERENCES nfse_dps_coordination_members (series_id, installation_id)
        ON DELETE RESTRICT
) ENGINE=InnoDB;

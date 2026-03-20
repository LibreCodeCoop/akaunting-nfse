<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    'name'                  => 'NFS-e',
    'description'           => 'NFS-e issuance and settings',
    'saved'                 => 'Settings saved successfully.',
    'certificate_uploaded'  => 'Certificate uploaded and password stored securely.',
    'certificate_deleted'   => 'Certificate removed successfully.',
    'invalid_pfx'           => 'Invalid PFX file or incorrect password.',
    'nfse_emitted'          => 'NFS-e :number emitted successfully.',
    'nfse_cancelled'        => 'NFS-e cancelled successfully.',
    'cancel_motivo_default' => 'Cancellation requested by the service provider.',
    'service_default'       => 'Service provision as per contract.',

    'settings' => [
        'title'            => 'NFS-e Settings',
        'cnpj_prestador'   => 'Service Provider CNPJ',
        'uf'               => 'State (UF)',
        'municipio_nome'   => 'Municipality',
        'municipio_ibge'   => 'Municipality IBGE Code',
        'sandbox_mode'     => 'Sandbox Mode (Staging)',
        'bao_addr'         => 'OpenBao / Vault Address',
        'bao_mount'        => 'KV v2 Mount',
        'bao_token'        => 'OpenBao / Vault Token',
        'bao_role_id'      => 'AppRole Role ID',
        'bao_secret_id'    => 'AppRole Secret ID',
        'certificate'      => 'ICP-Brasil Certificate (PFX)',
        'pfx_password'     => 'Certificate Password',
        'item_lista'       => 'Service List Item (LC 116)',
        'aliquota'         => 'ISS Rate (%)',
    ],
];

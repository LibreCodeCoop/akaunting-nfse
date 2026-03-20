<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    'name'                  => 'NFS-e',
    'description'           => 'Emissao e configuracoes de NFS-e',
    'saved'                 => 'Configurações salvas com sucesso.',
    'certificate_uploaded'  => 'Certificado enviado e senha armazenada com segurança.',
    'certificate_deleted'   => 'Certificado removido com sucesso.',
    'invalid_pfx'           => 'Arquivo PFX inválido ou senha incorreta.',
    'nfse_emitted'          => 'NFS-e :number emitida com sucesso.',
    'nfse_cancelled'        => 'NFS-e cancelada com sucesso.',
    'cancel_motivo_default' => 'Cancelamento solicitado pelo prestador de serviço.',
    'service_default'       => 'Prestação de serviços conforme contrato.',

    'settings' => [
        'title'            => 'Configurações NFS-e',
        'cnpj_prestador'   => 'CNPJ do Prestador',
        'municipio_ibge'   => 'Código IBGE do Município',
        'sandbox_mode'     => 'Modo Sandbox (Homologação)',
        'bao_addr'         => 'Endereço OpenBao / Vault',
        'bao_mount'        => 'Mount KV v2',
        'bao_token'        => 'Token do OpenBao / Vault',
        'bao_role_id'      => 'AppRole Role ID',
        'bao_secret_id'    => 'AppRole Secret ID',
        'certificate'      => 'Certificado ICP-Brasil (PFX)',
        'pfx_password'     => 'Senha do Certificado',
        'item_lista'       => 'Item da Lista de Serviços (LC 116)',
        'aliquota'         => 'Alíquota ISS (%)',
    ],
];

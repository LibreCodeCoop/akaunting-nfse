<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    'name'                  => 'NFS-e',
    'description'           => 'Emissao e configuracoes de NFS-e',
    'saved'                 => 'Configurações salvas com sucesso.',
    'saved_and_certificate_uploaded' => 'Configurações salvas e certificado armazenado com segurança.',
    'certificate_uploaded'  => 'Certificado enviado e senha armazenada com segurança.',
    'certificate_deleted'   => 'Certificado removido com sucesso.',
    'upload'                => 'Enviar certificado',
    'read_certificate'      => 'Ler certificado',
    'invalid_pfx'           => 'Arquivo PFX inválido ou senha incorreta.',
    'certificate_store_failed' => 'As configurações foram salvas, mas não foi possível armazenar o certificado. Configure o OpenBao / Vault e tente novamente.',
    'cnpj_not_found'        => 'CNPJ não encontrado no certificado. Verifique se é um certificado ICP-Brasil A1 válido.',
    'cnpj_from_certificate' => 'CNPJ extraído do certificado:',
    'step_certificate'      => '1. Certificado Digital',
    'step_settings'         => '2. Configurações NFS-e',
    'nfse_emitted'          => 'NFS-e :number emitida com sucesso.',
    'nfse_cancelled'        => 'NFS-e cancelada com sucesso.',
    'cancel_motivo_default' => 'Cancelamento solicitado pelo prestador de serviço.',
    'service_default'       => 'Prestação de serviços conforme contrato.',

    'settings' => [
        'title'                 => 'Configurações NFS-e',
        'cnpj_prestador'        => 'CNPJ do Prestador',
        'cnpj_from_certificate' => 'CNPJ (extraído do certificado)',
        'uf'               => 'Estado (UF)',
        'municipio_nome'   => 'Município',
        'municipio_ibge'   => 'Código IBGE do Município',
        'sandbox_mode'     => 'Modo Sandbox (Homologação)',
        'bao_addr'         => 'Endereço OpenBao / Vault',
        'bao_mount'        => 'Mount KV v2',
        'bao_token'        => 'Token do OpenBao / Vault',
        'bao_role_id'      => 'AppRole Role ID',
        'bao_secret_id'    => 'AppRole Secret ID',
        'certificate'      => 'Certificado ICP-Brasil (PFX)',
        'pfx_password'     => 'Senha do Certificado',
        'certificate_hint' => 'Leia o certificado para liberar o restante da configuração e salvar tudo em uma única vez.',
        'item_lista'       => 'Item da Lista de Serviços (LC 116)',
        'item_lista_hint'  => 'Selecione um item oficial da LC 116',
        'aliquota'         => 'Alíquota ISS (%)',
    ],
];

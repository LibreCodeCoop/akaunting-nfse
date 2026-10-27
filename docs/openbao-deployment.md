<!--
SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Implantação opcional do OpenBao para NFS-e

O módulo NFS-e utiliza armazenamento de segredos KV v2 para proteger senhas
de certificados digitais. OpenBao e HashiCorp Vault são alternativas.
Nenhum deles faz parte do runtime obrigatório do Akaunting. Quando já houver
um serviço de segredos disponível, prefira reutilizá-lo.

Este repositório oferece um exemplo **opcional e independente** de OpenBao.
Ele não instala o Akaunting, não altera o Compose da aplicação e não publica
a porta do OpenBao no host.

## Arquivos do exemplo

- `deploy/openbao/compose.yml`: serviço OpenBao, rede interna, persistência
  PebbleDB, restrições de segurança e verificação de saúde.
- `deploy/openbao/openbao.hcl`: configuração do serviço e desbloqueio
  automático por chave estática.
- `scripts/bootstrap-openbao.sh`: inicialização exclusiva de uma
  **instalação nova**, com KV v2, política de acesso e AppRole para NFS-e.

O exemplo utiliza o padrão de armazenamento da LibreCode:

- `volumes/openbao/data/`: dados persistentes do OpenBao;
- `volumes/openbao/seal.key`: chave estática de desbloqueio.

O diretório `volumes/` inteiro é ignorado pelo Git. Não mova esses arquivos
para pastas versionadas.

## Instalação nova

Na raiz do repositório, em um terminal interativo:

```sh
sudo bash scripts/bootstrap-openbao.sh
docker compose -f deploy/openbao/compose.yml ps
docker compose -f deploy/openbao/compose.yml exec -T openbao bao status
```

O script requer privilégios para preparar os dados e a chave de desbloqueio
para o UID 100, utilizado pelo contêiner OpenBao. O arquivo de chave tem
permissão de leitura exclusiva para esse usuário. O script recusa executar
quando encontra armazenamento existente ou contêineres do mesmo projeto
Compose, evitando sobrescrever uma instalação em operação.
**Não execute esse script em um servidor OpenBao já inicializado.**
Para uma instalação existente, configure o módulo para usar o serviço atual.

O OpenBao fica disponível apenas na rede Docker interna `nfse-openbao`,
pelo endereço `http://openbao:8200`. O runtime PHP do Akaunting precisa
participar dessa rede por configuração própria de implantação. **O diagnóstico
administrativo atual do módulo exige HTTPS e não aceita esse endereço HTTP.**
Antes de utilizar o exemplo com essa interface, disponibilize um endpoint TLS
com certificado confiável ao runtime PHP e configure o endereço HTTPS.
Não desabilite a validação TLS para contornar essa exigência.
Quando o serviço de segredos for externo, utilize igualmente HTTPS.

Após a inicialização, guarde a chave de recuperação, o token administrativo
inicial e as credenciais AppRole em local seguro. Configure o módulo pela
interface administrativa, conforme a
[documentação operacional](operacao-e-configuracao.md). Não inclua tokens,
Role ID, Secret ID ou chaves em comandos publicados, logs de CI ou issues.

## Segurança e recuperação

O desbloqueio automático com chave estática pressupõe confiança no host
Docker. O comprometimento simultâneo do armazenamento e da chave pode expor
os segredos. Proteja os backups de dados e da chave separadamente e mantenha
o material de recuperação fora do host.

O exemplo usa HTTP **somente dentro da rede Docker isolada**. Não exponha
essa porta à Internet nem reutilize a configuração sem TLS para conexões
externas.

Caso a inicialização falhe, examine os dados existentes antes de qualquer
nova tentativa. Não reinicialize o armazenamento nem gere outra chave para
uma instalação existente. A recuperação deve utilizar backups verificados
do armazenamento e da chave correspondente.

## Testes automatizados

A suíte `tests/bats/bootstrap-openbao.bats` verifica o bootstrap com Docker
simulado e armazenamento temporário isolado, sem iniciar serviços ou gerar
credenciais reais. Ela cobre proteção contra reinicialização, configuração
de KV v2 e AppRole, permissões do armazenamento, falhas de inicialização,
transporte de tokens e recuperação após reinício. A CI executa essa suíte
com Bats e verifica o ShellCheck e a configuração do Compose.

Para executar localmente, com Bats instalado:

```sh
bats tests/bats/bootstrap-openbao.bats
```

Os testes automatizados não substituem uma homologação real de conectividade
TLS e persistência de certificados no ambiente de destino.

O [runtime Docker genérico](https://github.com/LibreCodeCoop/akaunting-docker)
continua responsável pelo Akaunting, PHP, Nginx e banco de dados. O exemplo
de OpenBao não realiza emissões fiscais nem testes de transmissão de NFS-e.

<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Modules\Nfse\Support;

final class Lc116Catalog
{
    /**
     * @var array<int, array{code: string, display_code: string, description: string}>
     */
    private const ITEMS = [
        ['code' => '0101', 'display_code' => '1.01', 'description' => 'Analise e desenvolvimento de sistemas'],
        ['code' => '0102', 'display_code' => '1.02', 'description' => 'Programacao'],
        ['code' => '0103', 'display_code' => '1.03', 'description' => 'Processamento de dados e congeneres'],
        ['code' => '0104', 'display_code' => '1.04', 'description' => 'Elaboracao de programas de computadores, inclusive jogos eletronicos'],
        ['code' => '0105', 'display_code' => '1.05', 'description' => 'Licenciamento ou cessao de direito de uso de programas de computacao'],
        ['code' => '0106', 'display_code' => '1.06', 'description' => 'Assessoria e consultoria em informatica'],
        ['code' => '0107', 'display_code' => '1.07', 'description' => 'Suporte tecnico em informatica, inclusive instalacao, configuracao e manutencao'],
        ['code' => '0108', 'display_code' => '1.08', 'description' => 'Planejamento, confeccao, manutencao e atualizacao de paginas eletronicas'],
        ['code' => '0201', 'display_code' => '2.01', 'description' => 'Servicos de pesquisas e desenvolvimento de qualquer natureza'],
        ['code' => '0302', 'display_code' => '3.02', 'description' => 'Cessao de direito de uso de marcas e de sinais de propaganda'],
        ['code' => '0303', 'display_code' => '3.03', 'description' => 'Exploracao de saloes de festas e estruturas para eventos'],
        ['code' => '0304', 'display_code' => '3.04', 'description' => 'Locacao e compartilhamento de ferrovia, rodovia, postes, cabos e dutos'],
        ['code' => '0305', 'display_code' => '3.05', 'description' => 'Cessao de andaimes, palcos, coberturas e estruturas temporarias'],
        ['code' => '0401', 'display_code' => '4.01', 'description' => 'Medicina e biomedicina'],
        ['code' => '0402', 'display_code' => '4.02', 'description' => 'Analises clinicas e diagnosticos por imagem'],
        ['code' => '0403', 'display_code' => '4.03', 'description' => 'Hospitais, clinicas, laboratorios e congeneres'],
        ['code' => '0404', 'display_code' => '4.04', 'description' => 'Instrumentacao cirurgica'],
        ['code' => '0405', 'display_code' => '4.05', 'description' => 'Acupuntura'],
        ['code' => '0406', 'display_code' => '4.06', 'description' => 'Enfermagem, inclusive servicos auxiliares'],
        ['code' => '0407', 'display_code' => '4.07', 'description' => 'Servicos farmaceuticos'],
        ['code' => '0408', 'display_code' => '4.08', 'description' => 'Terapia ocupacional, fisioterapia e fonoaudiologia'],
        ['code' => '0409', 'display_code' => '4.09', 'description' => 'Terapias destinadas ao tratamento fisico, organico e mental'],
        ['code' => '0410', 'display_code' => '4.10', 'description' => 'Nutricao'],
        ['code' => '0411', 'display_code' => '4.11', 'description' => 'Obstetricia'],
        ['code' => '0412', 'display_code' => '4.12', 'description' => 'Odontologia'],
        ['code' => '0413', 'display_code' => '4.13', 'description' => 'Ortotica'],
        ['code' => '0414', 'display_code' => '4.14', 'description' => 'Proteses sob encomenda'],
        ['code' => '0415', 'display_code' => '4.15', 'description' => 'Psicanalise'],
        ['code' => '0416', 'display_code' => '4.16', 'description' => 'Psicologia'],
        ['code' => '0417', 'display_code' => '4.17', 'description' => 'Casas de repouso e recuperacao, creches e asilos'],
        ['code' => '0418', 'display_code' => '4.18', 'description' => 'Inseminacao artificial e fertilizacao in vitro'],
        ['code' => '0419', 'display_code' => '4.19', 'description' => 'Bancos de sangue, leite, pele, olhos, ovulos e semen'],
        ['code' => '0420', 'display_code' => '4.20', 'description' => 'Coleta de sangue, leite, tecidos, orgaos e materiais biologicos'],
        ['code' => '0421', 'display_code' => '4.21', 'description' => 'Unidade de atendimento, assistencia ou tratamento movel'],
        ['code' => '0422', 'display_code' => '4.22', 'description' => 'Planos de medicina de grupo ou individual e convenios'],
        ['code' => '0423', 'display_code' => '4.23', 'description' => 'Outros planos de saude por rede contratada ou credenciada'],
        ['code' => '0501', 'display_code' => '5.01', 'description' => 'Medicina veterinaria e zootecnia'],
        ['code' => '0601', 'display_code' => '6.01', 'description' => 'Barbearia, cabeleireiros, manicuros, pedicuros e congeneres'],
        ['code' => '0701', 'display_code' => '7.01', 'description' => 'Engenharia, agronomia, arquitetura, geologia, urbanismo e paisagismo'],
        ['code' => '0801', 'display_code' => '8.01', 'description' => 'Ensino regular pre-escolar, fundamental, medio e superior'],
        ['code' => '0901', 'display_code' => '9.01', 'description' => 'Hospedagem de qualquer natureza em hoteis e congeneres'],
        ['code' => '1001', 'display_code' => '10.01', 'description' => 'Agenciamento, corretagem ou intermediacao de cambio, seguros e cartoes'],
        ['code' => '1101', 'display_code' => '11.01', 'description' => 'Guarda e estacionamento de veiculos, aeronaves e embarcacoes'],
        ['code' => '1201', 'display_code' => '12.01', 'description' => 'Espetaculos teatrais'],
        ['code' => '1302', 'display_code' => '13.02', 'description' => 'Fonografia ou gravacao de sons, inclusive dublagem e mixagem'],
        ['code' => '1401', 'display_code' => '14.01', 'description' => 'Lubrificacao, limpeza, revisao e manutencao de maquinas e veiculos'],
        ['code' => '1501', 'display_code' => '15.01', 'description' => 'Administracao de fundos, consorcios, cartoes e carteira de clientes'],
        ['code' => '1601', 'display_code' => '16.01', 'description' => 'Servicos de transporte de natureza municipal'],
        ['code' => '1701', 'display_code' => '17.01', 'description' => 'Assessoria ou consultoria de qualquer natureza'],
        ['code' => '1706', 'display_code' => '17.06', 'description' => 'Propaganda e publicidade'],
        ['code' => '1719', 'display_code' => '17.19', 'description' => 'Contabilidade, inclusive servicos tecnicos e auxiliares'],
        ['code' => '1801', 'display_code' => '18.01', 'description' => 'Regulacao de sinistros e avaliacao de riscos para seguros'],
        ['code' => '1901', 'display_code' => '19.01', 'description' => 'Distribuicao e venda de bilhetes e produtos de loteria'],
        ['code' => '2001', 'display_code' => '20.01', 'description' => 'Servicos portuarios e ferroportuarios'],
        ['code' => '2101', 'display_code' => '21.01', 'description' => 'Servicos de registros publicos, cartorarios e notariais'],
        ['code' => '2201', 'display_code' => '22.01', 'description' => 'Servicos de exploracao de rodovia'],
        ['code' => '2301', 'display_code' => '23.01', 'description' => 'Servicos de programacao e comunicacao visual'],
        ['code' => '2401', 'display_code' => '24.01', 'description' => 'Servicos de chaveiros, carimbos, placas e sinalizacao visual'],
        ['code' => '2501', 'display_code' => '25.01', 'description' => 'Funerais, aluguel de capela e servicos correlatos'],
        ['code' => '2601', 'display_code' => '26.01', 'description' => 'Coleta, remessa e entrega de correspondencias e valores'],
        ['code' => '2701', 'display_code' => '27.01', 'description' => 'Servicos de assistencia social'],
        ['code' => '2801', 'display_code' => '28.01', 'description' => 'Servicos de avaliacao de bens e servicos'],
        ['code' => '2901', 'display_code' => '29.01', 'description' => 'Servicos de biblioteconomia'],
        ['code' => '3001', 'display_code' => '30.01', 'description' => 'Servicos de biologia, biotecnologia e quimica'],
        ['code' => '3101', 'display_code' => '31.01', 'description' => 'Servicos tecnicos em edificacoes, eletronica e telecomunicacoes'],
        ['code' => '3201', 'display_code' => '32.01', 'description' => 'Servicos de desenhos tecnicos'],
        ['code' => '3301', 'display_code' => '33.01', 'description' => 'Servicos de desembaraco aduaneiro e despachantes'],
        ['code' => '3401', 'display_code' => '34.01', 'description' => 'Servicos de investigacoes particulares e detetives'],
        ['code' => '3501', 'display_code' => '35.01', 'description' => 'Servicos de reportagem, assessoria de imprensa e relacoes publicas'],
        ['code' => '3601', 'display_code' => '36.01', 'description' => 'Servicos de meteorologia'],
        ['code' => '3701', 'display_code' => '37.01', 'description' => 'Servicos de artistas, atletas, modelos e manequins'],
        ['code' => '3801', 'display_code' => '38.01', 'description' => 'Servicos de museologia'],
        ['code' => '3901', 'display_code' => '39.01', 'description' => 'Servicos de ourivesaria e lapidacao'],
        ['code' => '4001', 'display_code' => '40.01', 'description' => 'Servicos relativos a obras de arte sob encomenda'],
    ];

    /**
     * @return array<int, array{code: string, display_code: string, description: string, label: string}>
     */
    public function search(?string $query = null, int $limit = 200): array
    {
        $normalizedQuery = mb_strtolower(trim((string) $query));

        $items = array_filter(self::ITEMS, static function (array $item) use ($normalizedQuery): bool {
            if ($normalizedQuery == '') {
                return true;
            }

            $haystack = mb_strtolower($item['display_code'] . ' ' . $item['description'] . ' ' . $item['code']);

            return str_contains($haystack, $normalizedQuery);
        });

        $items = array_slice(array_values($items), 0, max(1, $limit));

        return array_map(static fn (array $item): array => [
            'code' => $item['code'],
            'display_code' => $item['display_code'],
            'description' => $item['description'],
            'label' => $item['display_code'] . ' - ' . $item['description'],
        ], $items);
    }
}

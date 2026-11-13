<?php

namespace App\Actions\Maintenance;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * manutencao/unificar* (legado, DmfUtil::unirNavios/unirArmador/migrate/unirPaises/unirEstados/unirCidades/unirPorto):
 * repassa tudo que aponta para as origens ao destino e apaga as origens. Lista fixa de tabelas, como no legado
 * (acrescida das FKs do banco que o legado não cobria); vw_transit_time é view e fica de fora.
 */
class MergeRecords
{
    /** @var array<string, array{table: string, key: string, references: array<string, list<string>>}> */
    public const ENTITIES = [
        'ship' => [
            'table' => 'navio',
            'key' => 'navio_id',
            'references' => [
                'embarque' => ['navio_id', 'navio_id_destino'],
                'embarque_rota' => ['navio_id'],
                'hist_embarque' => ['navio_id'],
                'hist_navio' => ['navio_id'],
                'programacao_navio' => ['navio_id'],
            ],
        ],
        'carrier' => [
            'table' => 'armador',
            'key' => 'armador_id',
            'references' => [
                'armador_arquivo_carga_perigosa' => ['armador_id'],
                'cmn_armador_porto' => ['ref_armador'],
                'controle_invoice_master' => ['armador_id'],
                'cotacao' => ['ref_armador'],
                'cotacao_codigo' => ['ref_armador'],
                'cotacao_expo' => ['armador_id'],
                'cotacao_impo' => ['armador_id', 'coloader_id'],
                'cotacao_xls_armador_despesa' => ['armador_id'],
                'cotacao_xls_item' => ['armador_id'],
                'cotacao_xls_observacao_item' => ['armador_id'],
                'deposito_garantia' => ['ref_armador'],
                'embarque' => ['armador_id', 'coloader_id'],
                'fin_armador_banks' => ['ref_armador'],
                'fin_armador_demurrage_values' => ['ref_armador'],
                'fin_armador_detention_values' => ['ref_armador'],
                'fin_currencies_rates_armador' => ['ref_armador'],
                'fin_currencies_rates_armador_payment' => ['ref_armador'],
                'fin_payment_orders' => ['ref_armador'],
                'fin_payment_process' => ['ref_armador'],
                'hist_armador' => ['armador_id'],
                'matriz_equipamento_armador' => ['armador_id'],
                'pessoa_armador' => ['armador_id'],
                'representante_armador' => ['armador_id'],
                'resumo_resultado' => ['armador_id'],
                'transit_time' => ['armador_id'],
            ],
        ],
        'company' => [
            'table' => 'pessoa',
            'key' => 'pessoa_id',
            'references' => [
                'agente_banco' => ['agente_id'],
                'agente_contato' => ['ref_pessoa'],
                'agente_contato_nota' => ['agente_id'],
                'agente_homologacao' => ['ref_agente'],
                'agente_obs' => ['pessoa_id'],
                'agente_observacao' => ['agente_id'],
                'agente_pais' => ['agente_id'],
                'agente_porto' => ['pessoa_id'],
                'capa_processo_manual' => ['cliente_id'],
                'cmn_client_fup' => ['ref_pessoa'],
                'cmn_dados_bl' => ['ref_pessoa'],
                'cmn_group_member' => ['ref_pessoa'],
                'cotacao_expo' => ['pessoa_id'],
                'cotacao_impo' => ['pessoa_id', 'agente_id'],
                'cotacao_impo_aereo' => ['pessoa_id', 'agente_id'],
                'cotacao_seguro' => ['pessoa_id'],
                'cotacao_xls' => ['cliente_id'],
                'cotacao_xls_item' => ['agente_id'],
                'crm_agenda' => ['ref_pessoa'],
                'despachante_pessoa' => ['pessoa_id'],
                'destino_final' => ['pessoa_id'],
                'embarque' => ['cliente_id', 'pessoa_id_cliente', 'pessoa_id_importador', 'pessoa_id_notify', 'pessoa_id_pagador', 'pessoa_id_representante'],
                'embarque_shipper' => ['pessoa_id'],
                'enviareserva' => ['cliente_id'],
                'escritorio' => ['pessoa_id'],
                'fiducia_contratos' => ['cliente_id'],
                'fin_clients_boleto' => ['ref_client'],
                'fin_clients_exception' => ['ref_client'],
                'fin_clients_nfse_regra' => ['ref_client'],
                'fin_companies_rates' => ['ref_pessoa'],
                'fin_contato_cobranca' => ['ref_pessoa'],
                'fin_credit_notes' => ['ref_agente'],
                'fin_debit_notes' => ['ref_agente'],
                'fu_process' => ['ref_pessoa'],
                'historico_pessoa' => ['pessoa_id'],
                'matriz_despesa_pessoa' => ['pessoa_id'],
                'pessoa_aplicacao' => ['pessoa_id'],
                'pessoa_armador' => ['pessoa_id'],
                'pessoa_assessoria' => ['pessoa_id'],
                'pessoa_contato_assessoria' => ['pessoa_id'],
                'pessoa_contato_despachante' => ['pessoa_id'],
                'pessoa_contato_trading' => ['pessoa_id'],
                'pessoa_despachante' => ['pessoa_id'],
                'pessoa_sede' => ['pessoa_id'],
                'pessoa_tipo_pessoa' => ['pessoa_id'],
                'pessoa_trading' => ['pessoa_id'],
                'pessoa_usuario' => ['pessoa_id'],
                'resumo_resultado' => ['cliente_id'],
                'sales_lead' => ['pessoa_id', 'agente_id'],
                'transit_time' => ['pessoa_id'],
            ],
        ],
        'country' => [
            'table' => 'paises',
            'key' => 'paises_id',
            'references' => [
                'agente_pais' => ['pais_id'],
                'destino_final' => ['pais_id'],
                'estados' => ['paises_id'],
                'fin_expenses_matrix' => ['pais_origem_id'],
                'instrucao_especial' => ['paises_id'],
                'pessoa' => ['paises_id'],
                'porto' => ['ref_pais'],
                'resumo_resultado' => ['pais_destino_id', 'pais_origem_id'],
            ],
        ],
        'state' => [
            'table' => 'estados',
            'key' => 'estados_id',
            'references' => [
                'cidades' => ['estados_id'],
                'controle_correio' => ['estados_id'],
                'destino_final' => ['estado_id'],
                'hist_cidades' => ['estados_id'],
                'hist_estados' => ['estados_id'],
                'porto' => ['ref_estado'],
                'sedes' => ['estado_id'],
                'transportadora_estado' => ['estado_id'],
            ],
        ],
        'city' => [
            'table' => 'cidades',
            'key' => 'cidades_id',
            'references' => [
                'destino_final' => ['cidade_id'],
                'hist_cidades' => ['cidades_id'],
                'hist_pessoa' => ['cidades_id'],
                'hist_porto' => ['cidades_id'],
                'hist_usuario' => ['cidades_id'],
                'pessoa' => ['cidades_id'],
                'porto' => ['cidades_id'],
                'usuario' => ['cidades_id'],
            ],
        ],
        'port' => [
            'table' => 'porto',
            'key' => 'porto_id',
            'references' => [
                'agente_contato' => ['porto_id'],
                'agente_pais_porto' => ['porto_id'],
                'agente_porto' => ['porto_id'],
                'cmn_armador_porto' => ['ref_porto'],
                'cotacao' => ['ref_porto_origem', 'ref_porto_destino'],
                'cotacao_codigo' => ['ref_porto_origem', 'ref_porto_destino'],
                'cotacao_expo' => ['porto_origem_id', 'porto_destino_id'],
                'cotacao_impo' => ['porto_origem_id', 'porto_destino_id'],
                'cotacao_impo_aereo' => ['porto_origem_id', 'porto_destino_id'],
                'cotacao_seguro' => ['porto_origem_id', 'porto_destino_id'],
                'cotacao_xls_armador_despesa' => ['porto_id'],
                'cotacao_xls_item' => ['porto_origem_id', 'porto_destino_id'],
                'despachante_porto' => ['id_porto'],
                'embarque' => ['porto_origem_id', 'porto_destino_id', 'porto_descarga', 'place_of_delivery_id', 'place_of_receipt_id'],
                'embarque_rota' => ['porto_origem_id', 'porto_destino_id'],
                'local_retirada' => ['porto_id'],
                'matriz_despesa_pessoa' => ['porto_id'],
                'programacao_navio' => ['porto_origem_id', 'porto_destino_id'],
                'resumo_resultado' => ['porto_origem_id', 'porto_destino_id'],
                'transborda' => ['porto_id'],
                'transit_time' => ['porto_origem_id', 'porto_destino_id'],
            ],
        ],
    ];

    /**
     * @param  list<int|string>  $sourceIds
     * @return int quantos registros de origem foram unificados
     */
    public function __invoke(string $entity, array $sourceIds, int $targetId): int
    {
        $config = self::ENTITIES[$entity] ?? throw new InvalidArgumentException("Entidade desconhecida: {$entity}");

        $sources = array_values(array_diff(array_map('intval', $sourceIds), [$targetId]));

        if ($sources === []) {
            return 0;
        }

        DB::transaction(function () use ($config, $sources, $targetId) {
            $in = implode(',', array_fill(0, count($sources), '?'));
            $ignore = DB::getDriverName() === 'sqlite' ? 'OR IGNORE' : 'IGNORE';
            $grammar = DB::getQueryGrammar();

            foreach ($config['references'] as $table => $columns) {
                foreach ($columns as $column) {
                    $wrapped = $grammar->wrap($column);

                    // UPDATE IGNORE: se o destino já tem o mesmo vínculo (unique), a linha da origem fica e é descartada abaixo
                    DB::update("UPDATE {$ignore} {$grammar->wrapTable($table)} SET {$wrapped} = ? WHERE {$wrapped} IN ({$in})", [$targetId, ...$sources]);
                    DB::table($table)->whereIn($column, $sources)->delete();
                }
            }

            DB::table($config['table'])->whereIn($config['key'], $sources)->delete();
        });

        return count($sources);
    }
}

<?php

namespace App\Actions\Booking\Creation;

use Illuminate\Support\Facades\DB;

/**
 * "Importar dados da referência" (legado): vinculaCotacaoImpo, vinculaCotacaoImpoAereo e booking/createExpo?cotacao_expo=.
 * Só preenche o formulário; os custos da cotação continuam na ação "importar custos" da aba Despesas.
 */
class QuotationPrefill
{
    /** @return array{0: string, 1: string}|null [tabela da cotação, coluna na reserva] */
    public static function source(string $operation, string $mode): ?array
    {
        return match ($operation.$mode) {
            'im' => ['cotacao_impo', 'cotacao_impo_id'],
            'ia' => ['cotacao_impo_aereo', 'cotacao_impo_aereo_id'],
            'em' => ['cotacao_expo', 'cotacao_expo_id'],
            default => null,
        };
    }

    public function __construct(private BookingParties $parties) {}

    /** @return array<string, mixed> campos do formulário, com os contatos padrão do cliente e as mercadorias da cotação */
    public function __invoke(string $operation, string $mode, int $quotationId): array
    {
        [$table, $column] = self::source($operation, $mode);
        $q = DB::table($table)->where($column, $quotationId)->first();

        if (! $q) {
            return [];
        }

        $fields = [
            'vendedor_id' => $q->vendedor_id,
            'cliente_id' => $q->pessoa_id,
            'porto_origem_id' => $q->porto_origem_id,
            'porto_destino_id' => $q->porto_destino_id,
            'contacts.cliente' => $this->parties->defaultContacts($q->pessoa_id, $operation),
        ];

        if ($operation === 'e') {
            $author = DB::table('usuario')->where('usuario_id', $q->created_by)->first();

            return [
                ...$fields,
                'armador_id' => $q->armador_id,
                'via' => $q->transbordo,
                'data_validade' => $q->validade,
                'de' => $author?->nome,
                'place_of_receipt' => $q->place_of_receipt,
                'destino_final_id' => $q->destino_final_id,
                'free_time_detention' => DB::table('armador')->where('armador_id', $q->armador_id)->value('free_time_padrao'),
            ];
        }

        $fields += [
            'incoterm_id' => $q->incoterm_id,
            'pessoa_id_representante' => $q->agente_id,
            'data_topo' => today()->toDateString(),
            // getDespachanteDmf: o despachante padrão do porto de destino.
            'despachante_dmf_id' => DB::table('porto')->where('porto_id', $q->porto_destino_id)->value('despachante_id'),
        ];

        if ($mode === 'a') {
            return [
                ...$fields,
                'ref_cia_aerea' => $q->cia_aerea_id,
                'via' => $q->rota,
                'data_validade' => $q->validade,
                'invoice' => $q->fatura,
                'peso_cotacao' => $q->peso,
                'volume_cotacao' => $q->volume,
            ];
        }

        return [
            ...$fields,
            'armador_id' => $q->armador_id,
            'coloader_id' => $q->coloader_id,
            'customer_ref' => $q->fatura,
            'via' => $q->transbordo,
            'free_time' => $q->free_time,
            'data_validade' => $q->validade_max,
            'goods' => DB::table('cotacao_impo_container')->where('cotacao_impo_id', $quotationId)->orderBy('cotacao_impo_container_id')->get()
                ->unique('tipo_container_id')
                ->map(fn (object $c) => [
                    'tipo_container_id' => $c->tipo_container_id,
                    'quantidade' => $c->quantidade ?: 1,
                    'quimico' => (int) $c->flag_imo === 1 ? 1 : 0,
                    'carga_perigosa' => (int) $c->flag_imo === 1 ? 1 : 0,
                ])->values()->all(),
        ];
    }
}

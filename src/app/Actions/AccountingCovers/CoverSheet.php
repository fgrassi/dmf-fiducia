<?php

namespace App\Actions\AccountingCovers;

use App\Models\CapaProcesso;
use App\Models\Embarque;
use Illuminate\Support\Facades\DB;

/**
 * Dados do pdfCapaContabilidade (legado): cabeçalho da reserva, linhas e totais de despesas e receitas, e a lucratividade.
 *
 * Diferenças do legado: mês/ano em branco quando a capa não tem data (o legado imprime JANEIRO/1970) e maiúsculas
 * corretas nos acentos (MARÇO, MARÍTIMO).
 */
class CoverSheet
{
    public const MONTHS = [1 => 'JANEIRO', 'FEVEREIRO', 'MARÇO', 'ABRIL', 'MAIO', 'JUNHO', 'JULHO', 'AGOSTO', 'SETEMBRO', 'OUTUBRO', 'NOVEMBRO', 'DEZEMBRO'];

    private const TYPES = ['m' => 'Marítimo', 'a' => 'Aéreo', 's' => 'Seguro'];

    /** @return array<string, mixed> */
    public function __invoke(CapaProcesso $cover): array
    {
        $booking = Embarque::findOrFail($cover->embarque_id);
        $symbol = (string) DB::table('cmn_currencies')->where('id', $cover->moeda_id)->value('symbol');
        $export = $booking->impor_expor === 'e';

        $expenses = DB::table('capa_processo_despesa_item')->where('capa_processo_id', $cover->getKey())->orderBy('capa_processo_despesa_item_id')->get()
            ->map(fn ($i) => [$i->nome, (float) $i->valor])->all();
        $expenses = [...$expenses, ...$this->extra('DESCONTO FIDUCIA', $cover->valor_fiducia), ...$this->extra('IOF', $cover->valor_iof_compra)];

        $revenues = DB::table('capa_processo_receita_item')->where('capa_processo_id', $cover->getKey())->orderBy('capa_processo_receita_item_id')->get()
            ->map(fn ($i) => [$i->nome, (float) $i->valor])->all();
        $revenues = [
            ...$revenues,
            ...$this->extra('IOF', $cover->valor_iof),
            ...$this->extra('Desconto', $cover->desconto, discount: true),
            ...$this->extra('Juros', $cover->valor_juros),
            ...$this->extra('Taxa de emissão do boleto', $cover->valor_tax_boleto),
            ...$this->extra('Multa', $cover->valor_multa),
        ];

        $totalExpenses = array_sum(array_column($expenses, 1));
        $totalRevenues = array_sum(array_map(fn (array $r) => $r[2] ?? false ? -$r[1] : $r[1], $revenues));
        $abroad = (float) $cover->receitas;
        $commission = (float) $cover->comissoes;
        $financialCost = abs((float) $cover->custo_financeiro);
        $conversion = ($abroad + $commission) * (float) $cover->valor_cotacao;
        $brazil = $totalRevenues - $totalExpenses;

        return [
            'reminder' => $booking->house_bl === 'BL DIRETO' ? 'BL DIRETO' : mb_strtoupper(self::TYPES[$booking->tipo_embarque] ?? ''),
            'party_label' => $export ? 'Exportador:' : 'Importador',
            'party' => (string) DB::table('pessoa')->where('pessoa_id', $export ? $booking->cliente_id : $booking->pessoa_id_importador)->value('nome'),
            'bl' => (string) $booking->house_bl,
            'office' => (string) DB::table('vendedor as v')->leftJoin('sedes as s', 's.id', '=', 'v.sede_id')->where('v.vendedor_id', $booking->vendedor_id)->value('s.nome'),
            'seller' => (string) DB::table('vendedor')->where('vendedor_id', $booking->vendedor_id)->value('nome'),
            'operation' => $export ? 'EXPO' : 'IMPO',
            'month_year' => $cover->payment_date ? self::MONTHS[$cover->payment_date->month].'/'.$cover->payment_date->year : '',
            'number' => (string) $cover->numero,
            'reference' => $export && ($notes = $this->noteNames($booking)) ? $notes : (string) $booking->booking,
            'expenses' => array_map(fn (array $r) => [$r[0], $this->money($r[1])], $expenses),
            'revenues' => array_map(fn (array $r) => [$r[0], ($r[2] ?? false) ? '('.$this->money($r[1]).')' : $this->money($r[1])], $revenues),
            'total_expenses' => $this->money($totalExpenses),
            'total_revenues' => $this->money($totalRevenues),
            'difference' => $this->money($brazil),
            'abroad_label' => $abroad >= 0 ? 'Receitas recebidas do exterior' : 'Receitas a serem pagas ao exterior',
            'abroad' => $symbol.' '.$this->number($abroad),
            'commission' => $symbol.' '.$this->number($commission),
            'financial_cost' => '- '.$this->money($financialCost),
            'foreign_total' => $symbol.' '.$this->number($abroad + $commission),
            'conversion' => $this->money($conversion),
            'result' => $this->money($conversion + $brazil - $financialCost),
        ];
    }

    /** @return list<array{0: string, 1: float, 2?: bool}> */
    private function extra(string $name, mixed $value, bool $discount = false): array
    {
        return (float) $value > 0 ? [[$name, (float) $value, $discount]] : [];
    }

    /** Nomes das notas de crédito e de débito da reserva, separados por " - ". */
    private function noteNames(Embarque $booking): string
    {
        return collect(['fin_credit_notes', 'fin_debit_notes'])
            ->flatMap(fn (string $table) => DB::table($table)->where('ref_embarque', $booking->getKey())->orderBy('id')->pluck('name'))
            ->implode(' - ');
    }

    private function money(float $value): string
    {
        return 'R$ '.$this->number($value);
    }

    private function number(float $value): string
    {
        return number_format($value, 2, ',', '.');
    }
}

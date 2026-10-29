<?php

namespace App\Actions\Booking;

use App\Models\FinExpenses;
use Illuminate\Support\Facades\DB;

/**
 * setRastreamentoDespesas (legado): grava no rastreamento da reserva o que mudou na despesa
 * ou, sem versão anterior (cadastro e exclusão), tudo o que ela tem.
 */
class ExpenseTracking
{
    public const BILLING = ['p' => 'PP', 'a' => 'PA', 'c' => 'CC', 'f' => 'FI'];

    private const SUBJECTS = ['u' => 'Atualizado', 'd' => 'Apagado'];

    /** @param  ?array<string, mixed>  $previous  atributos da despesa antes da alteração */
    public function __invoke(array $expense, string $event, ?array $previous, int $userId): void
    {
        DB::table('rastreamento')->insert([
            'assunto' => self::SUBJECTS[$event].': Despesa',
            'historico' => '<h3>Alterações na Despesa</h3>'.($previous ? $this->changes($previous, $expense) : $this->summary($expense)),
            'ref_embarque' => $expense['ref_embarque'],
            'evento' => $event,
            'created_at' => now()->toDateString(),
            'created_time' => now()->format('H:i:s'),
            'created_by' => $userId,
        ]);
    }

    /**
     * Só os campos que mudaram, comparando como aparecem no histórico (null e "0" são ambos "NAO").
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function changes(array $old, array $new): string
    {
        $yesNo = fn (string $column) => fn ($a) => ($a[$column] ?? null) == '1' ? ' SIM ' : 'NAO';
        $lookup = fn (string $column, string $table, string $key, string $name) => fn ($a) => $this->lookup($table, $key, $name, $a[$column] ?? null);
        $money = fn (string $column) => fn ($a) => number_format((float) ($a[$column] ?? 0), 2, ',', '.');

        $fields = [
            'Despesa' => fn ($a) => $this->expenseName($a),
            'Equipamento' => $lookup('ref_equipment', 'tipo_container', 'tipo_container_id', 'nome'),
            'Due To' => $lookup('ref_due_to', 'fin_expenses_values_due_to', 'id', 'name'),
            'Tipo' => fn ($a) => self::BILLING[$a['billing'] ?? ''] ?? '',
            'BL' => $lookup('ref_bl', 'bl', 'bl_id', 'bl'),
            'Container' => $lookup('ref_container', 'container', 'container_id', 'numero'),
            'Moeda de Compra' => fn ($a) => $this->symbol($a['ref_currency_br'] ?? null),
            'Valor Compra' => $money('buying_rate'),
            'Mostrar B/R na S.I' => $yesNo('ctrl'),
            'Moeda de Venda' => fn ($a) => $this->symbol($a['ref_currency_sr'] ?? null),
            'Valor Venda' => $money('selling_rate'),
            'Mostrar S/R na S.I' => $yesNo('ctrl2'),
            'Mostrar no Booking' => $yesNo('ctrl3'),
            'Mostrar As per agreement' => $yesNo('agreement'),
            'Incluso no Frete' => $yesNo('all_in'),
            'Despesa EXW' => $yesNo('flag_exw'),
            'Informar Agente' => $yesNo('mostra_agente'),
        ];

        $html = '';

        foreach ($fields as $label => $describe) {
            if ($describe($old) !== $describe($new)) {
                $html .= "<p>{$label}: DE: {$describe($old)} PARA: {$describe($new)}</p>";
            }
        }

        return $html;
    }

    private function summary(array $a): string
    {
        $yesNo = fn ($flag) => $flag == '1' ? ' SIM ' : 'NÃO';
        $money = fn ($currency, $rate) => $this->symbol($currency).number_format((float) $rate, 2, ',', '.');
        $dueTo = $this->lookup('fin_expenses_values_due_to', 'id', 'name', $a['ref_due_to'] ?? null);

        return '<p>Despesa: '.$this->expenseName($a).'</p>'
            .'<p>Equipamento: '.$this->lookup('tipo_container', 'tipo_container_id', 'nome', $a['ref_equipment'] ?? null).'</p>'
            .($dueTo !== '' ? "<p>Due To: {$dueTo}</p>" : '')
            .'<p>Tipo: '.(self::BILLING[$a['billing'] ?? ''] ?? '').'</p>'
            .'<p>BL: '.$this->lookup('bl', 'bl_id', 'bl', $a['ref_bl'] ?? null).'</p>'
            .'<p>Container: '.$this->lookup('container', 'container_id', 'numero', $a['ref_container'] ?? null).'</p>'
            .'<p>B/R: '.$money($a['ref_currency_br'] ?? null, $a['buying_rate'] ?? 0).'</p>'
            .'<p>S.I(B/R): '.$yesNo($a['ctrl'] ?? null).' </p>'
            .'<p>S/R: '.$money($a['ref_currency_sr'] ?? null, $a['selling_rate'] ?? 0).'</p>'
            .'<p>S.I(S/R): '.$yesNo($a['ctrl2'] ?? null).' </p>'
            .'<p>Billing: '.(self::BILLING[$a['billing'] ?? ''] ?? '').' </p>'
            .'<p>Agreement: '.(($a['agreement'] ?? null) == '1' ? ' SIM' : 'NÃO').' </p>'
            .'<p>Booking: '.$yesNo($a['ctrl3'] ?? null).' </p>';
    }

    private function expenseName(array $a): string
    {
        return (string) FinExpenses::find($a['ref_expense'] ?? null)?->label;
    }

    private function symbol(mixed $id): string
    {
        return $this->lookup('cmn_currencies', 'id', 'symbol', $id);
    }

    private function lookup(string $table, string $key, string $column, mixed $id): string
    {
        return $id ? (string) DB::table($table)->where($key, $id)->value($column) : '';
    }
}

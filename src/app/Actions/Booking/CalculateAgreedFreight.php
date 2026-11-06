<?php

namespace App\Actions\Booking;

use App\Models\Embarque;
use App\Models\FinExpensesValues;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * booking/addValorFrete (legado, "Calcular Frete" e "Frete acordado"): monta o texto do valor do House BL a partir das
 * despesas marcadas e o grava em valor_frete_embarque (um registro por reserva; refazer apaga o anterior).
 *
 * Só importação. LCL: W/M vale o maior entre venda × fator e a venda mínima; BL e Processo, a venda. FCL: a venda de cada
 * despesa (com o equipamento quando é por container). Aéreo: só W/M, venda × fator. As despesas obrigatórias eram validadas
 * no navegador (general.js). Refazer exige justificativa e avisa a documentação por e-mail.
 *
 * Diferenças do legado, por decisão: no aéreo o fator entra arredondado em 2 casas (o legado o formatava com separador de
 * milhar e, a partir de 1.000, multiplicava por 1), e a validação da função aérea não depende da variável `tipo`, que ali
 * nunca existia.
 */
class CalculateAgreedFreight
{
    private const UNIT_BL = 1;

    private const UNIT_CONTAINER = 2;

    private const UNIT_WEIGHT_MEASURE = 3;

    private const UNIT_PROCESS = 5;

    private const LCL_CONTAINER_TYPE = 20;

    private const LCL_FREIGHT = [200, 41];

    private const LCL_EBS = 291;

    private const LCL_CAPATAZIA = [43, 463];

    private const LCL_THC = [36, 639, 993, 1346, 1450];

    private const FCL_FREIGHT = [1, 200];

    private const AIR_FREIGHT = 73;

    private const NOTICE_TO = ['Documentação', 'docimportacao@dmflog.com.br'];

    /**
     * @param  list<int>  $expenseIds  ids de fin_expenses_values marcadas
     *
     * @throws DomainException
     */
    public function __invoke(Embarque $booking, array $expenseIds, ?string $justification, int $userId): string
    {
        if ($booking->impor_expor !== 'i' || ! in_array($booking->tipo_embarque, ['m', 'a'], true)) {
            throw new DomainException('O cálculo do frete vale só para importação marítima ou aérea.');
        }

        $expenses = FinExpensesValues::with(['despesa.unity', 'equipment', 'sellingCurrency'])
            ->where('ref_embarque', $booking->getKey())
            ->whereIn('id', $expenseIds)
            ->orderBy('id')
            ->get();

        $this->ensureRequiredExpenses($booking, $expenses->pluck('ref_expense')->map(fn ($id) => (int) $id)->all());

        $previous = DB::table('valor_frete_embarque')->where('embarque_id', $booking->getKey())->exists();

        if ($previous && blank($justification)) {
            throw new DomainException('É necessário informar a justificativa!');
        }

        $value = $booking->tipo_embarque === 'a'
            ? $this->airValue($booking, $expenses)
            : ($this->isLcl($booking) ? $this->lclValue($booking, $expenses) : $this->fclValue($expenses));

        DB::transaction(function () use ($booking, $value, $justification, $userId) {
            DB::table('valor_frete_embarque')->where('embarque_id', $booking->getKey())->delete();
            DB::table('valor_frete_embarque')->insert([
                'embarque_id' => $booking->getKey(),
                'valor' => $value,
                'created_by' => $userId,
                'created_at' => today()->toDateString(),
                'justificativa' => $justification,
            ]);
        });

        if ($previous) {
            $this->notify($booking, $value, (string) $justification, $userId);
        }

        return $value;
    }

    public function isLcl(Embarque $booking): bool
    {
        return DB::table('mercadoria')->where('embarque_id', $booking->getKey())->where('tipo_container_id', self::LCL_CONTAINER_TYPE)->exists();
    }

    /** @param  list<int>  $selected  despesas (cadastro) marcadas */
    private function ensureRequiredExpenses(Embarque $booking, array $selected): void
    {
        $has = fn (array $ids) => array_intersect($ids, $selected) !== [];

        if ($booking->tipo_embarque === 'a') {
            if (round((float) $booking->importFactor(), 2) <= 0) {
                throw new DomainException('É necessário que o fator seja maior que 0');
            }

            if (! $has([self::AIR_FREIGHT])) {
                throw new DomainException('É necessário incluir as despesas obrigatórias! (Air Freigth - W/M)');
            }

            return;
        }

        if (! $this->isLcl($booking)) {
            if (! $has(self::FCL_FREIGHT)) {
                throw new DomainException('É necessário incluir as despesas obrigatórias! (Ocean Freigth)');
            }

            return;
        }

        $ebsAdded = FinExpensesValues::where('ref_embarque', $booking->getKey())->where('ref_expense', self::LCL_EBS)->exists();
        $ebsMissing = $ebsAdded && ! $has([self::LCL_EBS]);

        if ($ebsMissing) {
            throw new DomainException('É necessário incluir as despesas obrigatórias! (Ocean Freigth, EBS e Capatazia ou THC)');
        }

        if (! ($has(self::LCL_FREIGHT) && ($has(self::LCL_CAPATAZIA) || $has(self::LCL_THC)))) {
            throw new DomainException('É necessário incluir as despesas obrigatórias! (Ocean Freigth e Capatazia ou THC)');
        }
    }

    private function lclValue(Embarque $booking, $expenses): string
    {
        $factor = (float) $booking->importFactor();

        return $expenses->map(function (FinExpensesValues $e) use ($factor) {
            $unit = (int) $e->despesa?->ref_unity;
            $sale = (float) $e->selling_rate;

            return match (true) {
                $unit === self::UNIT_WEIGHT_MEASURE => $this->line($e, $sale * $factor > (float) $e->sr_min ? $sale * $factor : (float) $e->sr_min),
                in_array($unit, [self::UNIT_BL, self::UNIT_PROCESS], true) => $this->line($e, $sale),
                default => '',
            };
        })->implode('');
    }

    private function fclValue($expenses): string
    {
        return $expenses->map(fn (FinExpensesValues $e) => $this->line(
            $e,
            (float) $e->selling_rate,
            (int) $e->despesa?->ref_unity === self::UNIT_CONTAINER && $e->equipment ? "{$e->despesa->name} / {$e->equipment->nome}" : $e->despesa?->label,
        ))->implode('');
    }

    private function airValue(Embarque $booking, $expenses): string
    {
        $factor = round((float) $booking->importFactor(), 2);

        return $expenses
            ->filter(fn (FinExpensesValues $e) => (int) $e->despesa?->ref_unity === self::UNIT_WEIGHT_MEASURE)
            ->map(fn (FinExpensesValues $e) => $this->line($e, (float) $e->selling_rate * $factor))
            ->implode('');
    }

    /** "\nNome SÍMBOLO 1.234,56": cada linha começa pela quebra, como no legado. */
    private function line(FinExpensesValues $e, float $amount, ?string $name = null): string
    {
        return "\n".($name ?? $e->despesa?->name).' '.($e->sellingCurrency?->symbol ?? '').' '.number_format($amount, 2, ',', '.');
    }

    private function notify(Embarque $booking, string $value, string $justification, int $userId): void
    {
        $importer = $booking->pessoa_id_importador ? (string) DB::table('pessoa')->where('pessoa_id', $booking->pessoa_id_importador)->value('nome') : '';
        $user = (string) DB::table('usuario')->where('usuario_id', $userId)->value('nome');

        $row = fn (string $label, string $content) => "<tr><th style='white-space: nowrap' align='left'>{$label}:</th><td>{$content}</td></tr>";

        $html = '<table><tr><td>Segue abaixo informações de atualização do valor House BL.</td></tr>'
            ."<tr style='height:15px;'></tr><tr><td><table>"
            .$row('Reserva', e($booking->booking))
            .$row('Importador', e($importer))
            .$row('Valor', nl2br(e($value)))
            .$row('Justificativa', e($justification))
            .$row('Atualizado por', e($user))
            .$row('Atualizado em', now()->format('d/m/Y H:i:s'))
            .'</table></td></tr><tr><td><hr/></td></tr></table>';

        Mail::html($html, fn ($mail) => $mail
            ->to(self::NOTICE_TO[1], self::NOTICE_TO[0])
            ->subject("[AVISO] Atualização de valor House BL | {$booking->booking} | {$importer}"));
    }
}

<?php

namespace App\Actions\Booking;

use App\Actions\Cobranca\Generation\BookingChecks;
use App\Models\Embarque;
use App\Models\FinExpensesValues;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * updateEmbarqueExpenses act=2 (legado): cadastra ou altera uma despesa da reserva. As validações são as do navegador
 * (embarqueExpensesSuccess.php), que no legado eram a única barreira. Depois de salvar grava o histórico e devolve
 * a reserva à fila de cobrança e de pagamento (ctr_desp, ctr_pgto, ctr_pgto_outros = 1).
 *
 * Não compara venda × compra em reais nem avisa quando falta cotação (decisão B1 pendente em despesas-embarque.md).
 */
class SaveExpense
{
    public const HIDDEN_FLAGS_EXPENSE = 1916;

    public const DMF_DUE_TO = 7;

    private const REAL = 1;

    private const OCEAN_FREIGHT = [1, 41, 200, 437];

    /** Capatazia, THC e BL fee: na importação saem em reais. */
    private const FEES_IN_REAIS = [18, 43, 436, 463, 708, 879, 12, 36, 82, 194, 202, 241, 639, 993, 11, 951, 959];

    /** Arbitrary Fee => fretes a que se vincula (verificaAF). */
    private const ARBITRARY_FEES = [33 => [1], 419 => [41, 73], 356 => [200], 387 => [273]];

    private const WEIGHT_MEASURE_UNIT = 3;

    private const EXPENSE_GROUP_ALERTED = 12;

    private const IDS = ['ref_expense', 'ref_equipment', 'ref_bl', 'ref_container', 'ref_due_to', 'ref_currency_br', 'ref_currency_sr'];

    private const NUMBERS = ['buying_rate', 'br_min', 'selling_rate', 'sr_min', 'selling_rate_perc', 'profit'];

    private const FLAGS = ['agreement', 'ctrl', 'ctrl2', 'ctrl3', 'all_in'];

    private const OPTIONAL_FLAGS = ['flag_exw', 'mostra_agente'];

    public function __construct(private ExpenseTracking $tracking) {}

    /** verificaAFFrete: a Arbitrary Fee ligada a este frete, se houver. */
    public static function arbitraryFeeOf(int $freight): ?int
    {
        foreach (self::ARBITRARY_FEES as $fee => $freights) {
            if (in_array($freight, $freights, true)) {
                return $fee;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data  campos do formulário; flag_exw e mostra_agente só entram se vierem
     *
     * @throws DomainException mensagem do navegador do legado
     */
    public function __invoke(Embarque $booking, array $data, ?FinExpensesValues $expense, int $userId): FinExpensesValues
    {
        $values = $this->normalize($data);

        if ($values['ref_expense'] === self::HIDDEN_FLAGS_EXPENSE) {
            $values = $this->withHiddenDocumentFlags($values);
        }

        $this->validate($booking, $values);
        $values = $this->withProfit($booking, $values);

        $expense = DB::transaction(function () use ($booking, $values, $expense, $userId) {
            $previous = $expense?->getAttributes();
            $expense ??= new FinExpensesValues;

            $expense->forceFill([
                ...collect($values)->except('profit')->all(),
                'ref_embarque' => $booking->getKey(),
                'buying_rate_perc' => null,
                ...($previous ? ['updated_by' => $userId, 'updated_at' => now()->toDateString()] : ['created_by' => $userId, 'created_at' => now()->toDateString()]),
            ])->save();

            ($this->tracking)($expense->getAttributes(), 'u', $previous, $userId);

            $booking->forceFill(['ctr_desp' => 1, 'ctr_pgto_outros' => 1, 'ctr_pgto' => 1])->save();

            return $expense;
        });

        $this->alertFinance($booking, $expense);

        return $expense;
    }

    /** @return array<string, mixed> */
    private function normalize(array $data): array
    {
        $values = ['billing' => array_key_exists($data['billing'] ?? '', ExpenseTracking::BILLING) ? $data['billing'] : null];

        foreach (self::IDS as $field) {
            $values[$field] = filled($data[$field] ?? null) ? (int) $data[$field] : null;
        }

        foreach (self::NUMBERS as $field) {
            $values[$field] = filled($data[$field] ?? null) ? (float) $data[$field] : null;
        }

        foreach (self::FLAGS as $field) {
            $values[$field] = empty($data[$field]) ? '0' : '1';
        }

        foreach (self::OPTIONAL_FLAGS as $field) {
            if (array_key_exists($field, $data)) {
                $values[$field] = empty($data[$field]) ? 0 : 1;
            }
        }

        return $values;
    }

    /** applyRequiredHiddenDocumentFlags: a despesa 1916 não vai ao cliente (tipo PP, Due To DMF, sem venda). */
    private function withHiddenDocumentFlags(array $values): array
    {
        return [
            ...$values,
            'ctrl3' => '1',
            'all_in' => '1',
            'ref_due_to' => self::DMF_DUE_TO,
            'billing' => 'p',
            'selling_rate' => null,
            'ref_currency_sr' => null,
            'selling_rate_perc' => null,
            'sr_min' => null,
            'agreement' => null,
            'profit' => null,
        ];
    }

    /** Importação aérea: com profit e sem venda, a venda é compra + profit, na moeda da compra. */
    private function withProfit(Embarque $booking, array $values): array
    {
        if ($this->isAirImport($booking) && $values['profit'] !== null && (float) $values['selling_rate'] === 0.0) {
            $values['selling_rate'] = $values['buying_rate'] + $values['profit'];
            $values['ref_currency_sr'] = $values['ref_currency_br'];
        }

        return $values;
    }

    /** @throws DomainException */
    private function validate(Embarque $booking, array $v): void
    {
        $hidden = $v['ref_expense'] === self::HIDDEN_FLAGS_EXPENSE;

        foreach ([
            'ref_expense' => 'É necessário selecionar uma despesa!',
            'billing' => 'É necessário selecionar um tipo de despesa!',
            'ref_due_to' => 'É necessário informar o Due To!',
            'ref_currency_br' => 'É necessário informar a Moeda de Custo!',
            'buying_rate' => 'É necessário informar o valor do Custo!',
        ] as $field => $message) {
            $this->required($v[$field], $message);
        }

        if ($booking->tipo_embarque === 'a' && ! $hidden && $v['selling_rate'] !== null && $v['selling_rate_perc'] !== null) {
            throw new DomainException('Preencha a venda em valor OU percentual!');
        }

        if (! $this->isAirImport($booking) && ! $hidden) {
            $this->required($v['ref_currency_sr'], 'É necessário informar a Moeda de Venda!');
            $this->required($v['selling_rate'], 'É necessário informar o valor da Venda!');
        }

        if (in_array($v['ref_expense'], self::OCEAN_FREIGHT, true) && in_array(self::REAL, [$v['ref_currency_br'], $v['ref_currency_sr']], true)) {
            throw new DomainException('O Frete Internacional não pode ser lançado em R$!');
        }

        if ($booking->impor_expor === 'i' && in_array($v['ref_expense'], self::FEES_IN_REAIS, true)
            && ($v['ref_currency_br'] !== self::REAL || $v['ref_currency_sr'] !== self::REAL)) {
            throw new DomainException('Essa taxa deve ser lançada com a moeda de Compra e Venda em R$!');
        }

        if (! $this->isAirImport($booking) && ! $hidden && $this->isWeightMeasure($v['ref_expense'])) {
            $this->required($v['br_min'] ?: null, 'Para despesas do tipo W/M é preciso informar o Custo Mínimo!');
            $this->required($v['sr_min'] ?: null, 'Para despesas do tipo W/M é preciso informar a Venda Mínima!');
        }

        $this->validateArbitraryFee($booking, $v);
    }

    /** verificaAF: uma Arbitrary Fee só entra se a reserva já tiver o frete, com a mesma moeda de venda e equipamento. */
    private function validateArbitraryFee(Embarque $booking, array $v): void
    {
        $freights = self::ARBITRARY_FEES[$v['ref_expense']] ?? null;

        if ($freights === null) {
            return;
        }

        $exists = DB::table('fin_expenses_values')
            ->where('ref_embarque', $booking->getKey())
            ->whereIn('ref_expense', $freights)
            ->where('ref_currency_sr', $v['ref_currency_sr'])
            ->when($v['ref_equipment'], fn ($q, $equipment) => $q->where('ref_equipment', $equipment), fn ($q) => $q->whereNull('ref_equipment'))
            ->exists();

        if (! $exists) {
            throw new DomainException($v['ref_equipment']
                ? 'Não é possível cadastrar esta despesa. É necessário ter um Frete com o mesmo tipo de cobrança, moeda de venda e equipamento cadastrado!'
                : 'Não é possível cadastrar esta despesa. É necessário ter um Frete com o mesmo tipo de cobrança e moeda de venda cadastrado!');
        }
    }

    private function required(mixed $value, string $message): void
    {
        if ($value === null) {
            throw new DomainException($message);
        }
    }

    private function isAirImport(Embarque $booking): bool
    {
        return $booking->impor_expor === 'i' && $booking->tipo_embarque === 'a';
    }

    private function isWeightMeasure(int $expense): bool
    {
        return (int) DB::table('fin_expenses')->where('id', $expense)->value('ref_unity') === self::WEIGHT_MEASURE_UNIT;
    }

    /** emitirAlerta: importação com notas cadastradas e despesa do grupo 12 avisa o financeiro. */
    private function alertFinance(Embarque $booking, FinExpensesValues $expense): void
    {
        $inGroup = DB::table('grupo_despesa_despesa')
            ->where('despesa_id', $expense->ref_expense)
            ->where('grupo_despesa_id', self::EXPENSE_GROUP_ALERTED)
            ->exists();

        if ($booking->impor_expor !== 'i' || ! $inGroup || ! $this->hasRegisteredNotes($booking)) {
            return;
        }

        $name = e($expense->despesa->label);
        $bookingNumber = e($booking->booking);

        Mail::html("<p>A seguinte despesa foi alterada: {$name}</p><p>O embarque {$bookingNumber} possui Notas Cadastradas</p>", fn ($mail) => $mail
            ->to(BookingChecks::FINANCE_EMAIL)
            ->subject("[FINANCEIRO] Alerta de Alterações: {$booking->booking}"));
    }

    /** Embarque::temNotasCadastradas: nota de débito ou de crédito, ou arquivo do tipo 4 ou 5. */
    private function hasRegisteredNotes(Embarque $booking): bool
    {
        return DB::table('fin_debit_notes')->where('ref_embarque', $booking->getKey())->exists()
            || DB::table('fin_credit_notes')->where('ref_embarque', $booking->getKey())->exists()
            || DB::table('emb_embarque_files')->where('ref_embarque', $booking->getKey())->whereIn('ref_file_type', [4, 5])->exists();
    }
}

<?php

namespace App\Actions\Cobranca\Recalculation;

use App\Models\Embarque;
use App\Models\FinCharges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Cotações do recálculo (Cobranca::calculaCotacao / verificaCotacoes / getDataCotacao do legado). */
class ChargeRates
{
    /** sfConfig hora_cobranca do gerenciador: depois dela, vale a cotação do próximo dia útil. */
    private const CUTOFF = '16:00';

    private const FINANCE_EMAIL = 'financeiro@dmflog.com.br';

    /** Cobranca::getClienteId: cliente do embarque, senão exportador/importador; -1 sem nenhum. */
    public static function clientId(Embarque $embarque): int
    {
        return (int) ($embarque->cliente_id
            ?: match ($embarque->impor_expor) {
                'e' => $embarque->pessoa_id_cliente,
                'i' => $embarque->pessoa_id_importador,
                default => -1,
            });
    }

    /** getDataCotacao: hoje até o horário de corte; depois, o próximo dia (sexta → segunda). */
    public function quoteDate(): string
    {
        if (now()->format('H:i') < self::CUTOFF) {
            return now()->toDateString();
        }

        return now()->addDays(now()->isFriday() ? 3 : 1)->toDateString();
    }

    /** calculaCotacao: BRL = 1; cotação negociada da IC; senão a da empresa ou a padrão, com o percentual. */
    public function rate(int $clientId, ?int $currencyId, FinCharges $charge): float
    {
        if ((int) $currencyId === 1) {
            return 1.0;
        }

        $custom = $charge->customRates()->where('ref_currency', $currencyId)->orderByDesc('id')->value('custom_rate');

        if ($custom !== null) {
            return (float) $custom;
        }

        [$rateId, $tax] = $this->table($clientId, (int) $currencyId);
        $value = (float) $this->value($rateId);

        return round($value + $value * ((float) $tax / 100), 4);
    }

    /**
     * verificaCotacoes: toda moeda dos itens (fora BRL e as negociadas na IC) precisa de cotação válida na data.
     * Avisa o financeiro por e-mail de cada uma que faltar, como o legado.
     */
    public function upToDate(FinCharges $charge): bool
    {
        return $this->currenciesUpToDate($charge->embarque, $charge->items()->where('ref_currency', '<>', 1)->distinct()->pluck('ref_currency')
            ->diff($charge->customRates()->pluck('ref_currency')));
    }

    /** verificaCotacoes sem IC (geração): as moedas das despesas da reserva. @param  iterable<int>  $currencies */
    public function currenciesUpToDate(Embarque $embarque, iterable $currencies): bool
    {
        $clientId = self::clientId($embarque);

        if ($clientId === -1) {
            return true;
        }

        $ok = true;

        foreach ($currencies as $currencyId) {
            $currency = DB::table('cmn_currencies')->where('id', $currencyId)->value('name');
            [$rateId, , $company] = $this->table($clientId, (int) $currencyId);

            if ($rateId === null) {
                $ok = false;
                $this->notify("ERRO: Nao ha cotacao padrao cadastrada para a moeda {$currency}",
                    "A moeda {$currency} nao possui uma cotacao padrao cadastrada.<br/> É necessário fazer o cadastro para poder gerar a cobrança do Booking <strong>{$embarque->booking}</strong>");

                continue;
            }

            $value = $this->value($rateId);

            if ($value !== null && (float) $value > 0) {
                continue;
            }

            $ok = false;
            $problem = $value === null ? 'desatualizada' : 'com valores zerados ou inválidos';
            $title = $company
                ? 'AVISO: Cotacao para a empresa '.DB::table('pessoa')->where('pessoa_id', $clientId)->value('nome')." está {$problem} para a moeda {$currency}"
                : "AVISO: Cotacao padrão {$problem} para a moeda {$currency}";

            $this->notify($title, "A moeda {$currency} esta com cotacao {$problem}.<br/> É necessário fazer a atualizacao para poder gerar a cobrança do Booking <strong>{$embarque->booking}</strong>");
        }

        return $ok;
    }

    /** Tabela de cotação da moeda: a negociada com a empresa, senão a padrão. @return array{0:?int, 1:float, 2:bool} [id, percentual, é da empresa] */
    private function table(int $clientId, int $currencyId): array
    {
        $company = DB::table('fin_companies_rates as fcr')->join('fin_currencies_rates as f', 'f.id', '=', 'fcr.ref_currency_rate')
            ->where('fcr.ref_pessoa', $clientId)->where('f.ref_currency', $currencyId)->orderBy('fcr.id')
            ->first(['fcr.ref_currency_rate', 'fcr.tax']);

        if ($company) {
            return [(int) $company->ref_currency_rate, (float) $company->tax, true];
        }

        $default = DB::table('fin_default_rates as fdr')->join('fin_currencies_rates as f', 'f.id', '=', 'fdr.ref_currency_rate')
            ->where('f.ref_currency', $currencyId)->orderBy('fdr.id')
            ->first(['fdr.ref_currency_rate', 'fdr.default_tax']);

        return [$default ? (int) $default->ref_currency_rate : null, (float) ($default->default_tax ?? 0), false];
    }

    private function value(?int $rateId): ?string
    {
        return $rateId === null ? null : DB::table('fin_currencies_rates_values')
            ->where('ref_currency_rate', $rateId)->where('rate_date', $this->quoteDate())->orderBy('id')->value('rate');
    }

    private function notify(string $title, string $message): void
    {
        Mail::html($message, fn ($mail) => $mail->to(self::FINANCE_EMAIL)->subject("DMF Logistics: {$title}"));
    }
}

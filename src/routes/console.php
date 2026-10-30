<?php

use App\Actions\AccountingCovers\GenerateCoverSummaries;
use App\Actions\AccountingCovers\RefreshCoverSummaries;
use App\Actions\Cobranca\Generation\GenerateAllCharges;
use App\Actions\Cobranca\Notices\HapagPendingNotice;
use App\Actions\Cobranca\Notices\OpenChargeReminders;
use App\Actions\Cobranca\Notices\OpenChargesReport;
use App\Actions\Cobranca\Notices\OpenImportChargesNotice;
use App\Actions\Cobranca\Notices\PendingTakersNotice;
use App\Actions\Cobranca\Notices\ReleaseList;
use App\Actions\Expenses\ExpenseDueNotice;
use App\Actions\PaymentOrders\Generation\GeneratePaymentOrders;
use App\Actions\PaymentOrders\Other\GenerateOtherPaymentOrders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// executeGeraCobranca (legado): gera as ICs de todas as reservas com despesas pendentes.
Artisan::command('cobranca:gerar', function () {
    try {
        $created = app(GenerateAllCharges::class)(null);
    } catch (DomainException $e) {
        $this->warn($e->getMessage());

        return 1;
    }

    $this->info($created ? "{$created} cobrança(s) gerada(s) com sucesso!" : 'Nenhuma cobrança gerada!');

    return 0;
})->purpose('Gera as Instruções de Cobrança das reservas com despesas pendentes');

// desligado até o cron do tracking parar de chamar cobranca/geraCobranca (COBRANCA_GERACAO_AUTOMATICA=true)
Schedule::command('cobranca:gerar')
    ->hourly()
    ->withoutOverlapping()
    ->when(fn () => (bool) config('services.cobranca.automatic_generation'));

// rotinas do módulo cron do legado: [comando, Action, descrição, horário]
$notices = [
    ['cobranca:aviso-aberta', OpenChargeReminders::class, 'Avisa os clientes das cobranças em aberto há 7 dias ou mais (avisoCobrancaAberta)', '08:00'],
    ['cobranca:aviso-aberta-impo', OpenImportChargesNotice::class, 'Avisa o financeiro das ICs de importação em aberto há 7 dias (avisoCobrancaAbertaImpo)', '08:00'],
    ['cobranca:aviso-hapag', HapagPendingNotice::class, 'Avisa o financeiro das exportações Hapag pendentes de cobrança (avisoCobrancaHapag)', '08:00'],
    ['cobranca:relatorio-aberta', OpenChargesReport::class, 'Relatório interno das cobranças em aberto há 7 dias ou mais (relatorioCobrancaAberta)', '08:00'],
    ['cobranca:relacao-liberacao', ReleaseList::class, 'Relação de reservas autorizadas para liberação de BL (relacaoCobrancaReservaExpo)', '18:00'],
    ['cobranca:aviso-pendencia-tomador', PendingTakersNotice::class, 'Avisa o TI dos tomadores com pendência de cadastro nos próximos 5 dias (avisoPendenciaTomador)', '08:00'],
    ['cobranca:aviso-vencimento-despesa', ExpenseDueNotice::class, 'Lembrete dos vencimentos de despesas em 3 dias (avisoVencimentoDespesa)', '08:00'],
];

foreach ($notices as [$command, $action, $purpose, $time]) {
    Artisan::command($command, function () use ($action) {
        $this->info(app($action)().' registro(s) processado(s).');
    })->purpose($purpose);

    // ponytail: horários chutados (o crontab do tracking não está no repo); confirmar com produção antes de ligar
    // desligado até o cron do tracking parar de chamar cron/<rotina> (COBRANCA_ROTINAS_AUTOMATICAS=true)
    // avisoPendenciaTomador rodava só em dias úteis (0 8 * * 1-5)
    Schedule::command($command)
        ->dailyAt($time)
        ->withoutOverlapping()
        ->when(fn () => (bool) config('services.cobranca.automatic_notices')
            && ($command !== 'cobranca:aviso-pendencia-tomador' || ! now()->isWeekend()));
}

// ordemPagamento/geraOrdemPagamento (legado): gera as ordens de pagamento ao armador das reservas com pagamento pendente.
Artisan::command('ordens-pagamento:gerar', function () {
    try {
        $created = app(GeneratePaymentOrders::class)->all();
    } catch (DomainException $e) {
        $this->warn($e->getMessage());

        return 1;
    }

    $this->info($created ? "{$created} ordem(ns) de pagamento gerada(s) com sucesso!" : 'Nenhuma ordem de pagamento gerada!');

    return 0;
})->purpose('Gera as ordens de pagamento ao armador das reservas com pagamento pendente');

// ponytail: de hora em hora é um chute (o crontab do tracking não está no repo); confirmar com produção antes de ligar
// desligado até o cron do tracking parar de chamar ordemPagamento/geraOrdemPagamento (ORDEM_PAGAMENTO_GERACAO_AUTOMATICA=true)
Schedule::command('ordens-pagamento:gerar')
    ->hourly()
    ->withoutOverlapping()
    ->when(fn () => (bool) config('services.ordem_pagamento.automatic_generation'));

// ordemPagamentoOutros/geraOrdemPagamento (legado): gera as ordens de pagamento a despachante, cia aérea e seguro.
Artisan::command('ordens-pagamento-outros:gerar', function () {
    try {
        $created = app(GenerateOtherPaymentOrders::class)->all();
    } catch (DomainException $e) {
        $this->warn($e->getMessage());

        return 1;
    }

    $this->info($created ? "{$created} ordem(ns) de pagamento gerada(s) com sucesso!" : 'Nenhuma ordem de pagamento gerada!');

    return 0;
})->purpose('Gera as ordens de pagamento de outros das reservas com pagamento pendente');

// ponytail: de hora em hora é um chute (o crontab do tracking não está no repo); confirmar com produção antes de ligar
// desligado até o cron do tracking parar de chamar ordemPagamentoOutros/geraOrdemPagamento (ORDEM_PAGAMENTO_OUTROS_GERACAO_AUTOMATICA=true)
Schedule::command('ordens-pagamento-outros:gerar')
    ->hourly()
    ->withoutOverlapping()
    ->when(fn () => (bool) config('services.ordem_pagamento_outros.automatic_generation'));

// cron/manutencao (legado), capas normais: gera o resumo_resultado das capas arquivadas e avisa quem favoritou a reserva.
Artisan::command('capas:resumo-gerar', function () {
    $this->info(app(GenerateCoverSummaries::class)().' resumo(s) gerado(s).');
})->purpose('Gera o resumo de resultado das capas de contabilidade arquivadas');

// ponytail: diário às 02:00 é um chute (o crontab do tracking não está no repo); confirmar com produção antes de ligar
// desligado até o cron do tracking parar de chamar cron/manutencao (CAPAS_ROTINAS_AUTOMATICAS=true)
Schedule::command('capas:resumo-gerar')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->when(fn () => (bool) config('services.capas.automatic_routines'));

// capasContabilidade/atualizarResumoResultado (legado): recalcula rs e resultado dos resumos; só sob demanda.
Artisan::command('capas:resumo-atualizar {--data-min= : criadas a partir de (AAAA-MM-DD)} {--data-max= : criadas até (AAAA-MM-DD)}', function () {
    $count = app(RefreshCoverSummaries::class)(
        $this->option('data-min') ? Carbon\Carbon::parse($this->option('data-min')) : null,
        $this->option('data-max') ? Carbon\Carbon::parse($this->option('data-max')) : null,
    );

    $this->info("Atualizados {$count} registros de resumo.");
})->purpose('Recalcula o resumo de resultado das capas de contabilidade');

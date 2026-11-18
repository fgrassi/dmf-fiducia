<?php

use App\Actions\AccountingCovers\GenerateCoverSummaries;
use App\Actions\AccountingCovers\RefreshCoverSummaries;
use App\Actions\AgentNotes\OverdueNotesNotice;
use App\Actions\Booking\ExpenseReviewNotice;
use App\Actions\Cobranca\Generation\GenerateAllCharges;
use App\Actions\Cobranca\Notices\HapagPendingNotice;
use App\Actions\Cobranca\Notices\MblReleaseNotice;
use App\Actions\Cobranca\Notices\OpenChargeReminders;
use App\Actions\Cobranca\Notices\OpenChargesReport;
use App\Actions\Cobranca\Notices\OpenImportChargesNotice;
use App\Actions\Cobranca\Notices\PendingTakersNotice;
use App\Actions\Cobranca\Notices\ReleaseList;
use App\Actions\Comissoes\OpenCommissionsNotice;
use App\Actions\ControlePa\ControlePaNotices;
use App\Actions\Expenses\ExpenseDueNotice;
use App\Actions\Financeiro\ExchangeDeadlineNotice;
use App\Actions\Financeiro\NotesControlMonthlyReport;
use App\Actions\Financeiro\PriorityArrivalsNotice;
use App\Actions\Financeiro\ProvisioningNotice;
use App\Actions\PaymentOrders\Generation\GeneratePaymentOrders;
use App\Actions\PaymentOrders\Other\GenerateOtherPaymentOrders;
use App\Enums\Notice;
use App\Enums\Routine;
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
    ->when(fn () => (bool) config('services.cobranca.automatic_generation') && Routine::GenerateCharges->enabled());

// rotinas do módulo cron do legado: [comando, Action, descrição, método]; o horário vem de App\Enums\Notice::time() (tela Avisos por e-mail)
$notices = [
    ['cobranca:aviso-aberta', OpenChargeReminders::class, 'Avisa os clientes das cobranças em aberto há 7 dias ou mais (avisoCobrancaAberta)'],
    ['cobranca:aviso-aberta-impo', OpenImportChargesNotice::class, 'Avisa o financeiro das ICs de importação em aberto há 7 dias (avisoCobrancaAbertaImpo)'],
    ['cobranca:aviso-hapag', HapagPendingNotice::class, 'Avisa o financeiro das exportações Hapag pendentes de cobrança (avisoCobrancaHapag)'],
    ['cobranca:relatorio-aberta', OpenChargesReport::class, 'Relatório interno das cobranças em aberto há 7 dias ou mais (relatorioCobrancaAberta)'],
    ['cobranca:relacao-liberacao', ReleaseList::class, 'Relação de reservas autorizadas para liberação de BL (relacaoCobrancaReservaExpo)'],
    ['cobranca:aviso-pendencia-tomador', PendingTakersNotice::class, 'Avisa o TI dos tomadores com pendência de cadastro nos próximos 5 dias (avisoPendenciaTomador)'],
    ['cobranca:aviso-vencimento-despesa', ExpenseDueNotice::class, 'Lembrete dos vencimentos de despesas em 3 dias (avisoVencimentoDespesa)'],
    ['despesas:aviso-revisao-importacao', ExpenseReviewNotice::class, 'Despesas de importação lançadas hoje com venda abaixo da compra ou sem cotação (verificaDespesas)', 'imports'],
    ['despesas:aviso-revisao-exportacao', ExpenseReviewNotice::class, 'Despesas de exportação lançadas hoje com venda abaixo da compra ou sem cotação (verificaDespesas)', 'exports'],
    ['cobranca:aviso-liberacao-mbl', MblReleaseNotice::class, 'Avisa os agentes das exportações com frete a receber e chegada em até 5 dias (avisoAgenteFaltaPagamento)'],
    ['financeiro:aviso-limite-cambio', ExchangeDeadlineNotice::class, 'Agentes com prazo de envio de câmbio nos próximos 5 dias (avisoFinanceiroVencimentoEnvioCambio)'],
    ['financeiro:aviso-embarques-prioritarios', PriorityArrivalsNotice::class, 'Embarques prioritários que chegam em 5 e 2 dias (avisoFinanceiroEmbarquesPrioritarios)'],
    ['comissoes:aviso-abertas', OpenCommissionsNotice::class, 'Comissões de embarque em aberto, ao financeiro (avisoComissoesAbertas)'],
    ['notas-agentes:aviso-atrasadas', OverdueNotesNotice::class, 'Notas de débito/crédito de exportação emitidas há mais de 30 dias (debitNotesAtrasadas)'],
    ['notas:relatorio-status-mensal', NotesControlMonthlyReport::class, 'Reservas que deixaram de precisar de nota no mês anterior, só no dia 1º (relatorioStatusNotas)'],
    ['controle-pa:aviso-envio-agente', ControlePaNotices::class, 'Freight Invoices prontas para envio ao agente (controlePaAvisoEnvioAgente)', 'readyToSendToAgent'],
    ['controle-pa:aviso-envio-armador', ControlePaNotices::class, 'Freight Invoices prontas para envio ao armador (controlePaAvisoEnvioArmador)', 'readyToSendToCarrier'],
    ['controle-pa:aviso-pendencia-confirmacao', ControlePaNotices::class, 'Pagamentos ao armador sem recibo (controlePaAvisoPendenciaConfirmacao)', 'pendingReceipt'],
    ['controle-pa:aviso-pendencia-pagamento', ControlePaNotices::class, 'Freight Invoices do armador sem pagamento (controlePaAvisoPendenciaPagamento)', 'pendingPayment'],
    ['controle-pa:aviso-embarques-confirmados', ControlePaNotices::class, 'Embarques confirmados sem Freight Invoice (controlePaAvisoReservasConfirmadas)', 'confirmedWithoutInvoice'],
];

foreach ($notices as $row) {
    [$command, $action, $purpose] = $row;
    $method = $row[3] ?? null; // método da Action quando ela agrupa vários avisos

    Artisan::command($command, function () use ($action, $method) {
        $this->info(($method ? app($action)->$method() : app($action)()).' registro(s) processado(s).');
    })->purpose($purpose);

    //  horários chutados (o crontab do tracking não está no repo); confirmar com produção antes de ligar
    // desligado até o cron do tracking parar de chamar cron/<rotina> (COBRANCA_ROTINAS_AUTOMATICAS=true)
    // avisoPendenciaTomador rodava só em dias úteis (0 8 * * 1-5)
    // roda a cada minuto e só passa no horário gravado na tela Avisos por e-mail
    Schedule::command($command)
        ->everyMinute()
        ->withoutOverlapping()
        ->when(fn () => (bool) config('services.cobranca.automatic_notices')
            && now()->format('H:i') === Notice::from($command)->time()
            && ($command !== 'cobranca:aviso-pendencia-tomador' || ! now()->isWeekend()));
}

// cron/provisionamento (legado): o assunto leva o número da semana, então roda uma vez por semana (dia chutado; hora editável na tela Avisos por e-mail; confirmar com o crontab de produção).
Artisan::command('financeiro:provisionamento-semanal', function () {
    $this->info(app(ProvisioningNotice::class)().' reserva(s) listada(s).');
})->purpose('Provisionamento de receitas e despesas da semana daqui a 2 semanas, exportação e importação (provisionamento)');

Schedule::command('financeiro:provisionamento-semanal')
    ->mondays()
    ->everyMinute()
    ->withoutOverlapping()
    ->when(fn () => (bool) config('services.cobranca.automatic_notices') && now()->format('H:i') === Notice::Provisioning->time());

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

//  de hora em hora é um chute (o crontab do tracking não está no repo); confirmar com produção antes de ligar
// desligado até o cron do tracking parar de chamar ordemPagamento/geraOrdemPagamento (ORDEM_PAGAMENTO_GERACAO_AUTOMATICA=true)
Schedule::command('ordens-pagamento:gerar')
    ->hourly()
    ->withoutOverlapping()
    ->when(fn () => (bool) config('services.ordem_pagamento.automatic_generation') && Routine::GeneratePaymentOrders->enabled());

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

//  de hora em hora é um chute (o crontab do tracking não está no repo); confirmar com produção antes de ligar
// desligado até o cron do tracking parar de chamar ordemPagamentoOutros/geraOrdemPagamento (ORDEM_PAGAMENTO_OUTROS_GERACAO_AUTOMATICA=true)
Schedule::command('ordens-pagamento-outros:gerar')
    ->hourly()
    ->withoutOverlapping()
    ->when(fn () => (bool) config('services.ordem_pagamento_outros.automatic_generation') && Routine::GenerateOtherPaymentOrders->enabled());

// cron/manutencao (legado), capas normais: gera o resumo_resultado das capas arquivadas e avisa quem favoritou a reserva.
Artisan::command('capas:resumo-gerar', function () {
    $this->info(app(GenerateCoverSummaries::class)().' resumo(s) gerado(s).');
})->purpose('Gera o resumo de resultado das capas de contabilidade arquivadas');

//  diário às 02:00 é um chute (o crontab do tracking não está no repo); confirmar com produção antes de ligar
// desligado até o cron do tracking parar de chamar cron/manutencao (CAPAS_ROTINAS_AUTOMATICAS=true)
// roda a cada minuto e só passa no horário gravado na tela Avisos por e-mail (padrão 02:00)
Schedule::command('capas:resumo-gerar')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(fn () => (bool) config('services.capas.automatic_routines')
        && Routine::GenerateCoverSummaries->enabled()
        && now()->format('H:i') === Routine::GenerateCoverSummaries->time());

// capasContabilidade/atualizarResumoResultado (legado): recalcula rs e resultado dos resumos; só sob demanda.
Artisan::command('capas:resumo-atualizar {--data-min= : criadas a partir de (AAAA-MM-DD)} {--data-max= : criadas até (AAAA-MM-DD)}', function () {
    $count = app(RefreshCoverSummaries::class)(
        $this->option('data-min') ? Carbon\Carbon::parse($this->option('data-min')) : null,
        $this->option('data-max') ? Carbon\Carbon::parse($this->option('data-max')) : null,
    );

    $this->info("Atualizados {$count} registros de resumo.");
})->purpose('Recalcula o resumo de resultado das capas de contabilidade');

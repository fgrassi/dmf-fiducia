<?php

namespace App\Enums;

use App\Enums\Concerns\StoresNoticeSetting;

/**
 * Avisos automáticos por e-mail (módulo cron do legado) com destinatários fixos. O padrão de cada um é o que o legado tinha
 * no código; a tela Configurações > Avisos por e-mail grava a troca em notice_settings.
 */
enum Notice: string
{
    use StoresNoticeSetting;

    case OpenCharges = 'cobranca:aviso-aberta';
    case OpenImportCharges = 'cobranca:aviso-aberta-impo';
    case HapagPending = 'cobranca:aviso-hapag';
    case OpenChargesReport = 'cobranca:relatorio-aberta';
    case ReleaseList = 'cobranca:relacao-liberacao';
    case PendingTakers = 'cobranca:aviso-pendencia-tomador';
    case ExpenseDue = 'cobranca:aviso-vencimento-despesa';
    case ExpenseReviewImport = 'despesas:aviso-revisao-importacao';
    case ExpenseReviewExport = 'despesas:aviso-revisao-exportacao';
    case UnreviewedExpenses = 'despesas:aviso-sem-revisao';
    case MblRelease = 'cobranca:aviso-liberacao-mbl';
    case ExchangeDeadline = 'financeiro:aviso-limite-cambio';
    case PriorityArrivals = 'financeiro:aviso-embarques-prioritarios';
    case Provisioning = 'financeiro:provisionamento-semanal';
    case OpenCommissions = 'comissoes:aviso-abertas';
    case OverdueNotes = 'notas-agentes:aviso-atrasadas';
    case NotesMonthlyReport = 'notas:relatorio-status-mensal';
    case ImportWithoutNotes = 'notas:relatorio-impo-sem-notas';
    case ReadyToSendToAgent = 'controle-pa:aviso-envio-agente';
    case ReadyToSendToCarrier = 'controle-pa:aviso-envio-armador';
    case PendingReceipt = 'controle-pa:aviso-pendencia-confirmacao';
    case PendingPayment = 'controle-pa:aviso-pendencia-pagamento';
    case ConfirmedWithoutInvoice = 'controle-pa:aviso-embarques-confirmados';
    case AmsMissingUs = 'ams:aviso-eua';
    case AmsMissingMexico = 'ams:aviso-mexico';
    case AmsPendingAfrica = 'ams:aviso-africa';
    case AmsPendingUae = 'ams:aviso-uae';
    case AmsReminderAfrica = 'ams:lembrete-africa';
    case AmsReminderUae = 'ams:lembrete-uae';

    /** Dias da semana de 0 (domingo) a 6 (sábado). */
    private const EVERY_DAY = [0, 1, 2, 3, 4, 5, 6];

    private const FINANCE = 'financeiro@dmflog.com.br';

    private const IT = 'it@dmflog.com.br';

    private const BILLING = 'cobranca@dmflog.com.br';

    private const DRAFT = 'draft@dmflog.com.br';

    private const MARCIA = 'marcia@dmflog.com.br';

    /** @return array{label: string, weekdays: list<int>|null, fixed_days?: string, time: string, to: list<string>|null, cc: list<string>} to null: contatos da própria reserva; weekdays null: dia fixo (fixed_days) */
    private function definition(): array
    {
        $daily = ['weekdays' => self::EVERY_DAY, 'time' => '08:00'];
        $controlePa = [...$daily, 'to' => [self::FINANCE], 'cc' => []];

        return match ($this) {
            self::OpenCharges => ['label' => 'Clientes: cobranças em aberto há 7 dias ou mais (Para: contatos de cobrança da reserva)', ...$daily, 'to' => null, 'cc' => [self::BILLING]],
            self::OpenImportCharges => ['label' => 'Cobranças de importação em aberto há 7 dias', ...$daily, 'to' => [self::FINANCE], 'cc' => []],
            self::HapagPending => ['label' => 'Exportações Hapag pendentes de cobrança', ...$daily, 'to' => [self::FINANCE, self::IT], 'cc' => []],
            self::OpenChargesReport => ['label' => 'Relatório interno das cobranças em aberto', ...$daily, 'to' => [self::BILLING, 'chamado@dmflog.com.br'], 'cc' => []],
            self::ReleaseList => ['label' => 'Reservas autorizadas para liberação de BL', 'weekdays' => self::EVERY_DAY, 'time' => '18:00', 'to' => [self::FINANCE, 'diego@dmflog.com.br', 'docexp@dmflog.com.br'], 'cc' => [self::IT]],
            self::PendingTakers => ['label' => 'Tomadores com pendência de cadastro', 'weekdays' => [1, 2, 3, 4, 5], 'time' => '08:00', 'to' => [self::IT], 'cc' => []],
            self::ExpenseDue => ['label' => 'Vencimento de despesas em 3 dias', ...$daily, 'to' => [self::FINANCE, self::IT], 'cc' => []],
            self::ExpenseReviewImport => ['label' => 'Revisão das despesas lançadas hoje (importação)', 'weekdays' => self::EVERY_DAY, 'time' => '18:00', 'to' => [self::BILLING, 'imp2@dmflog.com.br', 'imp3@dmflog.com.br', 'imp4@dmflog.com.br', 'imp5@dmflog.com.br'], 'cc' => []],
            self::ExpenseReviewExport => ['label' => 'Revisão das despesas lançadas hoje (exportação)', 'weekdays' => self::EVERY_DAY, 'time' => '18:00', 'to' => [self::BILLING, 'douglas@dmflog.com.br'], 'cc' => []],
            self::UnreviewedExpenses => ['label' => 'Importações sem revisão de despesas, chegada em até 3 dias', ...$daily, 'to' => ['imp2@dmflog.com.br', 'imp3@dmflog.com.br', 'imp4@dmflog.com.br', 'imp5@dmflog.com.br', self::FINANCE], 'cc' => []],
            self::MblRelease => ['label' => 'Agentes: frete a receber e chegada em até 5 dias (Para: contatos da reserva)', ...$daily, 'to' => null, 'cc' => [self::FINANCE]],
            self::ExchangeDeadline => ['label' => 'Prazo de envio de câmbio dos agentes', ...$daily, 'to' => [self::FINANCE], 'cc' => []],
            self::PriorityArrivals => ['label' => 'Embarques prioritários com chegada próxima', ...$daily, 'to' => [self::FINANCE], 'cc' => []],
            self::Provisioning => ['label' => 'Provisionamento semanal de receitas e despesas', 'weekdays' => [1], 'time' => '08:00', 'to' => [self::MARCIA, self::IT], 'cc' => []],
            self::OpenCommissions => ['label' => 'Comissões de embarque em aberto', ...$daily, 'to' => ['financeiro5@dmflog.com.br', self::MARCIA], 'cc' => []],
            self::OverdueNotes => ['label' => 'Notas de agentes emitidas há mais de 30 dias', ...$daily, 'to' => [self::MARCIA, self::FINANCE, 'chamado@dmflog.com.br'], 'cc' => []],
            self::NotesMonthlyReport => ['label' => 'Relatório mensal do controle de notas', 'weekdays' => null, 'fixed_days' => 'Dia 1º', 'time' => '08:00', 'to' => [self::MARCIA], 'cc' => []],
            self::ImportWithoutNotes => ['label' => 'Importações confirmadas no mês sem nota de débito/crédito', 'weekdays' => null, 'fixed_days' => 'Último dia do mês', 'time' => '08:00', 'to' => [self::IT, self::FINANCE], 'cc' => []],
            self::ReadyToSendToAgent => ['label' => 'Freight Invoices prontas para envio ao agente', ...$controlePa],
            self::ReadyToSendToCarrier => ['label' => 'Freight Invoices prontas para envio ao armador', ...$controlePa],
            self::PendingReceipt => ['label' => 'Pagamentos ao armador sem recibo', ...$controlePa],
            self::PendingPayment => ['label' => 'Freight Invoices do armador sem pagamento', ...$controlePa],
            self::ConfirmedWithoutInvoice => ['label' => 'Embarques confirmados sem Freight Invoice', ...$controlePa],
            self::AmsMissingUs => ['label' => 'avisoAms', ...$daily, 'to' => [self::DRAFT], 'cc' => []],
            self::AmsMissingMexico => ['label' => 'avisoAmsMexico', ...$daily, 'to' => [self::DRAFT], 'cc' => []],
            self::AmsPendingAfrica => ['label' => 'avisoAmsAfrica', ...$daily, 'to' => [self::DRAFT], 'cc' => []],
            self::AmsPendingUae => ['label' => 'avisoAmsUae', ...$daily, 'to' => [self::DRAFT], 'cc' => []],
            self::AmsReminderAfrica => ['label' => 'enviaReminderAmsAfrica', ...$daily, 'to' => null, 'cc' => [self::DRAFT]],
            self::AmsReminderUae => ['label' => 'enviaReminderAmsUae', ...$daily, 'to' => null, 'cc' => [self::DRAFT]],
        };
    }

    public function label(): string
    {
        return $this->definition()['label'];
    }

    /** Dias em que o aviso roda ("Diário", "Dias úteis", "Seg, Qua"...); o horário é o de time(). */
    public function days(): string
    {
        $weekdays = $this->weekdays();

        return $weekdays === null ? $this->definition()['fixed_days'] : self::weekdaysLabel($weekdays);
    }

    protected function defaultWeekdays(): ?array
    {
        return $this->definition()['weekdays'];
    }

    protected function defaultTime(): string
    {
        return $this->definition()['time'];
    }

    /** Os endereços de copies() vão em cópia oculta (o cliente não vê). */
    public function copiesAreBlind(): bool
    {
        return $this === self::OpenCharges;
    }

    public function hasFixedRecipients(): bool
    {
        return $this->definition()['to'] !== null;
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return $this->setting()?->recipients ?: ($this->definition()['to'] ?? []);
    }

    /** @return list<string> */
    public function copies(): array
    {
        return $this->setting()?->cc ?? $this->definition()['cc'];
    }
}

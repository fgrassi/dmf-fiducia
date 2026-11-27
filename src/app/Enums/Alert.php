<?php

namespace App\Enums;

use App\Enums\Concerns\StoresNoticeSetting;
use Closure;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

/**
 * Alertas internos disparados por uma ação no sistema, com destinatários fixos. O padrão de cada um é o que o legado tinha no
 * código; a tela Configurações > Avisos por e-mail liga/desliga e grava a troca dos destinatários em notice_settings.
 */
enum Alert: string
{
    use StoresNoticeSetting;

    case NfseNotIssued = 'alerta:nfse-nao-emitida';
    case ReceiptDeleted = 'alerta:recibo-excluido';
    case ChargeDeleted = 'alerta:cobranca-excluida';
    case ChargeItemsDeleted = 'alerta:cobranca-despesas-excluidas';
    case ManualSettlement = 'alerta:cobranca-baixa-manual';
    case CurrencyRate = 'alerta:cotacao';
    case ChargeChecks = 'alerta:cobranca-verificacoes';
    case ChargeGeneratedManually = 'alerta:cobranca-manual';
    case ChargesGenerated = 'alerta:cobrancas-geradas';
    case InvoiceControlPending = 'alerta:controle-invoice';
    case MandatoryInsuranceWaived = 'alerta:seguro-obrigatorio-dispensado';
    case DirectBlCollect = 'alerta:bl-direto-cc';
    case GuaranteeDepositReview = 'alerta:deposito-garantia';
    case ArrivalDivergence = 'alerta:chegada-divergencia-despesas';
    case Deconsolidation = 'alerta:desconsolidacao';
    case ReleasePending = 'alerta:liberacao-pendente';
    case DeparturePa = 'alerta:embarque-despesas-pa';
    case Momast = 'alerta:momast';
    case BlExpensesReview = 'alerta:bl-verificar-despesas';
    case FreightPlaceImport = 'alerta:local-frete-importacao';
    case FreightPlaceExport = 'alerta:local-frete-exportacao';
    case LclVolume = 'alerta:lcl-volume';
    case LclNotes = 'alerta:lcl-notas';
    case ReeferTemperature = 'alerta:temperatura-reefer';
    case InsuredBookingCancelled = 'alerta:cancelamento-com-seguro';
    case ExpenseChangedWithNotes = 'alerta:despesa-alterada-com-notas';
    case HouseBlValue = 'alerta:valor-house-bl';
    case LargeShip = 'alerta:navio-grande';
    case Demurrage = 'alerta:demurrage';
    case Detention = 'alerta:detention';
    case CarrierMatrixImport = 'alerta:matriz-armador-importacao';
    case CarrierMatrixExport = 'alerta:matriz-armador-exportacao';
    case BrokerRatesImport = 'alerta:taxas-despachante-importacao';
    case BrokerRatesExport = 'alerta:taxas-despachante-exportacao';
    case ClientBoletoRemoved = 'alerta:prazo-negociado-removido';
    case FollowUpChanged = 'alerta:fup-alterado';
    case ExpensesNotImported = 'alerta:matriz-despesas-nao-importadas';
    case QuotationValidityChanged = 'alerta:validade-cotacao-alterada';
    case InsurancePolicyUpdate = 'alerta:atualizar-apolice';

    private const FINANCE = 'financeiro@dmflog.com.br';

    private const IMPORT = 'importacao@dmflog.com.br';

    private const EXPORT = 'exportacao@dmflog.com.br';

    private const BILLING = 'cobranca@dmflog.com.br';

    private const DRAFT = 'draft@dmflog.com.br';

    private const IMPORT_DOCS = 'docimportacao@dmflog.com.br';

    private const IT = 'chamado@dmflog.com.br';

    private const MARCIA = 'marcia@dmflog.com.br';

    private const DIEGO = 'diego@dmflog.com.br';

    /** @return array{0: string, 1: list<string>, 2?: list<string>} descrição, Para e Cópia */
    private function definition(): array
    {
        return match ($this) {
            self::NfseNotIssued => ['Cobrança: NFS-e não emitida na geração da instrução de cobrança', [self::FINANCE], [self::IT]],
            self::ReceiptDeleted => ['Cobrança: recibo ou declaração excluída', [self::BILLING]],
            self::ChargeDeleted => ['Cobrança: cobrança excluída', [self::BILLING]],
            self::ChargeItemsDeleted => ['Cobrança: despesas excluídas de uma IC', [self::FINANCE]],
            self::ManualSettlement => ['Cobrança: baixa manual', [self::FINANCE]],
            self::CurrencyRate => ['Cobrança: cotação ausente, desatualizada ou zerada', [self::FINANCE]],
            self::ChargeChecks => ['Cobrança: reserva com pendência que impede a geração (o autor da reserva também recebe quando falta despesa obrigatória)', [self::FINANCE]],
            self::ChargeGeneratedManually => ['Cobrança: gerada manualmente', [self::FINANCE]],
            self::ChargesGenerated => ['Cobrança: resumo das cobranças geradas', [self::FINANCE]],
            self::InvoiceControlPending => ['Cobrança: Controle de Invoice pendente ou aguardando arquivo', [self::FINANCE]],
            self::MandatoryInsuranceWaived => ['Reserva: cliente com seguro obrigatório sem reserva de seguro', [self::IMPORT, self::MARCIA]],
            self::DirectBlCollect => ['Reserva: BL direto com pagamento do frete em CC', [self::EXPORT]],
            self::GuaranteeDepositReview => ['Reserva: revisar o depósito de garantia importado da matriz', [self::IMPORT, self::MARCIA]],
            self::ArrivalDivergence => ['Chegada confirmada: despesas diferentes do cadastro do armador/porto', [self::FINANCE]],
            self::Deconsolidation => ['Chegada: desconsolidação a menos de 3 dias da atracação', [self::DIEGO, self::IMPORT_DOCS]],
            self::ReleasePending => ['Embarque confirmado: nova pendência de liberação', [self::IMPORT]],
            self::DeparturePa => ['Embarque confirmado: exportação com despesas do tipo PA', [self::EXPORT]],
            self::Momast => ['Embarque confirmado: gerar o arquivo MOMAST (Duroline/Stemco)', [self::DRAFT]],
            self::BlExpensesReview => ['BL finalizado: verificar as despesas', [self::IMPORT]],
            self::FreightPlaceImport => ['BL: local de pagamento do frete inconsistente (importação)', [self::IMPORT, self::IMPORT_DOCS]],
            self::FreightPlaceExport => ['BL: local de pagamento do frete inconsistente (exportação)', [self::EXPORT, self::DRAFT]],
            self::LclVolume => ['BL: volume de LCL divergente entre a reserva e o draft', [self::EXPORT]],
            self::LclNotes => ['BL: equipamento LCL alterado em reserva com notas cadastradas', [self::FINANCE]],
            self::ReeferTemperature => ['BL: temperatura reefer divergente da reserva', [self::EXPORT, self::DRAFT]],
            self::InsuredBookingCancelled => ['Reserva: cancelamento de reserva com seguro', [self::EXPORT]],
            self::ExpenseChangedWithNotes => ['Reserva: despesa alterada em embarque com notas cadastradas', [self::FINANCE]],
            self::HouseBlValue => ['Reserva: atualização do valor do House BL', [self::IMPORT_DOCS]],
            self::LargeShip => ['Rota: navio com mais de 300 metros', [self::DIEGO, 'trackingimp@dmflog.com.br', self::IMPORT]],
            self::Demurrage => ['Containers devolvidos com incidência de demurrage', ['info_demurrage@dmflog.com.br']],
            self::Detention => ['Containers devolvidos com incidência de detention', ['detention@dmflog.com.br']],
            self::CarrierMatrixImport => ['Matriz do armador: embarques de importação para revisar', [self::IMPORT, self::FINANCE, self::IT]],
            self::CarrierMatrixExport => ['Matriz do armador: embarques de exportação para revisar', [self::EXPORT, self::FINANCE, self::IT]],
            self::BrokerRatesImport => ['Taxas do despachante alteradas (importação)', ['imp2@dmflog.com.br', 'imp3@dmflog.com.br', 'imp4@dmflog.com.br', self::FINANCE]],
            self::BrokerRatesExport => ['Taxas do despachante alteradas (exportação)', ['export@dmflog.com.br']],
            self::ClientBoletoRemoved => ['Cliente: prazo negociado removido', [self::FINANCE]],
            self::FollowUpChanged => ['Follow up já enviado no relatório que foi alterado', ['priscila@dmflog.com.br', 'fernando@dmflog.com.br']],
            self::ExpensesNotImported => ['Reserva: despesas da matriz não importadas em reserva com despesas revisadas', [self::IMPORT, self::FINANCE]],
            self::QuotationValidityChanged => ['Reserva: data de validade da cotação alterada na exportação', [self::EXPORT]],
            self::InsurancePolicyUpdate => ['Reserva: ETD antecipado ou navio trocado em embarque com seguro', [self::EXPORT]],
        };
    }

    public function label(): string
    {
        return $this->definition()[0];
    }

    public function days(): string
    {
        return 'Na hora';
    }

    protected function defaultWeekdays(): ?array
    {
        return null;
    }

    protected function defaultTime(): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return $this->setting()?->recipients ?: $this->definition()[1];
    }

    /** @return list<string> */
    public function copies(): array
    {
        return $this->setting()?->cc ?? $this->definition()[2] ?? [];
    }

    /**
     * Envia o alerta aos destinatários configurados; desligado, não envia nada.
     *
     * @param  ?Closure(Message): mixed  $configure  remetente, anexos etc.
     * @param  list<string>  $also  destinatários do caso, somados aos configurados
     */
    public function send(string $subject, string $html, ?Closure $configure = null, array $also = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        Mail::html($html, function (Message $mail) use ($subject, $configure, $also) {
            $mail->to(array_values(array_unique([...$also, ...$this->recipients()])))->subject($subject);

            if ($copies = $this->copies()) {
                $mail->cc($copies);
            }

            $configure && $configure($mail);
        });
    }
}

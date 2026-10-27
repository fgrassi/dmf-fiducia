<?php

namespace App\Filament\Resources\Charges\Pages;

use App\Actions\Cobranca\Documents\ChargeRecipients;
use App\Actions\Cobranca\Documents\DebitNote;
use App\Actions\Cobranca\Documents\PlainReceiptPdf;
use App\Actions\Cobranca\Items\EditIof;
use App\Actions\Cobranca\Maintenance\DeleteCharge;
use App\Actions\Cobranca\Nfse\CancelNfse;
use App\Actions\Cobranca\Nfse\IssueNfse;
use App\Actions\Cobranca\Nfse\NfseApi;
use App\Actions\Cobranca\Nfse\RefreshStatus;
use App\Actions\Cobranca\Nfse\SubstituteNfse;
use App\Actions\Cobranca\Nfse\SyncPendingNfse;
use App\Actions\Cobranca\Rates\DeleteCustomRate;
use App\Actions\Cobranca\Recalculation\RecalculateCharge;
use App\Actions\Cobranca\Receipts\EditReceiptValue;
use App\Actions\Cobranca\Receipts\GenerateReceipts;
use App\Actions\Cobranca\Receipts\ResendReceipt;
use App\Actions\Cobranca\Settlement\BankSettlement;
use App\Actions\Cobranca\Settlement\BoletoSettlement;
use App\Actions\Cobranca\Settlement\ManualSettlement;
use App\Enums\Cobranca\ChargeStatus;
use App\Filament\Resources\Charges\ChargeResource;
use App\Filament\Resources\Charges\Schemas\NfseEmissionForm;
use App\Models\Escritorio;
use App\Models\FinCharges;
use App\Models\FinChargesLog;
use App\Models\FinChargesNfse;
use App\Services\Cobranca\ChargeDetails;
use Carbon\Carbon;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

class ViewCharge extends ViewRecord
{
    protected static string $resource = ChargeResource::class;

    /**
     * executeShow (legado): ao abrir, a última NFS-e ainda pendente é consultada na API;
     * se já está autorizada e o e-mail não saiu, tenta enviá-lo.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        app(SyncPendingNfse::class)($this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        $open = fn () => $this->getRecord()->status === ChargeStatus::Open;
        $allowed = fn () => $open() && app(ChargeDetails::class)->allowsRecalculation($this->getRecord());

        return [
            $this->recalculateAction('recalcular', 'Recalcular', RecalculateCharge::SINGLE)
                ->visible($allowed)
                ->modalDescription(fn () => RecalculateCharge::bookingHasOtherCharges($this->getRecord())
                    ? 'Reserva com mais de 1 cobrança vinculada. Deseja recalcular "Somente esta" ou "Todas"?'
                    : 'Recalcular cobrança?')
                ->schema(fn () => [
                    Radio::make('todos')
                        ->hiddenLabel()
                        ->options([
                            RecalculateCharge::SINGLE => 'Somente esta',
                            RecalculateCharge::ALL => 'Todas',
                        ])
                        ->default(RecalculateCharge::SINGLE)
                        ->visible(RecalculateCharge::bookingHasOtherCharges($this->getRecord())),
                    Checkbox::make('manter_iof_atual')
                        ->label('Manter IOF atual ao recalcular (somente esta cobrança)'),
                    ...$this->recalculationNfseFields(),
                ]),
            $this->recalculateAction('recalcularUnir', 'Recalcular e Unir', RecalculateCharge::MERGE)
                ->visible($allowed)
                ->modalDescription('As cobranças em aberto desta reserva (mesmo BL) serão unidas na mais antiga e recalculadas.')
                ->schema(fn () => $this->recalculationNfseFields()),
            $this->recalculateAction('recalcularSemIof', 'Recalcular SEM IOF', RecalculateCharge::SINGLE, withoutIof: true)
                ->visible($open)
                ->modalDescription('Recalcular a cobrança com IOF zerado?')
                ->schema(fn () => $this->recalculationNfseFields()),
            // reemitirNfse do legado: emissão antes da baixa (prazo/boleto, armador que não recalcula ou exceção) ou depois dela
            Action::make('gerarNfse')
                ->label('Gerar NFSe')
                ->icon('heroicon-o-document-plus')
                ->color('gray')
                ->visible(fn () => app(IssueNfse::class)->canGenerate($this->getRecord()))
                ->modalHeading('Gerar NFSe')
                ->modalWidth('3xl')
                ->modalSubmitActionLabel('Emitir NFSe')
                ->fillForm(fn () => ['nfse' => NfseEmissionForm::defaults(
                    $this->getRecord(),
                    NfseEmissionForm::GENERATE,
                    FinChargesNfse::where('ref_charge', $this->getRecord()->getKey())->orderByDesc('fin_charges_nfse_id')->first(),
                )])
                ->steps(fn () => [
                    Step::make('NFS-e')
                        ->schema(NfseEmissionForm::fields($this->getRecord(), NfseEmissionForm::GENERATE)),
                    Step::make('Confirmação')
                        ->schema($this->confirmation('nfse')),
                ])
                ->action(function (array $data) {
                    $record = $this->getRecord();
                    $emails = $this->chosenRecipients($data);
                    $record->selectRecipients($emails);
                    $record->save();

                    $outcome = app(IssueNfse::class)->forCharge($record, ['emitir_nfse' => true, ...$data['nfse']], IssueNfse::REISSUE, auth()->id());

                    if ($outcome && $outcome['records'] !== []) {
                        FinChargesLog::forceCreate([
                            'ref_charge' => $record->id,
                            'acao' => 'alteracao',
                            'historico' => 'Reemissão NFSe'.FinCharges::recipientsLogSummary($emails),
                            'status_anterior' => 0,
                            'status_novo' => 1,
                            'created_at' => now(),
                            'created_by' => auth()->id(),
                        ]);
                    }

                    $this->notifyNfse($outcome ?? ['status' => 'danger', 'message' => 'Não foi possível reenviar a NFSe.']);
                    $this->redirect(ChargeResource::getUrl('view', ['record' => $record]));
                }),
            Action::make('baixaBancaria')
                ->label('Baixa Bancária')
                ->icon('heroicon-o-building-library')
                ->color('gray')
                ->visible($open)
                ->modalHeading('Baixa Bancária')
                ->modalSubmitActionLabel('Salvar')
                ->modalWidth('2xl')
                ->fillForm(fn () => $this->paymentDefaults())
                ->steps(fn () => $this->settlementSteps($this->paymentFields()))
                ->action(function (array $data, Action $action) {
                    try {
                        $result = app(BankSettlement::class)($this->getRecord(), $data, auth()->user(), $this->chosenRecipients($data), $data['nfse'] ?? []);
                    } catch (DomainException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();
                    }

                    Notification::make()
                        ->title('Baixa bancária registrada. Recibo(s) gerado(s) e enviado(s).')
                        ->body($result['warning'])
                        ->status($result['warning'] ? 'warning' : 'success')
                        ->send();
                    $this->notifyNfse($result['nfse']);
                    $this->redirect(ChargeResource::getUrl('view', ['record' => $this->getRecord()]));
                }),
            Action::make('baixaBoleto')
                ->label('Baixa por Boleto')
                ->icon('heroicon-o-qr-code')
                ->color('gray')
                ->visible($open)
                ->modalHeading('Baixa por Boleto')
                ->modalSubmitActionLabel('Salvar')
                ->modalWidth('2xl')
                ->fillForm(fn () => $this->paymentDefaults())
                ->steps(fn () => $this->settlementSteps($this->paymentFields()))
                ->action(function (array $data, Action $action) {
                    try {
                        $nfse = app(BoletoSettlement::class)($this->getRecord(), $data, auth()->user(), $this->chosenRecipients($data), $data['nfse'] ?? []);
                    } catch (DomainException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();
                    }

                    Notification::make()->title('Baixa por boleto registrada. Declaração gerada e enviada.')->success()->send();
                    $this->notifyNfse($nfse);
                    $this->redirect(ChargeResource::getUrl('view', ['record' => $this->getRecord()]));
                }),
            Action::make('baixaManual')
                ->label('Baixa Manual')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->visible($open)
                ->modalHeading('Baixa Manual')
                ->modalSubmitActionLabel('Confirmar baixa')
                ->modalWidth('2xl')
                ->fillForm(fn () => [
                    'escritorio_id' => $this->getRecord()->escritorio_id,
                    'nfse' => NfseEmissionForm::defaults($this->getRecord(), NfseEmissionForm::SETTLEMENT),
                ])
                ->steps(fn () => $this->settlementSteps([
                    Textarea::make('justificativa')
                        ->label('Justificativa')
                        ->required()
                        ->minLength(3)
                        ->maxLength(1000)
                        ->rows(4),
                    Select::make('escritorio_id')
                        ->label('Escritório')
                        ->placeholder('Não Definido')
                        ->options(Escritorio::query()
                            ->where('pessoa_id', $this->getRecord()->embarque->pessoa_id_representante)
                            ->orderBy('nome')
                            ->pluck('nome', 'escritorio_id')),
                    Checkbox::make('liberacao')
                        ->label('Autoriza Liberação Embarque'),
                ], 'manual'))
                ->action(function (array $data, Action $action) {
                    try {
                        $nfse = app(ManualSettlement::class)(
                            $this->getRecord(),
                            $data['justificativa'],
                            filled($data['escritorio_id']) ? (int) $data['escritorio_id'] : null,
                            (bool) $data['liberacao'],
                            auth()->user(),
                            $this->chosenRecipients($data),
                            $data['nfse'] ?? [],
                        );
                    } catch (DomainException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();
                    }

                    Notification::make()->title('Baixa manual registrada.')->success()->send();
                    $this->notifyNfse($nfse);
                    $this->redirect(ChargeResource::getUrl('view', ['record' => $this->getRecord()]));
                }),
            Action::make('notaDebito')
                ->label('Nota de Débito SEM PAGAMENTO')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible($open)
                ->action(fn () => app(DebitNote::class)($this->getRecord())),
            // deleteCobranca do legado: só manager/admin/excluir_cobranca e sem NFS-e autorizada
            Action::make('excluir')
                ->label('Excluir')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn () => DeleteCharge::allowed(auth()->user()))
                ->requiresConfirmation()
                ->modalHeading('Excluir cobrança')
                ->modalDescription(fn () => ($reasons = DeleteCharge::blockingReasons($this->getRecord()))
                    ? 'Não é possível excluir a cobrança: '.implode(' ', $reasons)
                    : 'Tem certeza que deseja excluir esta cobrança? Itens, documentos, boletos e cotações negociadas também serão removidos.')
                ->modalSubmitAction(fn ($action) => $action->disabled((bool) DeleteCharge::blockingReasons($this->getRecord())))
                ->action(function (Action $action) {
                    try {
                        app(DeleteCharge::class)($this->getRecord(), auth()->user());
                    } catch (DomainException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();
                    }

                    Notification::make()->title('Registro excluído com sucesso.')->success()->send();
                    $this->redirect(ChargeResource::getUrl('index'));
                }),
        ];
    }

    /** FinChargesForm do legado: valor recebido sugerido é o total da IC. */
    private function paymentDefaults(): array
    {
        $r = $this->getRecord();
        $brl = fn ($v) => number_format((float) $v, 2, ',', '.');

        return [
            'ref_bank' => $r->ref_bank,
            'received_value' => $brl((float) $r->received_value > 0 ? $r->received_value : $r->total_value),
            'interest' => $brl($r->interest),
            'fine' => $brl($r->fine),
            'discount' => $brl($r->discount),
            'tax_boleto' => $brl($r->tax_boleto),
            'discount_obs' => $r->discount_obs,
            'payment_date' => $r->payment_date?->toDateString(),
            'payment_confirmation' => $r->payment_confirmation,
            'escritorio_id' => $r->escritorio_id,
            'autoriza_liberacao_embarque' => $r->autoriza_liberacao_embarque === 'on',
            'nfse' => NfseEmissionForm::defaults($r, NfseEmissionForm::SETTLEMENT),
        ];
    }

    /** Baixa em 3 passos: dados, emissão da NFS-e e o resumo de confirmação com os e-mails do cliente (_confirmaResumoBaixaNfse). */
    private function settlementSteps(array $fields, string $kind = 'baixa'): array
    {
        return [
            Step::make($kind === 'manual' ? 'Baixa manual' : 'Pagamento')
                ->schema($fields),
            Step::make('NFS-e')
                ->schema(NfseEmissionForm::fields($this->getRecord(), NfseEmissionForm::SETTLEMENT)),
            Step::make('Confirmação')
                ->schema($this->confirmation($kind)),
        ];
    }

    /**
     * _confirmaResumoBaixaNfse: resumo do que vai ser gravado e a escolha dos e-mails do cliente (emails_destino[]).
     * Os desmarcados não recebem recibo, declaração, IC nem NFS-e desta cobrança.
     */
    private function confirmation(string $kind): array
    {
        $recipients = app(ChargeRecipients::class)($this->getRecord(), all: true);

        return [
            Html::make(fn (Get $get) => $this->summary($kind, $get)),
            CheckboxList::make('emails_destino')
                ->label('E-mails do cliente que receberão os documentos')
                ->options(collect($recipients)->mapWithKeys(fn ($name, $email) => [$email => trim("{$name} <{$email}>")])->all())
                ->default(array_keys($recipients))
                ->bulkToggleable()
                ->helperText($recipients === [] ? 'Nenhum e-mail válido na lista de contatos.' : 'cobranca@dmflog.com.br recebe sempre.'),
        ];
    }

    private function summary(string $kind, Get $get): HtmlString
    {
        $row = fn (string $label, $value, bool $strong = false) => filled($value)
            ? '<tr><td style="padding:2px 12px 2px 0;font-weight:600;white-space:nowrap">'.e($label).'</td><td>'.($strong ? '<strong>'.e($value).'</strong>' : e($value)).'</td></tr>'
            : '';
        $date = fn ($d) => filled($d) ? Carbon::parse($d)->format('d/m/Y') : null;
        $office = fn ($id) => $id ? Escritorio::query()->whereKey($id)->value('nome') : null;
        $rows = match ($kind) {
            'baixa' => $row('Banco', DB::table('cmn_banks')->where('id', $get('ref_bank'))->value('name'))
                .$row('Valor Recebido', filled($get('received_value')) ? "R$ {$get('received_value')}" : null, true)
                .$row('Juros', $get('interest')).$row('Multa', $get('fine')).$row('Desconto', $get('discount')).$row('Taxa', $get('tax_boleto'))
                .$row('Data de Pagamento', $date($get('payment_date'))).$row('Data de Vencimento', $date($get('payment_confirmation')))
                .$row('Escritório', $office($get('escritorio_id'))).$row('Liberação do embarque', $get('autoriza_liberacao_embarque') ? 'Sim' : 'Não'),
            'manual' => $row('Justificativa', $get('justificativa')).$row('Escritório', $office($get('escritorio_id')))
                .$row('Liberação do embarque', $get('liberacao') ? 'Sim' : 'Não'),
            default => '',
        };
        $nfse = $get('nfse') ?? [];
        $emit = $kind === 'nfse' || ! empty($nfse['emitir_nfse']);
        $rows .= $row('Emitir NFS-e', $emit ? 'Sim' : 'Não', true);

        if ($emit) {
            $rows .= $row('Operação', IssueNfse::operationChoices()[$nfse['config_nfse_operacao_id'] ?? ''] ?? null)
                .$row('Tomador', trim(($nfse['nome'] ?? '').' ('.($nfse['cpf_cnpj'] ?? '').')'))
                .$row('Valor dos serviços', filled($nfse['valor_servicos'] ?? null) ? "R$ {$nfse['valor_servicos']}" : null, true)
                .$row('Competência', $nfse['competencia'] ?? null);
        }

        return new HtmlString("<table style=\"font-size:0.875rem\">{$rows}<tr><td style=\"padding:2px 12px 2px 0;font-weight:600\">Responsável</td><td>".e(auth()->user()->getFilamentName()).'</td></tr></table>');
    }

    /** emails_destino[] do resumo de confirmação. */
    private function chosenRecipients(array $data): ?array
    {
        return array_key_exists('emails_destino', $data) ? array_values($data['emails_destino'] ?? []) : null;
    }

    /** Recálculo com NFS-e autorizada (confirmarRecalculoComNfse do legado): motivo da substituição e e-mails de destino. */
    private function recalculationNfseFields(): array
    {
        $record = $this->getRecord();

        if (! $record->nfses()->get()->contains->canSubstitute()) {
            return [];
        }

        return [
            Html::make(new HtmlString('<p style="font-size:0.875rem">Esta IC tem NFS-e autorizada: após o recálculo a nota será <strong>substituída</strong> pelo valor atual e a IC reenviada ao cliente.</p>')),
            Select::make('nfse_subst_c_motivo')
                ->label('Motivo da substituição')
                ->options(FinChargesNfse::SUBSTITUTION_REASONS)
                ->default('99')
                ->required()
                ->live(),
            Textarea::make('nfse_subst_x_motivo')
                ->label('Justificativa')
                ->default(SubstituteNfse::RECALCULATION_REASON)
                ->required(fn (Get $get) => $get('nfse_subst_c_motivo') === '99')
                ->minLength(15)
                ->maxLength(255)
                ->rows(2),
            ...array_slice($this->confirmation('nfse'), 1),
        ];
    }

    /** Resultado da emissão de NFS-e (o flash do processarEmissaoNfse). */
    private function notifyNfse(?array $outcome): void
    {
        if ($outcome) {
            Notification::make()->title($outcome['message'])->status($outcome['status'])->send();
        }
    }

    /** Campos do _form do legado (baixas bancária e por boleto). */
    private function paymentFields(): array
    {
        $money = fn (string $name, string $label) => TextInput::make($name)
            ->label($label)
            ->prefix('R$')
            ->placeholder('0,00');

        return [
            Select::make('ref_bank')
                ->label('Banco')
                ->required()
                ->searchable()
                ->options(DB::table('cmn_banks')
                    ->whereNotNull('name')
                    ->where('name', '<>', '')
                    ->orderBy('name')
                    ->get(['id', 'name', 'bank_number'])
                    ->mapWithKeys(fn ($b) => [$b->id => "{$b->name} ({$b->bank_number})"])),
            Grid::make(2)
                ->schema([
                    $money('received_value', 'Valor Recebido')
                        ->required(),
                    $money('interest', 'Juros'),
                    $money('fine', 'Multa'),
                    $money('discount', 'Desconto'),
                    $money('tax_boleto', 'Taxa'),
                ]),
            Grid::make(2)
                ->schema([
                    DatePicker::make('payment_date')
                        ->label('Data de Pagamento')
                        ->required(),
                    DatePicker::make('payment_confirmation')
                        ->label('Data de Vencimento')
                        ->required(),
                ]),
            Textarea::make('discount_obs')
                ->label('Observações')
                ->rows(3),
            Select::make('escritorio_id')
                ->label('Escritório')
                ->placeholder('Não Definido')
                ->options(Escritorio::query()
                    ->where('pessoa_id', $this->getRecord()->embarque->pessoa_id_representante)
                    ->orderBy('nome')
                    ->pluck('nome', 'escritorio_id')),
            Checkbox::make('autoriza_liberacao_embarque')
                ->label('Autoriza Liberação Embarque'),
        ];
    }

    /** recalculaCobranca do legado; no "Recalcular" o modo (Somente esta/Todas) e o "Manter IOF" vêm do formulário. */
    private function recalculateAction(string $name, string $label, string $mode, bool $withoutIof = false): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-calculator')
            ->color('gray')
            ->modalHeading('Recalcular Cobrança')
            ->modalSubmitActionLabel('Recalcular')
            ->action(function (array $data, Action $action) use ($mode, $withoutIof) {
                $mode = ($data['todos'] ?? null) === RecalculateCharge::ALL ? RecalculateCharge::ALL : $mode;

                try {
                    $charge = app(RecalculateCharge::class)(
                        $this->getRecord(),
                        $mode,
                        $withoutIof,
                        (bool) ($data['manter_iof_atual'] ?? false),
                        auth()->user(),
                        array_key_exists('nfse_subst_c_motivo', $data) ? [
                            'reason' => $data['nfse_subst_c_motivo'],
                            'justification' => (string) ($data['nfse_subst_x_motivo'] ?? ''),
                            'emails' => $this->chosenRecipients($data),
                        ] : [],
                    );
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()
                    ->title('Cobrança recalculada com sucesso!')
                    ->success()
                    ->send();

                $this->redirect(ChargeResource::getUrl('view', ['record' => $charge]));
            });
    }

    /** Valor do recibo, disparado pelo lápis de cada linha da tabela de recibos (x-editable do legado). */
    public function editarValorReciboAction(): Action
    {
        return Action::make('editarValorRecibo')
            ->modalHeading('Informe o valor do recibo')
            ->modalWidth('sm')
            ->fillForm(fn (array $arguments) => [
                'valor' => ($v = $this->getRecord()->receipts()->find($arguments['id'])?->valor) !== null
                    ? number_format((float) $v, 2, ',', '.')
                    : null,
            ])
            ->schema([
                TextInput::make('valor')
                    ->label('Valor')
                    ->prefix('R$')
                    ->placeholder('0,00'),
            ])
            ->action(function (array $data, array $arguments, Action $action) {
                try {
                    app(EditReceiptValue::class)($this->getRecord(), (int) $arguments['id'], $data['valor']);
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Valor do recibo atualizado.')->success()->send();
            });
    }

    /** Nota de Débito (xls) de um recibo, disparada pelo ícone da tabela de recibos (xlsRecibo?rid= do legado). */
    public function notaDebitoReciboAction(): Action
    {
        return Action::make('notaDebitoRecibo')
            ->action(fn (array $arguments) => app(DebitNote::class)(
                $this->getRecord(),
                $this->getRecord()->receipts()->findOrFail($arguments['id'])->getKey(),
            ));
    }

    /** substituirRecibo do legado: redesenha o PDF do recibo com os dados atuais (mesmo arquivo). */
    public function substituirReciboAction(): Action
    {
        return Action::make('substituirRecibo')
            ->requiresConfirmation()
            ->modalHeading('Substituir recibo')
            ->modalDescription('O PDF atual será sobrescrito com os dados atuais do BL. Deseja continuar?')
            ->modalSubmitActionLabel('Sim, substituir')
            ->action(function (array $arguments, Action $action) {
                try {
                    app(GenerateReceipts::class)->replace($this->getRecord(), (int) $arguments['id']);
                } catch (DomainException $e) {
                    Notification::make()->title("Não foi possível substituir o recibo: {$e->getMessage()}")->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Recibo substituído com os dados atuais do BL.')->success()->send();
            });
    }

    /** reenviaRecibo do legado: reenvia o PDF do recibo ao cliente. */
    public function reenviarReciboAction(): Action
    {
        return Action::make('reenviarRecibo')
            ->requiresConfirmation()
            ->modalHeading('Reenviar recibo')
            ->modalDescription('Reenviar este recibo ao setor de cobrança e aos contatos do cliente?')
            ->modalSubmitActionLabel('Sim, reenviar')
            ->action(function (array $arguments, Action $action) {
                try {
                    app(ResendReceipt::class)($this->getRecord(), (int) $arguments['id']);
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Recibo reenviado.')->success()->send();
            });
    }

    /** downloadXmlNfse do legado. */
    public function baixarXmlNfseAction(): Action
    {
        return $this->nfseDownloadAction('baixarXmlNfse', 'xml', 'XML da NFSe indisponível no momento.');
    }

    /** downloadPdfNfse do legado (DANFSe gerado pelo emissor). */
    public function baixarPdfNfseAction(): Action
    {
        return $this->nfseDownloadAction('baixarPdfNfse', 'pdf', 'PDF DANFSe indisponível no momento.');
    }

    /** atualizarStatusNfse do legado. */
    public function atualizarStatusNfseAction(): Action
    {
        return Action::make('atualizarStatusNfse')
            ->action(function (array $arguments) {
                $nfse = app(RefreshStatus::class)($this->nfse($arguments['id']));

                $nfse->cancellationFailed()
                    ? Notification::make()
                        ->title('Cancelamento falhou — a nota continua autorizada e pode ser cancelada de novo.')
                        ->body(implode(' ', $nfse->readableErrors()) ?: 'a Receita rejeitou o evento.')
                        ->danger()
                        ->send()
                    : Notification::make()->title("Status da NFSe atualizado: {$nfse->status}")->success()->send();
            });
    }

    /** cancelarNfse do legado: motivo 1/2/9; justificativa obrigatória no 9 (15 a 255 caracteres). */
    public function cancelarNfseAction(): Action
    {
        return Action::make('cancelarNfse')
            ->modalHeading('Cancelar NFS-e')
            ->modalDescription('Selecione o motivo do cancelamento da NFS-e.')
            ->modalSubmitActionLabel('Cancelar NFS-e')
            ->color('danger')
            ->modalWidth('lg')
            ->schema([
                Select::make('c_motivo')
                    ->label('Motivo')
                    ->options(CancelNfse::REASONS)
                    ->default('1')
                    ->required()
                    ->live(),
                Textarea::make('x_motivo')
                    ->label('Justificativa')
                    ->helperText('Se preenchida, deve ter entre 15 e 255 caracteres.')
                    ->required(fn ($get) => $get('c_motivo') === '9')
                    ->minLength(15)
                    ->maxLength(255)
                    ->rows(3),
            ])
            ->action(function (array $data, array $arguments, Action $action) {
                try {
                    $message = app(CancelNfse::class)($this->nfse($arguments['id']), $data['c_motivo'], $data['x_motivo'] ?? null, auth()->id());
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title($message)->success()->send();
            });
    }

    /** substituirNfse / salvarSubstituicaoNfse do legado. */
    public function substituirNfseAction(): Action
    {
        return $this->nfseFormAction('substituirNfse', 'Substituir NFS-e', NfseEmissionForm::SUBSTITUTE, function (FinChargesNfse $nfse, array $data) {
            $result = app(SubstituteNfse::class)($nfse, $data['nfse'], auth()->id());

            return [$result['ok'], $result['message']];
        });
    }

    /** reprocessarNfse / salvarReprocessamentoNfse do legado: nova emissão de uma nota rejeitada/com erro. */
    public function reprocessarNfseAction(): Action
    {
        return $this->nfseFormAction('reprocessarNfse', 'Reprocessar NFS-e', NfseEmissionForm::REPROCESS, function (FinChargesNfse $nfse, array $data, ?array $emails) {
            $result = app(IssueNfse::class)->reprocess($nfse, ['emitir_nfse' => true, ...$data['nfse']], auth()->id());
            $n = $data['nfse'];

            FinChargesLog::forceCreate([
                'ref_charge' => $nfse->ref_charge,
                'acao' => 'alteracao',
                'historico' => "Reprocessamento NFS-e (origem: {$nfse->id_externo}"
                    .(filled($n['competencia'] ?? null) ? "; competência: {$n['competencia']}" : '')
                    .(filled($n['valor_servicos'] ?? null) ? "; valor: {$n['valor_servicos']}" : '')
                    ."). {$result['message']}".FinCharges::recipientsLogSummary($emails),
                'created_at' => now(),
                'created_by' => auth()->id(),
            ]);

            return [$result['ok'], $result['message']];
        });
    }

    /** Modal com o formulário de emissão para uma nota existente (o id vem da tabela de NFS-e). */
    private function nfseFormAction(string $name, string $heading, string $mode, callable $handle): Action
    {
        return Action::make($name)
            ->modalHeading($heading)
            ->modalWidth('3xl')
            ->modalSubmitActionLabel($heading)
            ->fillForm(fn (array $arguments) => ['nfse' => NfseEmissionForm::defaults($this->getRecord(), $mode, $this->nfse($arguments['id']))])
            ->schema(fn (array $arguments) => [
                ...NfseEmissionForm::fields($this->getRecord(), $mode, $this->nfse($arguments['id'])),
                ...array_slice($this->confirmation('nfse'), 1),
            ])
            ->action(function (array $data, array $arguments, Action $action) use ($handle) {
                $nfse = $this->nfse($arguments['id']);
                $emails = $this->chosenRecipients($data);
                $record = $this->getRecord();
                $record->selectRecipients($emails ?? []);
                $record->save();

                [$ok, $message] = $handle($nfse, $data, $emails);

                Notification::make()->title($message)->status($ok ? 'success' : 'danger')->send();

                if (! $ok) {
                    $action->halt();
                }
            });
    }

    private function nfseDownloadAction(string $name, string $type, string $unavailable): Action
    {
        return Action::make($name)
            ->action(function (array $arguments, Action $action) use ($type, $unavailable) {
                $nfse = $this->nfse($arguments['id']);
                $response = filled($nfse->nfse_uuid) ? app(NfseApi::class)->{$type}($nfse->nfse_uuid) : null;
                $valid = $response && $response['ok'] && $response['raw'] !== '' && ($type === 'xml' || str_starts_with($response['raw'], '%PDF'));

                if (! $valid) {
                    Notification::make()->title($unavailable)->danger()->send();
                    $action->halt();
                }

                $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $nfse->id_externo);

                return response()->streamDownload(
                    fn () => print ($response['raw']),
                    $type === 'xml' ? "nfse-{$base}.xml" : "danfse-{$base}.pdf",
                    ['Content-Type' => $type === 'xml' ? 'application/xml; charset=UTF-8' : 'application/pdf'],
                );
            });
    }

    /** NFS-e desta cobrança (o id vem do navegador). */
    private function nfse(int|string $id): FinChargesNfse
    {
        return FinChargesNfse::where('ref_charge', $this->getRecord()->getKey())->findOrFail($id);
    }

    /** Recibo em PDF sem a arte de fundo (geraReciboPlain do legado), disparado pela tabela de recibos. */
    public function reciboSemFundoAction(): Action
    {
        return Action::make('reciboSemFundo')
            ->action(fn (array $arguments) => app(PlainReceiptPdf::class)($this->getRecord(), (int) $arguments['id']));
    }

    /** Remoção de cotação negociada, disparada pela lixeira de cada linha da tabela. */
    public function removerCotacaoAction(): Action
    {
        return Action::make('removerCotacao')
            ->requiresConfirmation()
            ->modalHeading('Remover Cotação Negociada')
            ->modalDescription('Tem certeza que deseja remover este registro?')
            ->color('danger')
            ->action(function (array $arguments) {
                app(DeleteCustomRate::class)($this->getRecord(), (int) $arguments['id']);
                Notification::make()->title('Cotação negociada removida.')->success()->send();
            });
    }

    /** Edição do IOF, disparada pelo lápis no rodapé da tabela de itens (x-editable do legado). */
    public function editarIofAction(): Action
    {
        return Action::make('editarIof')
            ->modalHeading('Alterar IOF')
            ->modalWidth('sm')
            ->fillForm(fn () => ['valor_iof' => number_format((float) $this->getRecord()->valor_iof, 2, ',', '.')])
            ->schema([
                TextInput::make('valor_iof')
                    ->label('Despesas Financeiras/IOF')
                    ->prefix('R$')
                    ->placeholder('0,00')
                    ->required(),
            ])
            ->action(function (array $data, Action $action) {
                try {
                    app(EditIof::class)($this->getRecord(), $data['valor_iof'], auth()->id());
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('IOF atualizado.')->success()->send();
            });
    }

    /** Flag de IOF manual (toggleCtrValorIof do legado), disparada pelo checkbox da tabela de itens. */
    public function alternarIofManualAction(): Action
    {
        return Action::make('alternarIofManual')
            ->action(function () {
                $record = $this->getRecord();
                $record->forceFill(['ctr_valor_iof' => $record->ctr_valor_iof ? null : 1])->save();

                Notification::make()
                    ->title($record->ctr_valor_iof ? 'IOF manual ativado.' : 'IOF manual desativado.')
                    ->success()
                    ->send();
            });
    }

    public function getHeading(): string
    {
        return 'Cobrança';
    }

    public function getSubheading(): string
    {
        return app(ChargeDetails::class)->heading($this->getRecord());
    }
}

<?php

namespace App\Filament\Resources\Charges\Schemas;

use App\Actions\Cobranca\Listing\ChargeDueDate;
use App\Actions\Cobranca\Maintenance\EditDueDate;
use App\Actions\Cobranca\Maintenance\EditPartyData;
use App\Actions\Cobranca\Rates\SaveCustomRate;
use App\Enums\Cobranca\ChargeStatus;
use App\Models\Escritorio;
use App\Models\FinCharges;
use App\Services\Cobranca\ChargeDetails;
use Carbon\CarbonInterface;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Página da cobrança (showSuccess do legado): coluna esquerda com os dados financeiros, direita com baixa/recibos/NFS-e. */
class ChargeInfolist
{
    /** Adicionar Cotação Negociada (todos os campos obrigatórios, como no legado). */
    private static function addCustomRate(): Action
    {
        return Action::make('adicionarCotacao')
            ->label('Adicionar Cotação Negociada')
            ->icon(Heroicon::PlusCircle)
            ->link()
            ->modalHeading('Adicionar Cotação Negociada')
            ->modalWidth('md')
            ->schema(fn (FinCharges $record) => [
                Select::make('ref_currency')->label('Moeda')->required()
                    ->options(SaveCustomRate::currencyOptions($record)),
                TextInput::make('custom_rate')->label('Valor Negociado')->required()->placeholder('0,0000'),
                Textarea::make('note')->label('Observações')->required(),
            ])
            ->action(function (array $data, FinCharges $record, Action $action) {
                try {
                    app(SaveCustomRate::class)($record, (int) $data['ref_currency'], $data['custom_rate'], $data['note'], auth()->id());
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Cotação negociada cadastrada.')->success()->send();
            });
    }

    /** Edição do vencimento direto na página (x-editable do legado), pré-preenchida com o prazo final. */
    private static function editDueDate(): Action
    {
        return Action::make('editarVencimento')
            ->icon(Heroicon::PencilSquare)
            ->tooltip('Alterar vencimento')
            ->modalHeading('Alterar vencimento')
            ->modalWidth('sm')
            ->fillForm(fn (FinCharges $record) => [
                'due_date' => (app(ChargeDueDate::class)->final($record) ?? $record->due_date)?->toDateString(),
            ])
            ->schema([DatePicker::make('due_date')->label('Data de Vencimento')->required()])
            ->action(function (array $data, FinCharges $record, Action $action) {
                try {
                    app(EditDueDate::class)($record, $data['due_date'], auth()->id());
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title('Vencimento atualizado.')->success()->send();
            });
    }

    /** Dados do Exportador/Importador editados direto na página (x-editable textarea do legado). */
    private static function editPartyData(string $field, string $label): Action
    {
        return Action::make("editar_{$field}")
            ->icon(Heroicon::PencilSquare)
            ->tooltip("Alterar {$label}")
            ->modalHeading("Alterar {$label}")
            ->modalWidth('md')
            ->fillForm(fn (FinCharges $record) => [$field => $record->{$field}])
            ->schema([Textarea::make($field)->label($label)->rows(5)])
            ->action(function (array $data, FinCharges $record) use ($field, $label) {
                app(EditPartyData::class)($record, $field, $data[$field], auth()->id());
                Notification::make()->title("{$label} atualizado.")->success()->send();
            });
    }

    /** Escritório do agente (editEscritorio do legado): só os escritórios do representante do embarque. */
    private static function editOffice(): Action
    {
        return Action::make('editarEscritorio')
            ->icon(Heroicon::PencilSquare)
            ->tooltip('Alterar escritório')
            ->modalHeading('Alterar escritório')
            ->modalWidth('sm')
            ->fillForm(fn (FinCharges $record) => ['escritorio_id' => $record->escritorio_id])
            ->schema(fn (FinCharges $record) => [
                Select::make('escritorio_id')
                    ->label('Escritório')
                    ->placeholder('Não Definido')
                    ->options(Escritorio::query()
                        ->where('pessoa_id', $record->embarque->pessoa_id_representante)
                        ->orderBy('nome')
                        ->pluck('nome', 'escritorio_id')),
            ])
            ->action(function (array $data, FinCharges $record) {
                $record->forceFill(['escritorio_id' => $data['escritorio_id']])->save();
                Notification::make()->title('Escritório atualizado.')->success()->send();
            });
    }

    /** Adicionar Escritório (addEscritorioBaixa do legado): cadastra no representante do embarque. */
    private static function addOffice(): Action
    {
        return Action::make('adicionarEscritorio')
            ->label('Adicionar Escritório')
            ->icon(Heroicon::PlusCircle)
            ->link()
            ->modalHeading('Adicionar Escritório')
            ->modalWidth('md')
            ->schema([
                TextInput::make('nome')->label('Nome')->required()->maxLength(255),
                TextInput::make('nif')->label('NIF')->required()->maxLength(255),
                Textarea::make('observacoes')->label('Observações')->rows(5),
            ])
            ->action(function (array $data, FinCharges $record) {
                Escritorio::forceCreate([...$data, 'pessoa_id' => $record->embarque->pessoa_id_representante]);
                Notification::make()->title('Escritório cadastrado.')->success()->send();
            });
    }

    public static function configure(Schema $schema): Schema
    {
        $details = fn () => app(ChargeDetails::class);
        $brl = fn (mixed $value) => 'R$ '.number_format((float) $value, 2, ',', '.');
        $rows = fn (string $name, callable $source) => RepeatableEntry::make($name)
            ->hiddenLabel()
            ->contained(false)
            ->columns(2)
            ->state(fn (FinCharges $r) => $source($r))
            ->schema([
                TextEntry::make('label')
                    ->hiddenLabel()
                    ->weight('bold'),
                TextEntry::make('value')
                    ->hiddenLabel()
                    ->placeholder('—')
                    ->alignEnd(),
            ]);
        // linha rótulo/valor no mesmo formato de $rows, com ação de edição no valor
        $editable = fn (string $label, TextEntry $value) => Grid::make(['default' => 2])
            ->schema([
                TextEntry::make("{$value->getName()}_label")
                    ->hiddenLabel()
                    ->state($label)
                    ->weight('bold'),
                $value
                    ->hiddenLabel()
                    ->alignEnd(),
            ]);
        $booking = fn (FinCharges $r) => once(fn () => $details()->booking($r));
        $hasAgent = fn (FinCharges $r) => (bool) $r->embarque->pessoa_id_representante;
        $status = fn (int ...$in) => fn (FinCharges $r) => in_array($r->status->value, $in, true);

        return $schema
            ->components([
                Grid::make(2)
                    ->columnSpanFull()
                    ->schema([
                        Group::make([
                            Section::make('Informações Financeiras')
                                ->schema([
                                    TextEntry::make('status')
                                        ->label('Status')
                                        ->badge()
                                        ->formatStateUsing(fn (ChargeStatus $state) => $state->label())
                                        ->color(fn (ChargeStatus $state) => $state->isSettled() ? 'success' : 'gray'),
                                    TextEntry::make('type.name')
                                        ->label('Tipo'),
                                    Grid::make(['default' => 2])
                                        ->schema([
                                            TextEntry::make('created_at')
                                                ->label('Data (Cobrança)')
                                                ->date('d/m/Y'),
                                            TextEntry::make('vencimento')
                                                ->label('Data de Vencimento')
                                                ->state(fn (FinCharges $r) => app(ChargeDueDate::class)->display($r))
                                                ->formatStateUsing(fn ($state) => $state instanceof CarbonInterface
                                                    ? $state->format('d/m/Y')
                                                    : $state)
                                                ->suffixAction(self::editDueDate()),
                                        ]),
                                    $rows('booking_head', fn (FinCharges $r) => $booking($r)['head']),
                                    $editable('Dados do Exportador', TextEntry::make('nome_exportador')
                                        ->default('—')
                                        ->html()
                                        ->formatStateUsing(fn ($state) => nl2br(e((string) $state)))
                                        ->suffixAction(self::editPartyData('nome_exportador', 'Dados do Exportador'))),
                                    $rows('booking_importer', fn (FinCharges $r) => $booking($r)['importer']),
                                    $editable('Dados do Importador', TextEntry::make('nome_importador')
                                        ->default('—')
                                        ->html()
                                        ->formatStateUsing(fn ($state) => nl2br(e((string) $state)))
                                        ->suffixAction(self::editPartyData('nome_importador', 'Dados do Importador'))),
                                    $rows('booking_agent', fn (FinCharges $r) => $booking($r)['agent']),
                                    $editable('Escritório', TextEntry::make('escritorio.nome')
                                        ->default('Não Definido')
                                        ->suffixAction(self::editOffice()))
                                        ->visible($hasAgent),
                                    Actions::make([self::addOffice()])
                                        ->key('escritorio_actions')
                                        ->visible($hasAgent),
                                    $rows('booking_tail', fn (FinCharges $r) => $booking($r)['tail']),
                                ]),
                            Section::make('Containers')
                                ->schema([
                                    RepeatableEntry::make('containers')
                                        ->hiddenLabel()
                                        ->columns(3)
                                        ->state(fn (FinCharges $r) => $details()->containers($r))
                                        ->schema([
                                            TextEntry::make('type')
                                                ->label('Equipamento'),
                                            TextEntry::make('number')
                                                ->label('Numero'),
                                            TextEntry::make('factor')
                                                ->label('Quantidade'),
                                        ]),
                                ]),
                            Section::make('Cotações Negociadas')
                                ->key('cotacoes')
                                ->headerActions([self::addCustomRate()])
                                ->schema([
                                    ViewEntry::make('negotiated_rates')
                                        ->hiddenLabel()
                                        ->view('filament.charges.custom-rates-table')
                                        ->state(fn (FinCharges $r) => $details()->customRates($r)),
                                ]),
                            Section::make('Itens da Cobrança')
                                ->schema([
                                    ViewEntry::make('items_table')
                                        ->hiddenLabel()
                                        ->view('filament.charges.items-table')
                                        ->state(fn (FinCharges $r) => [
                                            'items' => $details()->items($r),
                                            'discount' => (float) $r->discount > 0 && $r->discount_status ? $brl($r->discount) : null,
                                            'iof' => $brl($r->valor_iof),
                                            'iof_manual' => (bool) $r->ctr_valor_iof,
                                            // legado: total gravado menos o desconto
                                            'total' => $brl((float) $r->total_value - (float) $r->discount),
                                        ]),
                                ]),
                            Section::make('Contatos da Cobrança')
                                ->schema([
                                    RepeatableEntry::make('contacts')
                                        ->hiddenLabel()
                                        ->columns(3)
                                        ->state(fn (FinCharges $r) => $details()->contacts($r))
                                        ->schema([
                                            TextEntry::make('name')
                                                ->label('Nome'),
                                            TextEntry::make('email')
                                                ->label('Email'),
                                            TextEntry::make('type')
                                                ->label('Tipo'),
                                        ]),
                                ]),
                            Section::make('Histórico da IC')
                                ->schema([
                                    RepeatableEntry::make('history')
                                        ->hiddenLabel()
                                        ->columns(5)
                                        ->state(fn (FinCharges $r) => $details()->history($r))
                                        ->placeholder('Nenhum evento registrado ainda.')
                                        ->schema([
                                            TextEntry::make('date')
                                                ->label('Data'),
                                            TextEntry::make('event')
                                                ->label('Evento')
                                                ->weight('bold'),
                                            TextEntry::make('user')
                                                ->label('Usuário'),
                                            TextEntry::make('status')
                                                ->label('Status'),
                                            TextEntry::make('detail')
                                                ->label('Detalhe')
                                                ->placeholder('—'),
                                        ]),
                                ]),
                        ]),
                        Group::make([
                            Section::make('Baixa Bancária')
                                ->visible($status(1))
                                ->schema([
                                    $rows('bank', fn (FinCharges $r) => $details()->bankSettlement($r)),
                                ]),
                            Section::make('Recibos')
                                ->visible($status(1))
                                ->schema([
                                    RepeatableEntry::make('receipts')
                                        ->hiddenLabel()
                                        ->columns(3)
                                        ->placeholder('Nenhum recibo.')
                                        ->state(fn (FinCharges $r) => $details()->receipts($r))
                                        ->schema([
                                            TextEntry::make('id')
                                                ->label('Nº'),
                                            TextEntry::make('file')
                                                ->label('Arquivo')
                                                ->placeholder('—'),
                                            TextEntry::make('value')
                                                ->label('Valor')
                                                ->placeholder('—')
                                                ->alignEnd(),
                                        ]),
                                ]),
                            Section::make('Baixa Por Boleto')
                                ->visible($status(2))
                                ->schema([
                                    $rows('boleto', fn (FinCharges $r) => $details()->boletoSettlement($r)),
                                ]),
                            Section::make('Declarações')
                                ->visible($status(2))
                                ->schema([
                                    RepeatableEntry::make('declarations')
                                        ->hiddenLabel()
                                        ->columns(2)
                                        ->placeholder('Nenhuma declaração.')
                                        ->state(fn (FinCharges $r) => $details()->declarations($r))
                                        ->schema([
                                            TextEntry::make('file')
                                                ->label('Arquivo')
                                                ->placeholder('—'),
                                            TextEntry::make('due')
                                                ->label('Vencimento')
                                                ->placeholder('—'),
                                        ]),
                                ]),
                            Section::make('Baixa Manual')
                                ->visible($status(3))
                                ->schema([
                                    TextEntry::make('obs')
                                        ->label('Justificativa')
                                        ->placeholder('—')
                                        ->html()
                                        ->formatStateUsing(fn ($state) => nl2br(e((string) $state))),
                                ]),
                            Section::make('Recibo do Boleto')
                                ->visible(fn (FinCharges $r) => count($details()->boletoReceipts($r)) > 0)
                                ->schema([
                                    RepeatableEntry::make('boleto_receipts')
                                        ->hiddenLabel()
                                        ->state(fn (FinCharges $r) => $details()->boletoReceipts($r))
                                        ->schema([
                                            TextEntry::make('file')
                                                ->hiddenLabel(),
                                        ]),
                                ]),
                            Section::make('NFSe')
                                ->visible(fn (FinCharges $r) => $r->nfses()->exists() || $r->status->isSettled())
                                ->schema([
                                    RepeatableEntry::make('nfse_rows')
                                        ->hiddenLabel()
                                        ->columns(6)
                                        ->placeholder('Nenhuma NFSe emitida para esta cobrança.')
                                        ->state(fn (FinCharges $r) => $details()->nfses($r))
                                        ->schema([
                                            TextEntry::make('external_id')
                                                ->label('Id externo'),
                                            TextEntry::make('key')
                                                ->label('Chave de acesso')
                                                ->placeholder('—')
                                                ->copyable(),
                                            TextEntry::make('value')
                                                ->label('Valor')
                                                ->alignEnd(),
                                            TextEntry::make('status')
                                                ->label('Status')
                                                ->badge()
                                                ->color(fn (string $state) => match ($state) {
                                                    'Autorizada' => 'success',
                                                    'Rejeitada', 'Erro' => 'danger',
                                                    'Cancelada' => 'gray',
                                                    'Substituída' => 'info',
                                                    default => 'warning',
                                                }),
                                            TextEntry::make('date')
                                                ->label('Data'),
                                            TextEntry::make('danfse')
                                                ->label('DANFSe')
                                                ->placeholder('—')
                                                ->url(fn ($state) => $state)
                                                ->openUrlInNewTab()
                                                ->formatStateUsing(fn ($state) => filled($state) ? 'Abrir' : null),
                                            TextEntry::make('notes')
                                                ->hiddenLabel()
                                                ->color('danger')
                                                ->columnSpanFull()
                                                ->visible(fn ($state) => filled($state))
                                                ->html()
                                                ->formatStateUsing(fn ($state) => nl2br(e((string) $state))),
                                        ]),
                                ]),
                            Section::make('Documentos enviados')
                                ->visible($status(0, 2, 3, 1))
                                ->schema([
                                    RepeatableEntry::make('documents')
                                        ->hiddenLabel()
                                        ->columns(2)
                                        ->placeholder('Nenhum documento enviado.')
                                        ->state(fn (FinCharges $r) => $details()->sentDocuments($r))
                                        ->schema([
                                            TextEntry::make('file')
                                                ->label('Arquivo'),
                                            TextEntry::make('date')
                                                ->label('Data')
                                                ->placeholder('—'),
                                        ]),
                                ]),
                        ]),
                    ]),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Actions\Booking\Notes\ChangeNoteItems;
use App\Actions\Booking\Notes\ConvertNoteToEuro;
use App\Actions\Booking\Notes\CreateNote;
use App\Actions\Booking\Notes\GenerateNotePdf;
use App\Actions\Booking\Notes\RemoveNote;
use App\Actions\Booking\Notes\SendNote;
use App\Actions\Booking\Notes\ToggleNoteNeed;
use App\Actions\Booking\Notes\UpdateNote;
use App\Enums\NoteType;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Abas "Debit Note" e "Credit Note" da reserva (listDebitNote, debitNote, editDebitNote e as equivalentes do crédito, no legado).
 * PDF (com a versão sem valores no crédito), conversão para euro e envio por e-mail ao financeiro e ao agente.
 */
abstract class NotesRelationManager extends RelationManager
{
    abstract protected function type(): NoteType;

    public function isReadOnly(): bool
    {
        return false;
    }

    /** _show_tab.php: reserva cancelada ou suspensa só mostra as notas a diretor e financeiro; as demais, a todos da reserva. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return in_array($ownerRecord->finalizado, ['c', 'p'], true)
            ? (bool) auth()->user()?->hasLegacyCredential(['director', 'financial'])
            : true;
    }

    public function form(Schema $schema): Schema
    {
        $debit = $this->type() === NoteType::Debit;
        $money = fn (string $name, string $label) => TextInput::make($name)
            ->label($label)
            ->numeric()
            ->step(0.01);

        return $schema
            ->components([
                Grid::make(3)
                    ->schema([
                        TextInput::make('dolar_rate')
                            ->label('Cotação da moeda')
                            ->required()
                            ->numeric()
                            ->step(0.0001),
                        $money('due_to_dmf', 'Due to DMF')
                            ->required(),
                        $money('due_to_dmf_agent', 'Due to Agent')
                            ->required(),
                        $money('due_to_cnee_agent', 'Due to Cnee Agent')
                            ->required(),
                        $money('due_to_shipper_agent', 'Due to Shipper Agent')
                            ->required(),
                        $money('exw_expenses', 'EXW Expenses'),
                        $money('handling', 'Handling')
                            ->visible($debit),
                        $money('origin_comission', 'Origin Comission')
                            ->visible($debit),
                        DatePicker::make('data_vencimento')
                            ->label('Data de vencimento')
                            ->visible($debit),
                    ]),
                Textarea::make('obs')
                    ->label('Observação')
                    ->rows(4),
            ]);
    }

    public function table(Table $table): Table
    {
        $type = $this->type();
        $debit = $type === NoteType::Debit;
        $money = fn (string $column) => TextColumn::make($column)
            ->numeric(2, ',', '.');

        return $table
            ->recordTitleAttribute('name')
            ->headerActions([
                Action::make('nova')
                    ->label('Nova nota')
                    ->icon('heroicon-o-plus')
                    ->action(function () use ($type) {
                        try {
                            $id = app(CreateNote::class)($this->getOwnerRecord(), $type, [], auth()->id());
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title($this->noteName($id).' criada.')->success()->send();
                    }),
                Action::make('semNota')
                    ->label(fn () => $this->needsNote() ? 'Marcar: não precisa de nota' : 'Desmarcar: não precisa de nota')
                    ->icon('heroicon-o-no-symbol')
                    ->color('gray')
                    ->schema([
                        Textarea::make('justification')
                            ->label('Justificativa')
                            ->required(),
                    ])
                    ->action(function (array $data) use ($type) {
                        app(ToggleNoteNeed::class)($type, $this->getOwnerRecord()->getKey(), $data['justification'], auth()->id());
                        $this->getOwnerRecord()->refresh();
                    }),
            ])
            ->recordActions([
                Action::make('adicionarDespesa')
                    ->label('Adicionar despesa')
                    ->icon('heroicon-o-plus-circle')
                    ->iconButton()
                    ->schema(fn (Model $record) => [
                        Select::make('expense')
                            ->label('Despesa')
                            ->options($this->expenseOptions($record, false))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Model $record, array $data) use ($type) {
                        try {
                            app(ChangeNoteItems::class)->add($type, $record->getKey(), (int) $data['expense']);
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('removerDespesa')
                    ->label('Remover despesa')
                    ->icon('heroicon-o-minus-circle')
                    ->iconButton()
                    ->color('danger')
                    ->visible(fn (Model $record) => $this->expenseOptions($record, true) !== [])
                    ->schema(fn (Model $record) => [
                        Select::make('expense')
                            ->label('Despesa')
                            ->options($this->expenseOptions($record, true))
                            ->required(),
                    ])
                    ->action(fn (Model $record, array $data) => app(ChangeNoteItems::class)->remove($type, $record->getKey(), (int) $data['expense'])),
                EditAction::make()
                    ->modalWidth('5xl')
                    ->using(function (Model $record, array $data) use ($type) {
                        app(UpdateNote::class)($type, $record->getKey(), $data, auth()->id());

                        return $record->refresh();
                    }),
                Action::make('pdf')
                    ->label('Gerar PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->iconButton()
                    ->action(fn (Model $record) => $this->downloadPdf($record, false)),
                Action::make('pdfSemValores')
                    ->label('Gerar PDF (sem valores)')
                    ->icon('heroicon-o-eye-slash')
                    ->iconButton()
                    ->visible(! $debit)
                    ->action(fn (Model $record) => $this->downloadPdf($record, true)),
                Action::make('converterEuro')
                    ->label('Converter para EURO')
                    ->icon('heroicon-o-currency-euro')
                    ->iconButton()
                    ->visible(fn (Model $record) => (int) $record->moeda_id !== ConvertNoteToEuro::EURO)
                    ->modalHeading('Converter nota para EURO')
                    ->modalDescription('Não será possível desfazer esta operação. O PDF é gerado de novo.')
                    ->schema(fn (Model $record) => [
                        TextInput::make('euro_rate')
                            ->label('Cotação do EURO')
                            ->required()
                            ->numeric()
                            ->step(0.0001),
                        TextInput::make('factor')
                            ->label('Fator de conversão')
                            ->required()
                            ->numeric()
                            ->step(0.0001)
                            ->default($record->fator_conversao ?: null),
                    ])
                    ->action(function (Model $record, array $data) use ($type) {
                        try {
                            app(ConvertNoteToEuro::class)($type, $record->getKey(), (float) $data['factor'], (float) $data['euro_rate'], auth()->id());
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Nota convertida para EURO.')->success()->send();
                    }),
                Action::make('enviar')
                    ->label('Enviar PDF para Financeiro/Agente')
                    ->icon('heroicon-o-paper-airplane')
                    ->iconButton()
                    ->requiresConfirmation()
                    ->modalDescription('Envia o último PDF gerado desta nota.')
                    ->action(function (Model $record) use ($type) {
                        try {
                            app(SendNote::class)($type, $record->getKey(), auth()->id());
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Nota enviada.')->success()->send();
                    }),
                Action::make('remover')
                    ->label('Remover')
                    ->icon('heroicon-o-trash')
                    ->iconButton()
                    ->color('danger')
                    ->visible(fn () => (bool) auth()->user()?->hasLegacyCredential(['manager', 'admin', 'financial']))
                    ->modalHeading('Remover '.$type->label())
                    ->schema([
                        Textarea::make('justification')
                            ->label('Justificativa')
                            ->required(),
                    ])
                    ->action(fn (Model $record, array $data) => app(RemoveNote::class)($type, $record->getKey(), $data['justification'], auth()->id())),
            ])
            ->emptyStateHeading('Não há nenhuma nota cadastrada.')
            ->columns([
                TextColumn::make('name')
                    ->label($type->label()),
                TextColumn::make('created_at')
                    ->label('Data')
                    ->date('d/m/Y'),
                TextColumn::make('agent.nome')
                    ->label('Agente'),
                TextColumn::make('data_vencimento')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->visible($debit),
                TextColumn::make('itens')
                    ->label('Despesas')
                    ->state(fn (Model $record) => array_values($this->expenseOptions($record, true)))
                    ->listWithLineBreaks(),
                $money('gross_profit')
                    ->label('Gross Profit'),
                $money('handling')
                    ->label('Handling')
                    ->visible($debit),
                $money('due_to_dmf')
                    ->label('Due to DMF'),
                $money('due_to_dmf_agent')
                    ->label('Due to Agent'),
                $money('exw_expenses')
                    ->label('EXW')
                    ->visible($debit),
                $money('origin_comission')
                    ->label('Origin Comission')
                    ->visible($debit),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->state(fn (Model $record) => $this->value($record))
                    ->numeric(2, ',', '.'),
                TextColumn::make('arquivo')
                    ->label('PDF')
                    ->state(fn (Model $record) => $record->ref_file ? DB::table('cmn_files')->where('id', $record->ref_file)->value('name').'.pdf' : null),
                TextColumn::make('status_pagamento')
                    ->label('Status')
                    ->formatStateUsing(fn ($state) => (int) $state === 1 ? 'Baixada' : 'Aberto'),
                IconColumn::make('status')
                    ->label('Enviada')
                    ->boolean(),
            ]);
    }

    /** getValor (legado): débito soma as partes; crédito de importação vale a parte da DMF, o de exportação a do agente e do cnee. */
    private function value(Model $note): float
    {
        if ($this->type() === NoteType::Debit) {
            return (float) ($note->due_to_dmf + $note->handling + $note->origin_comission + $note->due_to_shipper_agent + $note->exw_expenses);
        }

        return (float) ($this->getOwnerRecord()->impor_expor === 'i' ? $note->due_to_dmf : $note->due_to_dmf_agent + $note->due_to_cnee_agent);
    }

    /** @return array<int, string> despesas da reserva que estão (ou não) na nota */
    private function expenseOptions(Model $note, bool $inNote): array
    {
        $type = $this->type();
        $inNoteIds = DB::table($type->itemsTable())->where($type->itemColumn(), $note->getKey())->select('ref_expense');

        return DB::table('fin_expenses_values as v')
            ->join('fin_expenses as e', 'e.id', '=', 'v.ref_expense')
            ->leftJoin('fin_expenses_unities as u', 'u.id', '=', 'e.ref_unity')
            ->where('v.ref_embarque', $note->ref_embarque)
            ->when($inNote, fn ($q) => $q->whereIn('v.id', $inNoteIds), fn ($q) => $q->whereNotIn('v.id', $inNoteIds))
            ->orderBy('e.name')
            ->orderBy('v.id')
            ->get(['v.id', 'e.name', 'u.acronym', 'v.buying_rate', 'v.selling_rate'])
            ->mapWithKeys(fn (object $v) => [$v->id => $v->name.($v->acronym ? " / {$v->acronym}" : '').' (B/R '.number_format($v->buying_rate, 2, ',', '.').' · S/R '.number_format($v->selling_rate, 2, ',', '.').')'])
            ->all();
    }

    private function needsNote(): bool
    {
        $marked = $this->getOwnerRecord()->{$this->type()->needColumn()};

        return blank($marked) || $marked === '0000-00-00 00:00:00';
    }

    private function downloadPdf(Model $note, bool $hideValues): mixed
    {
        $path = app(GenerateNotePdf::class)($this->type(), $note->getKey(), auth()->id(), $hideValues);

        return Storage::disk('tracking_uploads')->download($path);
    }

    private function noteName(int $id): string
    {
        return (string) DB::table($this->type()->table())->where('id', $id)->value('name');
    }
}

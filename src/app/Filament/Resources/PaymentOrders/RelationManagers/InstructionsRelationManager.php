<?php

namespace App\Filament\Resources\PaymentOrders\RelationManagers;

use App\Actions\PaymentOrders\DeletePaymentInstruction;
use App\Actions\PaymentOrders\EditPaymentIof;
use App\Actions\PaymentOrders\GeneratePaymentInstruction;
use App\Actions\PaymentOrders\PaymentInstructionPdf;
use App\Models\InstrucaoPagamento;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Instruções de Pagamento da ordem (showSuccess do legado): valor, IOF e total de cada uma, o PDF e a exclusão. */
class InstructionsRelationManager extends RelationManager
{
    protected static string $relationship = 'instructions';

    protected static ?string $title = 'Instruções de Pagamento';

    protected static ?string $recordTitleAttribute = 'instrucao_pagamento_id';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('instrucao_pagamento.*')
                ->addSelect(['items_total' => DB::table('instrucao_pagamento_item as i')->selectRaw('COALESCE(SUM(i.valor), 0)')->whereColumn('i.instrucao_pagamento_id', 'instrucao_pagamento.instrucao_pagamento_id')])
                ->orderBy('instrucao_pagamento.instrucao_pagamento_id'))
            ->paginated(false)
            ->headerActions([
                Action::make('gerarInstrucao')
                    ->label('Gerar instrução de pagamento')
                    ->icon('heroicon-o-document-plus')
                    ->requiresConfirmation()
                    ->modalHeading('Gerar a instrução de pagamento?')
                    ->modalDescription('Grava os itens ligados da ordem, com o IOF atual.')
                    ->action(function (Action $action) {
                        try {
                            app(GeneratePaymentInstruction::class)($this->getOwnerRecord());
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }

                        Notification::make()->title('Instrução de pagamento gerada.')->success()->send();
                    }),
            ])
            ->columns([
                TextColumn::make('code')
                    ->label('Instrução')
                    ->state(fn (InstrucaoPagamento $record) => $record->code()),
                TextColumn::make('items_total')
                    ->label('Valor')
                    ->formatStateUsing(fn ($state) => 'BRL '.number_format((float) $state, 2, ',', '.')),
                TextColumn::make('valor_iof')
                    ->label('IOF')
                    ->formatStateUsing(fn ($state) => 'BRL '.number_format((float) $state, 2, ',', '.')),
                TextColumn::make('total')
                    ->label('Total')
                    ->state(fn (InstrucaoPagamento $record) => 'BRL '.number_format((float) $record->items_total + (float) $record->valor_iof, 2, ',', '.')),
            ])
            ->recordActions([
                Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(fn (InstrucaoPagamento $record) => app(PaymentInstructionPdf::class)($record)),
                Action::make('iof')
                    ->label('IOF')
                    ->icon('heroicon-o-pencil-square')
                    ->modalWidth('sm')
                    ->fillForm(fn (InstrucaoPagamento $record) => ['iof' => $record->valor_iof])
                    ->schema([TextInput::make('iof')->label('IOF (R$)')->numeric()->minValue(0)->required()])
                    ->action(function (array $data, InstrucaoPagamento $record, Action $action) {
                        try {
                            app(EditPaymentIof::class)->instruction($record, (float) $data['iof']);
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }

                        Notification::make()->title('IOF atualizado.')->success()->send();
                    }),
                DeleteAction::make()
                    ->modalHeading('Confirmar exclusão?')
                    ->using(function (InstrucaoPagamento $record) {
                        app(DeletePaymentInstruction::class)($record);

                        return true;
                    }),
            ]);
    }
}

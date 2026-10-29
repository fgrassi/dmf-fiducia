<?php

namespace App\Filament\Resources\OtherPaymentOrders\RelationManagers;

use App\Actions\PaymentOrders\EditPaymentIof;
use App\Actions\PaymentOrders\Other\DeleteOtherPaymentInstruction;
use App\Actions\PaymentOrders\Other\OtherPaymentInstructionPdf;
use App\Models\InstrucaoPagamentoOutros;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Instruções de Pagamento da ordem (showSuccess do legado): uma por parte, com valor, IOF e total, o PDF e a exclusão.
 * A criação e a atualização saem do recálculo da ordem; o legado não tem botão de gerar.
 */
class InstructionsRelationManager extends RelationManager
{
    protected static string $relationship = 'instructions';

    protected static ?string $title = 'Instruções de Pagamento';

    protected static ?string $recordTitleAttribute = 'instrucao_pagamento_outros_id';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $money = fn ($state) => 'BRL '.number_format((float) $state, 2, ',', '.');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('instrucao_pagamento_outros.*')
                ->addSelect(['items_total' => DB::table('instrucao_pagamento_item_outros as i')->selectRaw('COALESCE(SUM(i.valor), 0)')->whereColumn('i.instrucao_pagamento_outros_id', 'instrucao_pagamento_outros.instrucao_pagamento_outros_id')])
                ->orderBy('instrucao_pagamento_outros.instrucao_pagamento_outros_id'))
            ->paginated(false)
            ->columns([
                TextColumn::make('recebedor')
                    ->label('Recebedor'),
                TextColumn::make('cnpj_recebedor')
                    ->label('CNPJ'),
                TextColumn::make('dados_bancarios')
                    ->label('Dados Bancários')
                    ->wrap(),
                TextColumn::make('items_total')
                    ->label('Valor')
                    ->formatStateUsing($money),
                TextColumn::make('valor_iof')
                    ->label('IOF')
                    ->formatStateUsing($money),
                TextColumn::make('total')
                    ->label('Total')
                    ->state(fn (InstrucaoPagamentoOutros $record) => $money((float) $record->items_total + (float) $record->valor_iof))
                    ->summarize(Summarizer::make()
                        ->label('TOTAL')
                        ->using(fn (QueryBuilder $query) => (float) $query->sum('valor_iof') + (float) DB::table('instrucao_pagamento_item_outros')->whereIn('instrucao_pagamento_outros_id', $query->pluck('instrucao_pagamento_outros_id'))->sum('valor'))
                        ->formatStateUsing(fn ($state) => 'R$ '.number_format((float) $state, 2, ',', '.'))),
            ])
            ->recordActions([
                Action::make('pdf')
                    ->label(fn (InstrucaoPagamentoOutros $record) => $record->code())
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(fn (InstrucaoPagamentoOutros $record) => app(OtherPaymentInstructionPdf::class)($record)),
                Action::make('iof')
                    ->label('IOF')
                    ->icon('heroicon-o-pencil-square')
                    ->modalWidth('sm')
                    ->fillForm(fn (InstrucaoPagamentoOutros $record) => ['iof' => $record->valor_iof])
                    ->schema([TextInput::make('iof')->label('IOF (R$)')->numeric()->minValue(0)->required()])
                    ->action(function (array $data, InstrucaoPagamentoOutros $record, Action $action) {
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
                    ->using(function (InstrucaoPagamentoOutros $record) {
                        app(DeleteOtherPaymentInstruction::class)($record);

                        return true;
                    }),
            ]);
    }
}

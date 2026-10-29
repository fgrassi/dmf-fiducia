<?php

namespace App\Filament\Resources\OtherPaymentOrders\RelationManagers;

use App\Actions\PaymentOrders\Other\EditTaPayment;
use App\Models\TaBaixa;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** "Baixas TA" da ordem (showSuccess do legado): só em ordem com a despesa TA; alterar e excluir só sem capa vinculada. */
class TaPaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'taPayments';

    protected static ?string $title = 'Baixas TA';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageName): bool
    {
        return app(EditTaPayment::class)->orderHasTa($ownerRecord);
    }

    public function table(Table $table): Table
    {
        $user = fn (string $column) => DB::table('usuario')->select('nome')->whereColumn('usuario.usuario_id', "ta_baixa.{$column}");

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('ta_baixa.*')
                ->addSelect(['created_by_name' => $user('usuario_id'), 'updated_by_name' => $user('updated_by')])
                ->orderBy('data_pagamento')
                ->orderBy('ta_baixa_id'))
            ->paginated(false)
            ->defaultSort(null)
            ->columns([
                TextColumn::make('data_pagamento')
                    ->label('Data')
                    ->date('d/m/Y'),
                TextColumn::make('valor_ta')
                    ->label('Valor TA')
                    ->numeric(2, ',', '.')
                    ->summarize(Sum::make()->label('Total')->numeric(2, ',', '.')),
                TextColumn::make('taxa')
                    ->label('Taxa')
                    ->numeric(4, ',', '.'),
                TextColumn::make('valor_pago')
                    ->label('Valor pago')
                    ->prefix('R$ ')
                    ->numeric(2, ',', '.')
                    ->summarize(Sum::make()->label('Total')->prefix('R$ ')->numeric(2, ',', '.')),
                TextColumn::make('created_by_name')
                    ->label('Criado por'),
                TextColumn::make('updated_by_name')
                    ->label('Alterado por'),
                TextColumn::make('capa_processo_despesa_item_id')
                    ->label('Capa')
                    ->formatStateUsing(fn ($state) => $state ? 'Sim' : 'Não'),
            ])
            ->recordActions([
                Action::make('alterar')
                    ->label('Alterar baixa')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (TaBaixa $record) => $record->canChange())
                    ->modalWidth('sm')
                    ->modalHeading('Alterar baixa de TA')
                    ->fillForm(fn (TaBaixa $record) => [
                        'rate' => $record->taxa,
                        'paid' => $record->valor_pago,
                        'date' => $record->data_pagamento?->toDateString(),
                    ])
                    ->schema([
                        TextInput::make('rate')->label('Taxa')->numeric()->minValue(0.0001)->required(),
                        TextInput::make('paid')->label('Valor pago (R$)')->numeric()->minValue(0.01)->required(),
                        DatePicker::make('date')->label('Data de pagamento')->required(),
                    ])
                    ->action(function (array $data, TaBaixa $record, Action $action) {
                        try {
                            app(EditTaPayment::class)->update($this->getOwnerRecord(), $record, (float) $data['rate'], (float) $data['paid'], $data['date'], auth()->id());
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }

                        Notification::make()->title('Baixa atualizada.')->success()->send();
                    }),
                Action::make('excluir')
                    ->label('Excluir baixa')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (TaBaixa $record) => $record->canChange())
                    ->requiresConfirmation()
                    ->modalHeading('Excluir a baixa de TA?')
                    ->action(function (TaBaixa $record, Action $action) {
                        try {
                            app(EditTaPayment::class)->delete($this->getOwnerRecord(), $record);
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }

                        Notification::make()->title('Baixa excluída.')->success()->send();
                    }),
            ]);
    }
}

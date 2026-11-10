<?php

namespace App\Filament\Resources\Couriers\Tables;

use App\Actions\Courier\DeleteCourier;
use App\Filament\Actions\CourierAgentExpenseAction;
use App\Filament\Actions\CourierPdfAction;
use App\Filament\Resources\Couriers\CourierResource;
use App\Filament\Resources\Couriers\Schemas\CourierForm;
use App\Models\ControleCourier;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * list do legado: número, data, tipo, conta, nota e valor se editam na linha, até o courier virar despesa do agente
 * (`status_vinculo_nota = 's'`); depois disso as colunas ficam travadas.
 */
class CouriersTable
{
    public static function configure(Table $table): Table
    {
        $linked = fn (ControleCourier $record) => $record->isLinked();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('type'))
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('data_recebimento')->orderByDesc('controle_courier_id'))
            ->paginationPageOptions([50, 100])
            ->defaultPaginationPageOption(50)
            ->recordUrl(fn (ControleCourier $record) => CourierResource::getUrl('view', ['record' => $record]))
            ->filtersFormWidth('3xl')
            ->filtersFormColumns(2)
            ->columns([
                TextColumn::make('controle_courier_id')
                    ->label('Id')
                    ->sortable(),
                TextInputColumn::make('numero')
                    ->label('Número Courrier')
                    ->rules(['required', 'max:255'])
                    ->disabled($linked)
                    ->searchable(),
                TextInputColumn::make('data_recebimento')
                    ->label('Data de Recebimento')
                    ->type('date')
                    ->rules(['required', 'date'])
                    ->disabled($linked)
                    ->sortable(),
                SelectColumn::make('tipo_courier_id')
                    ->label('Tipo do Courrier')
                    ->options(fn () => CourierForm::types())
                    ->selectablePlaceholder(false)
                    ->rules(['required'])
                    ->disabled($linked),
                SelectColumn::make('conta_utilizada')
                    ->label('Conta')
                    ->options(ControleCourier::ACCOUNTS)
                    ->selectablePlaceholder(false)
                    ->disabled($linked),
                TextColumn::make('nota')
                    ->label('Nota')
                    ->placeholder('N/D'),
                TextInputColumn::make('valor')
                    ->label('Valor (USD)')
                    ->type('number')
                    ->step(0.01)
                    ->rules(['nullable', 'numeric'])
                    ->disabled($linked),
                TextColumn::make('status_vinculo_nota')
                    ->label('Nota Vinculada')
                    ->badge()
                    ->state(fn (ControleCourier $record) => $record->isLinked() ? 'SIM' : 'NÃO')
                    ->color(fn (string $state) => $state === 'SIM' ? 'success' : 'gray'),
            ])
            ->filters([
                Filter::make('busca')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextInput::make('numero')->label('Número'),
                        TextInput::make('booking')->label('Booking'),
                        DatePicker::make('data_recebimento')->label('Data de Recebimento'),
                        TextInput::make('nota')->label('Nota'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        $like = fn (string $value) => '%'.trim($value).'%';

                        return $query
                            ->when(filled(trim((string) ($data['numero'] ?? ''))), fn (Builder $query) => $query->where('numero', 'like', $like($data['numero'])))
                            ->when(filled(trim((string) ($data['nota'] ?? ''))), fn (Builder $query) => $query->where('nota', 'like', $like($data['nota'])))
                            ->when(filled($data['data_recebimento'] ?? null), fn (Builder $query) => $query->whereDate('data_recebimento', $data['data_recebimento']))
                            ->when(filled(trim((string) ($data['booking'] ?? ''))), fn (Builder $query) => $query->whereIn(
                                'controle_courier_id',
                                DB::table('ctr_courier_embarque')
                                    ->join('embarque', 'embarque.embarque_id', '=', 'ctr_courier_embarque.embarque_id')
                                    ->where('embarque.booking', 'like', $like($data['booking']))
                                    ->select('ctr_courier_embarque.controle_courier_id'),
                            ));
                    }),
                SelectFilter::make('tipo_courier_id')
                    ->label('Tipo Courrier')
                    ->options(fn () => CourierForm::types()),
                SelectFilter::make('conta_utilizada')
                    ->label('Conta')
                    ->options(ControleCourier::ACCOUNTS),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Exibir'),
                Action::make('nota')
                    ->label('Nota')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->hidden($linked)
                    ->modalHeading('Número da Nota')
                    ->modalWidth('md')
                    ->fillForm(fn (ControleCourier $record) => ['nota' => $record->nota])
                    ->schema([CourierForm::note()])
                    ->action(fn (array $data, ControleCourier $record) => $record->update(['nota' => $data['nota'] ?: null])),
                CourierPdfAction::make(),
                CourierAgentExpenseAction::make(),
                Action::make('excluir')
                    ->label('Excluir')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->hidden($linked)
                    ->requiresConfirmation()
                    ->modalHeading('Confirmar exclusão?')
                    ->modalDescription('Os embarques e nomes vinculados também são apagados.')
                    ->action(function (ControleCourier $record, DeleteCourier $delete) {
                        try {
                            $delete($record);
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Registro Removido com Sucesso')->success()->send();
                    }),
            ]);
    }
}

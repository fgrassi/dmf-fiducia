<?php

namespace App\Filament\Resources\Boxes\Tables;

use App\Actions\BoxControl\DeleteBox;
use App\Filament\Resources\Boxes\BoxResource;
use App\Models\ControleCaixa;
use App\Models\ControleCaixaEmbarque;
use App\Models\Embarque;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** list do legado: abertas primeiro, depois as mais recentes. */
class BoxesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('bookings'))
            ->defaultSort(fn (Builder $query) => $query->orderBy('status')->orderByDesc('controle_caixa_id'))
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->recordUrl(fn (ControleCaixa $record) => BoxResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('controle_caixa_id')
                    ->label('Número')
                    ->searchable(),
                SelectColumn::make('tipo_caixa')
                    ->label('Tipo')
                    ->options(ControleCaixa::TYPES)
                    ->selectablePlaceholder(false),
                TextInputColumn::make('nome_caixa')
                    ->label('Nome')
                    ->placeholder('Não determinado.')
                    ->rules(['max:255'])
                    ->searchable(),
                TextColumn::make('bookings_count')
                    ->label('Nº Processos'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => ControleCaixa::STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        ControleCaixa::OPEN => 'success',
                        ControleCaixa::CLOSED => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Filter::make('booking')
                    ->label('Embarque')
                    ->schema([
                        Select::make('booking_id')
                            ->label('Embarque')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Embarque::query()
                                ->where('booking', 'like', "%{$search}%")
                                ->orderByDesc('embarque_id')
                                ->limit(30)
                                ->pluck('booking', 'embarque_id'))
                            ->getOptionLabelUsing(fn ($value) => Embarque::find($value)?->booking),
                    ])
                    ->query(fn (Builder $query, array $data) => $query->when($data['booking_id'], fn (Builder $query, $id) => $query->whereIn(
                        'controle_caixa_id',
                        ControleCaixaEmbarque::where('embarque_id', $id)->select('controle_caixa_id'),
                    ))),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Exibir'),
                Action::make('excluir')
                    ->label('Excluir')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Confirmar exclusão?')
                    ->modalDescription('Os vínculos com as reservas também são apagados.')
                    ->action(function (ControleCaixa $record, DeleteBox $delete) {
                        $delete($record);
                        Notification::make()->title('Registro Removido com Sucesso')->success()->send();
                    }),
            ]);
    }
}

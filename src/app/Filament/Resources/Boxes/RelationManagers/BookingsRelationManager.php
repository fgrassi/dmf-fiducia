<?php

namespace App\Filament\Resources\Boxes\RelationManagers;

use App\Actions\BoxControl\AddBookingToBox;
use App\Actions\BoxControl\RemoveBookingFromBox;
use App\Filament\Resources\Boxes\BoxResource;
use App\Models\ControleCaixa;
use App\Models\ControleCaixaEmbarque;
use App\Models\Embarque;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** "Relação de Processos na Caixa" (showSuccess do legado): inclui, edita a observação e remove reservas. */
class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    protected static ?string $title = 'Relação de Processos na Caixa';

    public function isReadOnly(): bool
    {
        return false;
    }

    /** O legado deixa trocar a reserva de uma linha já gravada sem conferir nada; aqui só se edita a observação. */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('obs')
                    ->label('Observação')
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('booking.cliente')->orderByDesc('controle_caixa_embarque_id'))
            ->paginated(false)
            ->emptyStateHeading('Nenhum embarque relacionado.')
            ->columns([
                TextColumn::make('booking.booking')
                    ->label('Booking'),
                TextColumn::make('booking.house_bl')
                    ->label('BL'),
                TextColumn::make('booking.cliente.nome')
                    ->label('Cliente')
                    ->wrap(),
                TextColumn::make('obs')
                    ->label('Observação')
                    ->wrap(),
            ])
            ->headerActions([
                Action::make('incluir')
                    ->label('Incluir Embarque')
                    ->icon(Heroicon::OutlinedPlus)
                    ->modalWidth('md')
                    ->schema([
                        Select::make('booking_id')
                            ->label('Embarque')
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Embarque::query()
                                ->where('booking', 'like', "%{$search}%")
                                ->orderByDesc('embarque_id')
                                ->limit(30)
                                ->pluck('booking', 'embarque_id'))
                            ->getOptionLabelUsing(fn ($value) => Embarque::find($value)?->booking),
                        TextInput::make('obs')
                            ->label('Observação')
                            ->maxLength(255),
                    ])
                    ->action(function (array $data, AddBookingToBox $add, Action $action) {
                        try {
                            $add($this->getOwnerRecord(), (int) $data['booking_id'], $data['obs'] ?: null);
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }

                        Notification::make()->title('Embarque Incluído com Sucesso.')->success()->send();

                        return $this->reloadBox();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth('md'),
                DeleteAction::make()
                    ->modalHeading('Confirmar exclusão?')
                    ->using(fn (ControleCaixaEmbarque $record, RemoveBookingFromBox $remove) => $remove($record))
                    ->successNotificationTitle('Embarque Removido com Sucesso')
                    ->after(fn () => $this->reloadBox()),
            ]);
    }

    /** O status e a contagem do topo mudam com a inclusão e a remoção. */
    private function reloadBox(): mixed
    {
        /** @var ControleCaixa $box */
        $box = $this->getOwnerRecord();

        return $this->redirect(BoxResource::getUrl('view', ['record' => $box]));
    }
}

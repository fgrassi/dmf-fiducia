<?php

namespace App\Filament\Resources\Couriers\RelationManagers;

use App\Actions\Courier\AddCourierName;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * "Não Vinculado a Reserva" (showSuccess do legado): nomes livres de quem recebeu o courier sem reserva.
 *
 * Como no legado, só a inclusão trava com a nota vinculada; remover um nome continua liberado (não entra na despesa).
 */
class NamesRelationManager extends RelationManager
{
    protected static string $relationship = 'names';

    protected static ?string $title = 'Não Vinculado a Reserva';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->orderBy('ctr_courier_sem_embarque_id'))
            ->paginated(false)
            ->emptyStateHeading('Nenhum nome vinculado.')
            ->columns([
                TextColumn::make('nome')
                    ->label('Nome')
                    ->wrap(),
            ])
            ->headerActions([
                Action::make('incluir')
                    ->label('Vincular Nome')
                    ->icon(Heroicon::OutlinedPlus)
                    ->hidden(fn () => $this->getOwnerRecord()->isLinked())
                    ->modalWidth('md')
                    ->schema([
                        Textarea::make('nome')
                            ->label('Nome')
                            ->required()
                            ->rows(4),
                    ])
                    ->action(function (array $data, AddCourierName $add, Action $action) {
                        try {
                            $add($this->getOwnerRecord(), $data['nome']);
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();
                        }

                        Notification::make()->title('Nome vinculado.')->success()->send();
                    }),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->modalHeading('Confirmar exclusão?')
                    ->successNotificationTitle('Removido com Sucesso'),
            ]);
    }
}

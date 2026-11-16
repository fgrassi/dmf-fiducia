<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Actions\Booking\Remarks\AddRemark;
use App\Actions\Booking\Remarks\RemoveRemark;
use App\Actions\Booking\Remarks\SaveBookingNotes;
use App\Models\Embarque;
use App\Models\ObservacaoEmbarque;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Observações da reserva (observacoes / addObservacaoEmbarque / removerLembrete / saveEmbarqueObs do booking, legado). */
class RemarksRelationManager extends RelationManager
{
    protected static string $relationship = 'remarks';

    protected static ?string $title = 'Observações';

    public function isReadOnly(): bool
    {
        return false;
    }

    /** Como o botão do legado: lembretes mais a observação do embarque, se houver. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->remarks()->count() + (filled($ownerRecord->embarque_obs) ? 1 : 0);

        return $count > 0 ? (string) $count : null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('mensagem')
                    ->label('Mensagem')
                    ->required()
                    ->rows(4),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('author'))
            ->paginated(false)
            ->emptyStateHeading('Nenhuma observação')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('author.nome')
                    ->label('Autor'),
                TextColumn::make('mensagem')
                    ->label('Mensagem')
                    ->wrap(),
            ])
            ->headerActions([
                $this->bookingNotesAction(),
                CreateAction::make()
                    ->label('Adicionar observação')
                    ->using(fn (array $data) => app(AddRemark::class)($this->getOwnerRecord()->getKey(), $data['mensagem'], auth()->id())),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->modalHeading('Remover observação?')
                    ->using(fn (ObservacaoEmbarque $record) => app(RemoveRemark::class)($record)),
            ]);
    }

    /** Campo embarque_obs: sai na instrução de cobrança. */
    private function bookingNotesAction(): Action
    {
        return Action::make('bookingNotes')
            ->label('Observação do embarque')
            ->color('gray')
            ->modalHeading('Observação do embarque')
            ->fillForm(fn (): array => ['embarque_obs' => $this->getOwnerRecord()->embarque_obs])
            ->schema([
                Textarea::make('embarque_obs')
                    ->label('Observação')
                    ->rows(6),
            ])
            ->action(function (array $data): void {
                /** @var Embarque $booking */
                $booking = $this->getOwnerRecord();

                app(SaveBookingNotes::class)($booking, $data['embarque_obs'], auth()->id());

                Notification::make()
                    ->title('Observação do embarque salva')
                    ->success()
                    ->send();
            });
    }
}

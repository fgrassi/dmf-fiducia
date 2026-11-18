<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Actions\Booking\Schedule\ScheduleBooking;
use App\Models\Embarque;
use App\Models\Transborda;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Programação de Embarque da reserva (booking/programacaoEmbarque do legado): grava o cut off e lança no rastreamento a
 * mensagem "booking programado" (ou "alterado"), que sai por e-mail aos contatos. Fica de fora a edição do lançamento: uma
 * mudança de programação entra como novo lançamento "alteração".
 */
class ScheduleRelationManager extends RelationManager
{
    protected static string $relationship = 'trackings';

    protected static ?string $title = 'Programação de Embarque';

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ! in_array($ownerRecord->finalizado, ['c', 'p'], true);
    }

    public function form(Schema $schema): Schema
    {
        /** @var Embarque $booking */
        $booking = $this->getOwnerRecord();

        return $schema->components([
            Toggle::make('altera')
                ->label('Alteração'),
            DatePicker::make('data_reg')
                ->label('Data')
                ->default(today())
                ->required(),
            DateTimePicker::make('cutt_off')
                ->label('Cut Off')
                ->default($booking->cutt_off)
                ->seconds(false)
                ->required(),
            Textarea::make('descr')
                ->label('Descrição')
                ->rows(4),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where('ref_frase_transborda', ScheduleBooking::PHRASE)->with('author'))
            ->paginated(false)
            ->emptyStateHeading('Nenhuma programação lançada')
            ->columns([
                TextColumn::make('data_reg')
                    ->label('Data')
                    ->date('d/m/Y'),
                TextColumn::make('descr')
                    ->label('Descrição')
                    ->formatStateUsing(fn (?string $state) => nl2br(e($state)))
                    ->html()
                    ->wrap(),
                TextColumn::make('author.nome')
                    ->label('Usuário'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar programação')
                    ->using(function (array $data): Transborda {
                        $schedule = app(ScheduleBooking::class);
                        $tracking = $schedule($this->getOwnerRecord(), $data, auth()->id());

                        if ($schedule->unsent !== []) {
                            Notification::make()
                                ->title('Atenção! Um ou mais contatos não possuíam e-mail válido ou o envio falhou.')
                                ->warning()
                                ->send();
                        }

                        return $tracking;
                    }),
            ])
            ->recordActions([
                DeleteAction::make(),
            ]);
    }
}

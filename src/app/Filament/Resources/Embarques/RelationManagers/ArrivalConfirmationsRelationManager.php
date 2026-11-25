<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Actions\Booking\Confirmation\ArrivalMessage;
use App\Actions\Booking\Confirmation\ConfirmArrival;
use App\Actions\Booking\Confirmation\RemoveArrival;
use App\Actions\Booking\Tracking\FillTrackingPhrase;
use App\Filament\Support\ReportFields;
use App\Models\FraseTransborda;
use App\Models\Transborda;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Subaba "Chegada" do grupo Confirmação (booking/confirmacaoChegada do legado). Importação marítima escolhe o tipo
 * (ArrivalMessage); exportação e aéreo escolhem a frase da categoria 11 e escrevem o texto.
 */
class ArrivalConfirmationsRelationManager extends DepartureConfirmationsRelationManager
{
    protected static string $relationship = 'arrivalTrackings';

    protected string $confirmationRelation = 'arrivalConfirmations';

    protected static ?string $title = 'Confirmação de chegada';

    protected static ?string $emptyLabel = 'Chegada ainda não confirmada';

    protected string $createLabel = 'Confirmar chegada';

    protected string $dateField = 'dtePrevista';

    protected string $dateLabel = 'Previsão de chegada';

    public function form(Schema $schema): Schema
    {
        $booking = $this->booking();
        $phrase = ConfirmArrival::usesPhrase($booking);

        return $schema->components([
            Select::make('frase')
                ->label('Frase')
                ->options(fn () => FraseTransborda::query()->where('categoria_id', 11)->orderBy('valor')->pluck('valor', 'id'))
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(fn ($state, $set) => $set('descr', $state ? app(FillTrackingPhrase::class)($booking, (string) FraseTransborda::find($state)?->frase) : ''))
                ->visible($phrase),
            Textarea::make('descr')
                ->label('Descrição')
                ->rows(8)
                ->required()
                ->visible($phrase),
            Select::make('kind')
                ->label('Tipo')
                ->options(ArrivalMessage::KINDS)
                ->required()
                ->live()
                ->visible(! $phrase),
            DatePicker::make('data_reg')
                ->label('Data')
                ->default(today())
                ->required(),
            TextInput::make('vg')
                ->label('VG')
                ->required(fn ($get) => in_array((int) $get('kind'), [ArrivalMessage::ARRIVAL, ArrivalMessage::BERTHING], true))
                ->visible(! $phrase),
            ReportFields::ship('navio_id_destino', 'Navio de destino')
                ->default($booking->navio_id_destino),
            DateTimePicker::make('dtePrevista')
                ->label('Previsão de chegada')
                ->default(now())
                ->maxDate(now()->endOfDay())
                ->required(),
            Toggle::make('finance_confirmed')
                ->label('Confirmado com o setor Financeiro?')
                ->accepted()
                ->visible(fn () => ConfirmArrival::needsFinanceConfirmation($this->booking())),
        ]);
    }

    protected function needsFinanceConfirmation(): bool
    {
        return ConfirmArrival::needsFinanceConfirmation($this->booking());
    }

    protected function recordData(array $data): array
    {
        return $data + [
            'frase' => $data['ref_frase_transborda'] ?? null,
            'kind' => ($kind = array_search($data['ref_frase_transborda'] ?? null, ArrivalMessage::PHRASES, true)) === false ? null : $kind,
            'navio_id_destino' => $this->booking()->navio_id_destino,
        ];
    }

    protected function perform(array $data, ?Transborda $record): Transborda
    {
        $confirm = app(ConfirmArrival::class);
        $tracking = $confirm($this->booking(), $data, auth()->id(), $record);
        $this->warnIfUnsent($confirm->sent);

        return $tracking;
    }

    protected function remove(Transborda $record): void
    {
        app(RemoveArrival::class)($this->booking(), $record, auth()->id());
    }
}

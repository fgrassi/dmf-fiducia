<?php

namespace App\Filament\Actions;

use App\Actions\CurrencyRates\SaveRateValue;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** "Lançamento de Cotações" (currencyRateValues, currencyRateArmadorValues e currencyRateArmadorPaymentValues, legado). */
class RateValueAction
{
    /** Valor de hoje da cotação da linha (edição inline do legado). */
    public static function forRecord(string $kind): Action
    {
        return Action::make('launchValue')
            ->label('Lançar valor')
            ->icon('heroicon-o-currency-dollar')
            ->modalHeading('Informe o valor atualizado')
            ->modalWidth('sm')
            ->schema([self::rateInput()])
            ->action(fn (array $data, Model $record) => self::save($kind, $record->getKey(), $data['rate']));
    }

    /**
     * "Incluir Novo Lançamento": cotação, valor e data (hoje por padrão).
     *
     * @param  array<int|string, string>  $options  cotações disponíveis
     */
    public static function forAny(string $kind, array $options): Action
    {
        return Action::make('launchAnyValue')
            ->label('Incluir Novo Lançamento')
            ->icon('heroicon-o-plus')
            ->modalWidth('md')
            ->schema([
                Select::make('rate_id')
                    ->label('Cotação')
                    ->options($options)
                    ->searchable()
                    ->required(),
                self::rateInput(),
                DatePicker::make('rate_date')
                    ->label('Data')
                    ->default(now())
                    ->required()
                    ->maxDate($kind === SaveRateValue::CURRENCY ? null : now()),
            ])
            ->action(fn (array $data) => self::save($kind, (int) $data['rate_id'], $data['rate'], $data['rate_date']));
    }

    private static function rateInput(): TextInput
    {
        return TextInput::make('rate')
            ->label('Valor')
            ->numeric()
            ->required();
    }

    private static function save(string $kind, int $rateId, string|float $rate, ?string $date = null): void
    {
        try {
            (new SaveRateValue)($kind, $rateId, (float) $rate, $date, auth()->id());
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Cotação lançada.')->send();
    }
}

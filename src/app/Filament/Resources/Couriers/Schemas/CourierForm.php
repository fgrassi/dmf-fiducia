<?php

namespace App\Filament\Resources\Couriers\Schemas;

use App\Actions\Courier\CreateCourierType;
use App\Actions\Courier\NextCourierNumber;
use App\Models\ControleCourier;
use App\Models\TipoCourier;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;

/** Campos de createSuccess do legado (número, nota, data, tipo e conta). */
class CourierForm
{
    /** @return list<mixed> */
    public static function create(): array
    {
        return [
            TextInput::make('numero')
                ->label('Número')
                ->required()
                ->maxLength(255),
            self::note(),
            DatePicker::make('data_recebimento')
                ->label('Data de Recebimento')
                ->default(now())
                ->required(),
            Select::make('tipo_courier_id')
                ->label('Tipo Courrier')
                ->required()
                ->options(fn () => self::types())
                ->createOptionForm([
                    TextInput::make('nome')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255),
                ])
                ->createOptionUsing(fn (array $data) => app(CreateCourierType::class)($data['nome'])->getKey()),
            Select::make('conta_utilizada')
                ->label('Conta')
                ->options(ControleCourier::ACCOUNTS)
                ->default('d')
                ->selectablePlaceholder(false)
                ->required(),
        ];
    }

    /** Nota com o "Solicitar número" do legado, que gasta um número da sequência a cada clique. */
    public static function note(): TextInput
    {
        return TextInput::make('nota')
            ->label('Nota')
            ->maxLength(255)
            ->suffixAction(Action::make('solicitarNumero')
                ->label('Solicitar número')
                ->icon('heroicon-o-hashtag')
                ->requiresConfirmation()
                ->modalHeading('Solicitar número')
                ->modalDescription('Tem certeza?')
                ->action(fn (Set $set) => $set('nota', (string) app(NextCourierNumber::class)())));
    }

    /** @return array<int, string> */
    public static function types(): array
    {
        return TipoCourier::query()->orderBy('nome')->pluck('nome', 'tipo_courier_id')->all();
    }
}

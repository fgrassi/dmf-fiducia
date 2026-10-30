<?php

namespace App\Filament\Actions;

use App\Actions\AccountingCovers\CreateCover;
use App\Actions\AccountingCovers\ListPendingCoverValues;
use App\Models\CmnCurrency;
use App\Models\Embarque;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

/** "Nova capa" (loadValoresReserva + save, legado): escolhe a reserva, marca o que ainda não entrou em capa e grava. */
class NewCoverAction
{
    /** Extras marcáveis: chave da listagem => título (cada cobrança vira uma opção). */
    private const EXTRAS = [
        'iof' => 'IOF',
        'discount' => 'Desconto',
        'interest' => 'Juros',
        'bank_fee' => 'Taxa de emissão do boleto',
        'fine' => 'Multa',
    ];

    public static function make(): Action
    {
        return Action::make('newCover')
            ->label('Nova capa')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Nova capa')
            ->modalWidth('5xl')
            ->modalSubmitActionLabel('Gravar')
            ->schema([
                Select::make('booking_id')
                    ->label('Reserva')
                    ->searchable()
                    ->required()
                    ->live()
                    ->getSearchResultsUsing(fn (string $search) => Embarque::where('booking', 'like', "%{$search}%")->orderByDesc('embarque_id')->limit(30)->pluck('booking', 'embarque_id')->all())
                    ->getOptionLabelUsing(fn ($value) => Embarque::find($value)?->booking)
                    ->afterStateUpdated(fn (Set $set, $state) => self::load($set, $state)),
                Select::make('currency_id')
                    ->label('Moeda')
                    ->options(fn () => CmnCurrency::orderBy('name')->pluck('name', 'id')->all())
                    ->required(),
                TextInput::make('commission')
                    ->label('Comissão')
                    ->numeric()
                    ->default(0),
                TextInput::make('rate')
                    ->label('Valor Cotação')
                    ->numeric()
                    ->default(0),
                Toggle::make('select_all')
                    ->label('Todos')
                    ->live()
                    ->dehydrated(false)
                    ->afterStateUpdated(fn (Set $set, Get $get, $state) => self::markAll($set, $get, (bool) $state)),
                self::rows('expenses', 'Despesas efetivadas'),
                self::rows('revenues', 'Receitas efetivadas no Brasil'),
                self::rows('covered_expenses', 'Despesas já em capa (somente não vinculadas ao armador)'),
                CheckboxList::make('purchase_iof')
                    ->label('IOF da compra')
                    ->options(fn (Get $get) => self::money(self::pending($get('booking_id'))['purchase_iof'] ?? []))
                    ->visible(fn (Get $get) => filled(self::pending($get('booking_id'))['purchase_iof'] ?? [])),
                ...array_map(fn (string $kind, string $label) => CheckboxList::make($kind)
                    ->label($label)
                    ->options(fn (Get $get) => self::money(array_column(self::pending($get('booking_id'))[$kind] ?? [], 'value', 'id')))
                    ->visible(fn (Get $get) => filled(self::pending($get('booking_id'))[$kind] ?? [])), array_keys(self::EXTRAS), self::EXTRAS),
            ])
            ->action(function (array $data, CreateCover $create, Action $action) {
                try {
                    $cover = $create(Embarque::findOrFail($data['booking_id']), self::selection($data), auth()->user()?->getAuthIdentifier());
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title("Capa {$cover->numero} criada.")->success()->send();
            });
    }

    private static function rows(string $name, string $label): Repeater
    {
        return Repeater::make($name)
            ->label($label)
            ->schema([
                Checkbox::make('selected')
                    ->label('Na capa'),
                Hidden::make('id'),
                Hidden::make('ta_payment_id'),
                TextInput::make('name')
                    ->label('Despesa')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('value')
                    ->label('Valor')
                    ->numeric()
                    ->required(),
            ])
            ->columns(3)
            ->addable(false)
            ->deletable(false)
            ->reorderable(false)
            ->visible(fn (Get $get) => filled($get($name)));
    }

    private static function load(Set $set, mixed $bookingId): void
    {
        $pending = self::pending($bookingId);
        $row = fn (array $item) => $item + ['selected' => false, 'ta_payment_id' => null];

        foreach (['revenues', 'expenses', 'covered_expenses'] as $name) {
            $set($name, array_map($row, $pending[$name] ?? []));
        }

        foreach (['purchase_iof', ...array_keys(self::EXTRAS)] as $kind) {
            $set($kind, []);
        }

        $set('select_all', false);
    }

    private static function markAll(Set $set, Get $get, bool $selected): void
    {
        foreach (['revenues', 'expenses', 'covered_expenses'] as $name) {
            $set($name, array_map(fn (array $row) => ['selected' => $selected] + $row, $get($name) ?? []));
        }

        $pending = self::pending($get('booking_id'));
        $set('purchase_iof', $selected ? array_keys($pending['purchase_iof'] ?? []) : []);

        foreach (array_keys(self::EXTRAS) as $kind) {
            $set($kind, $selected ? array_column($pending[$kind] ?? [], 'id') : []);
        }
    }

    /**
     * Guardada no container (vale só pela requisição) para não repetir as consultas a cada campo da renderização.
     *
     * @return array<string, mixed>
     */
    private static function pending(mixed $bookingId): array
    {
        $booking = $bookingId ? Embarque::find($bookingId) : null;

        if (! $booking) {
            return [];
        }

        $key = "accounting-covers.pending.{$booking->getKey()}";

        return app()->bound($key) ? app($key) : tap(app(ListPendingCoverValues::class)($booking), fn (array $pending) => app()->instance($key, $pending));
    }

    /**
     * @param  array<int, float>  $values
     * @return array<int, string>
     */
    private static function money(array $values): array
    {
        return array_map(fn (float $value) => 'R$ '.number_format($value, 2, ',', '.'), $values);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function selection(array $data): array
    {
        $marked = fn (string $name) => collect($data[$name] ?? [])
            ->filter(fn (array $row) => $row['selected'] ?? false)
            ->map(fn (array $row) => array_filter(['id' => $row['id'], 'value' => $row['value'], 'ta_payment_id' => $row['ta_payment_id'] ?? null], fn ($v) => $v !== null))
            ->values()->all();

        return [
            'currency_id' => (int) $data['currency_id'],
            'commission' => $data['commission'] ?? 0,
            'rate' => $data['rate'] ?? 0,
            'revenues' => $marked('revenues'),
            'expenses' => $marked('expenses'),
            'covered_expenses' => $marked('covered_expenses'),
            'purchase_iof' => $data['purchase_iof'] ?? [],
            ...collect(array_keys(self::EXTRAS))->mapWithKeys(fn (string $kind) => [$kind => $data[$kind] ?? []])->all(),
        ];
    }
}

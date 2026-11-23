<?php

namespace App\Filament\Widgets;

use App\Enums\OperationType;
use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Filament\Support\DashboardPeriod;
use App\Models\Embarque;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Base das listas "próximas saídas/chegadas": as 8 reservas abertas com a data prevista mais próxima a partir de hoje. */
abstract class UpcomingBookings extends TableWidget
{
    /** Coluna de data prevista da reserva (saida_prevista ou chegada_prevista). */
    abstract protected function dateColumn(): string;

    abstract protected function heading(): string;

    /** @return list<string> */
    protected function statuses(): array
    {
        return ['n'];
    }

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('booking');
    }

    public function table(Table $table): Table
    {
        $date = $this->dateColumn();

        return $table
            ->heading($this->heading())
            ->query(fn () => Embarque::query()
                ->with(['cliente:pessoa_id,nome', 'armador:armador_id,nome', 'portoOrigem:porto_id,nome', 'portoDestino:porto_id,nome'])
                ->where('dteCadastro', '>=', DashboardPeriod::SINCE)
                ->whereIn('finalizado', $this->statuses())
                ->where($date, '>=', today()->toDateString())
                ->orderBy($date)
                ->orderBy('embarque_id')
                ->limit(8))
            ->columns([
                TextColumn::make($date)
                    ->label('Data')
                    ->date('d/m'),
                TextColumn::make('impor_expor')
                    ->label('Op.')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => OperationType::labelFor($state))
                    ->color(fn (?string $state) => OperationType::tryFrom((string) $state)?->getColor()),
                TextColumn::make('booking')
                    ->label('Booking')
                    ->limit(14),
                TextColumn::make('cliente.nome')
                    ->label('Cliente')
                    ->limit(22),
                TextColumn::make('armador.nome')
                    ->label('Armador')
                    ->limit(14),
                TextColumn::make('rota')
                    ->label('Rota')
                    ->state(fn (Embarque $record) => $record->portoOrigem?->nome.' → '.$record->portoDestino?->nome)
                    ->limit(24),
            ])
            ->recordUrl(fn (Embarque $record) => EmbarqueResource::getUrl('view', ['record' => $record]))
            ->paginated(false)
            ->emptyStateHeading('Nada previsto');
    }
}

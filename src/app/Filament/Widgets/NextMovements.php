<?php

namespace App\Filament\Widgets;

use App\Enums\OperationType;
use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Filament\Support\DashboardPeriod;
use App\Models\Embarque;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** As 8 reservas com a data prevista mais próxima a partir de hoje, com um botão que alterna entre saídas (abertas) e chegadas (abertas e já embarcadas). */
class NextMovements extends TableWidget
{
    protected static ?int $sort = 40;

    /** 'departures' (saida_prevista) ou 'arrivals' (chegada_prevista). */
    public string $kind = 'departures';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('booking');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(fn () => $this->departures() ? 'Próximas saídas' : 'Próximas chegadas')
            ->description('A partir de '.today()->format('d/m/Y').', reservas solicitadas desde '.DashboardPeriod::sinceLabel())
            ->query(fn () => Embarque::query()
                ->ofUserOperation()
                ->inBookingList()
                ->with(['cliente:pessoa_id,nome', 'armador:armador_id,nome', 'portoOrigem:porto_id,nome', 'portoDestino:porto_id,nome'])
                ->where('data_topo', '>=', DashboardPeriod::SINCE)
                ->whereIn('finalizado', $this->departures() ? ['n'] : ['n', 's'])
                ->where($this->dateColumn(), '>=', today()->toDateString())
                ->orderBy($this->dateColumn())
                ->orderBy('embarque_id')
                ->limit(8))
            ->headerActions([
                Action::make('alternar')
                    ->label(fn () => $this->departures() ? 'Ver chegadas' : 'Ver saídas')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->action(fn () => $this->kind = $this->departures() ? 'arrivals' : 'departures'),
            ])
            ->columns([
                TextColumn::make('data_prevista')
                    ->label('Data')
                    ->state(fn (Embarque $record) => $record->{$this->dateColumn()})
                    ->date('d/m/Y'),
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

    private function departures(): bool
    {
        return $this->kind === 'departures';
    }

    private function dateColumn(): string
    {
        return $this->departures() ? 'saida_prevista' : 'chegada_prevista';
    }
}

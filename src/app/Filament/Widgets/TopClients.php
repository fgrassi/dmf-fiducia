<?php

namespace App\Filament\Widgets;

use App\Filament\Support\DashboardPeriod;
use App\Models\Embarque;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Os 5 clientes com mais reservas solicitadas no mês, sem canceladas. */
class TopClients extends TableWidget
{
    protected static ?int $sort = 42;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('booking');
    }

    public function table(Table $table): Table
    {
        $from = max(today()->startOfMonth()->toDateString(), DashboardPeriod::SINCE);

        return $table
            ->heading('Top clientes do mês')
            ->description('De '.Carbon::parse($from)->format('d/m/Y').' a '.today()->format('d/m/Y').', sem canceladas')
            ->records(fn () => $this->rows($from))
            ->columns([
                TextColumn::make('nome')
                    ->label('Cliente')
                    ->limit(32),
                TextColumn::make('total')
                    ->label('Reservas')
                    ->alignEnd(),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nenhuma reserva no mês');
    }

    /** @return array<int|string, array{nome: string, total: int}> */
    private function rows(string $from): array
    {
        $totals = Embarque::query()
            ->ofUserOperation()
            ->inBookingList()
            ->where('data_topo', '>=', $from)
            ->where('finalizado', '<>', 'c')
            ->selectRaw('cliente_id as id, count(*) as total')
            ->groupBy('cliente_id')
            ->orderByDesc('total')
            ->orderBy('cliente_id')
            ->limit(5)
            ->pluck('total', 'id');
        $names = DB::table('pessoa')->whereIn('pessoa_id', $totals->keys())->pluck('nome', 'pessoa_id');

        return $totals->map(fn ($total, $id) => ['nome' => $names[$id] ?? "#{$id}", 'total' => (int) $total])->all();
    }
}

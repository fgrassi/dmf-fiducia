<?php

namespace App\Filament\Widgets;

use App\Enums\OperationType;
use App\Filament\Resources\Charges\ChargeResource;
use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Filament\Support\DashboardPeriod;
use App\Models\Embarque;
use App\Models\FinCharges;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** As 8 cobranças (ICs) em aberto vencidas há mais tempo. */
class OverdueCharges extends TableWidget
{
    protected static ?int $sort = 50;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Cobranças vencidas')
            ->description('Em aberto, vencidas antes de '.today()->format('d/m/Y').', criadas desde '.DashboardPeriod::sinceLabel())
            ->query(fn () => FinCharges::query()
                ->with('embarque:embarque_id,booking,impor_expor,tipo_embarque')
                ->where('created_at', '>=', DashboardPeriod::SINCE)
                ->where('status', 0)
                ->where('due_date', '<', today()->toDateString())
                ->where('due_date', '>', '0000-00-00')
                ->orderBy('due_date')
                ->limit(8))
            ->columns([
                TextColumn::make('id')
                    ->label('IC'),
                TextColumn::make('embarque.booking')
                    ->label('Booking')
                    ->placeholder('—')
                    ->url(fn (FinCharges $record) => $record->embarque ? EmbarqueResource::getUrl('view', ['record' => $record->embarque]) : null),
                TextColumn::make('embarque.impor_expor')
                    ->label('Op.')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => OperationType::labelFor($state))
                    ->color(fn (?string $state) => OperationType::tryFrom((string) $state)?->getColor()),
                TextColumn::make('embarque.tipo_embarque')
                    ->label('Tipo')
                    ->formatStateUsing(fn (?string $state) => Embarque::TYPES[$state] ?? $state),
                TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y'),
                TextColumn::make('days_overdue')
                    ->label('Atraso')
                    ->state(fn (FinCharges $record) => (int) $record->due_date->diffInDays(today()).' dias')
                    ->badge()
                    ->color('danger'),
                TextColumn::make('total_value')
                    ->label('Valor')
                    ->money('BRL')
                    ->alignEnd(),
            ])
            ->headerActions([
                Action::make('verTodas')
                    ->label('Ver todas')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(FinanceStats::overdueUrl()),
            ])
            ->recordUrl(fn (FinCharges $record) => ChargeResource::getUrl('view', ['record' => $record]))
            ->paginated(false)
            ->emptyStateHeading('Nenhuma cobrança vencida');
    }
}

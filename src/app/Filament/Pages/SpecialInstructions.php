<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Models\Embarque;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * relInstrucoesEspeciais/list (legado), "Instruções Especiais Expo": as reservas de exportação não canceladas, ainda sem
 * confirmação de saída, que têm instrução especial. Mostra a primeira instrução lançada.
 */
class SpecialInstructions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Reservas de Exportação com Instrução Especial';

    protected static ?string $navigationLabel = 'Instruções Especiais Expo';

    protected static ?string $slug = 'relatorios/instrucoes-especiais-expo';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Relatórios';

    protected static ?int $navigationSort = 43;

    /** security.yml do módulo (legado): credencial followup. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('followup');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Embarque::query()
                ->select('embarque.*')
                ->addSelect(['instruction' => DB::table('instrucao_especial_embarque')
                    ->select('instrucao')
                    ->whereColumn('instrucao_especial_embarque.embarque_id', 'embarque.embarque_id')
                    ->orderBy('instrucao_especial_embarque_id')
                    ->limit(1)])
                ->where('impor_expor', 'e')
                ->where('finalizado', '<>', 'c')
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('instrucao_especial_embarque')->whereColumn('instrucao_especial_embarque.embarque_id', 'embarque.embarque_id'))
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('departure_confirmation')->whereColumn('departure_confirmation.ref_embarque', 'embarque.embarque_id'))
                ->with(['exportador', 'importador', 'portoOrigem', 'portoDestino']))
            ->defaultSort('saida_prevista')
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Nenhuma reserva com instrução especial cadastrada')
            ->recordUrl(fn (Embarque $record) => EmbarqueResource::getUrl('view', ['record' => $record->getKey()]))
            ->columns([
                TextColumn::make('booking')
                    ->label('Booking')
                    ->sortable(),
                TextColumn::make('saida_prevista')
                    ->label('ETD')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('chegada_prevista')
                    ->label('ETA')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('exportador.nome')
                    ->label('Exportador')
                    ->wrap(),
                TextColumn::make('importador.nome')
                    ->label('Importador')
                    ->wrap(),
                TextColumn::make('portoOrigem.nome')
                    ->label('Origem'),
                TextColumn::make('portoDestino.nome')
                    ->label('Destino'),
                TextColumn::make('instruction')
                    ->label('Instrução Especial')
                    ->wrap(),
            ]);
    }
}

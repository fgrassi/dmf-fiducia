<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Filament\Support\OperationBadge;
use App\Models\Embarque;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Reservas em aberto da empresa, em qualquer papel (cliente, exportador, importador ou agente). Somente leitura. */
class CompanyBookings extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = CompanyResource::class;

    protected static ?string $title = 'Bookings';

    protected static ?string $navigationLabel = 'Bookings';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
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
        $companyId = $this->getRecord()->getKey();

        return $table
            ->query(Embarque::query()
                ->where('finalizado', 'n')
                ->where(fn (Builder $query) => $query
                    ->where('cliente_id', $companyId)
                    ->orWhere('pessoa_id_cliente', $companyId)
                    ->orWhere('pessoa_id_importador', $companyId)
                    ->orWhere('pessoa_id_representante', $companyId)))
            ->defaultSort('embarque_id', 'desc')
            ->emptyStateHeading('Nenhuma reserva em aberto.')
            ->columns([
                TextColumn::make('booking')
                    ->label('Reserva')
                    ->searchable()
                    ->sortable(),
                OperationBadge::column()
                    ->label('Operação'),
                TextColumn::make('tipo_embarque')
                    ->label('Modal')
                    ->formatStateUsing(fn (?string $state) => Embarque::TYPES[$state] ?? $state),
                TextColumn::make('cliente.nome')
                    ->label('Cliente'),
                TextColumn::make('armador.nome')
                    ->label('Armador'),
                TextColumn::make('house_bl')
                    ->label('HBL')
                    ->searchable(),
                TextColumn::make('saida_prevista')
                    ->label('Saída prevista')
                    ->date('d/m/Y'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn (Embarque $record) => EmbarqueResource::getUrl('view', ['record' => $record])),
            ]);
    }
}

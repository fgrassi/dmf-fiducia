<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Actions\Companies\CompanyFollowUps;
use App\Filament\Resources\Companies\CompanyResource;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** pessoa/listFups (legado): follow ups e visitas da empresa (somente leitura). */
class FollowUps extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = CompanyResource::class;

    protected static ?string $title = 'Follow Ups';

    protected static ?string $navigationLabel = 'Follow Ups';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

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
        return $table
            ->records(fn () => app(CompanyFollowUps::class)($this->getRecord()))
            ->paginated(false)
            ->columns([
                TextColumn::make('tipo')
                    ->label('Tipo'),
                TextColumn::make('data_registro')
                    ->label('Data de Registro')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('relato')
                    ->label('Relato')
                    ->wrap(),
                TextColumn::make('data_ocorrencia')
                    ->label('Data da Ocorrência')
                    ->dateTime('d/m/Y'),
                TextColumn::make('responsavel')
                    ->label('Responsável'),
            ]);
    }
}

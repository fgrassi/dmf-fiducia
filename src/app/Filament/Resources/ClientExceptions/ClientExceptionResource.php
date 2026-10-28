<?php

namespace App\Filament\Resources\ClientExceptions;

use App\Filament\Resources\ClientExceptions\Pages\ManageClientExceptions;
use App\Models\FinClientsException;
use App\Models\Pessoa;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** clientException (legado): clientes que não recebem cobrança automática (NFS-e, IC e seguro esperam o recálculo). */
class ClientExceptionResource extends Resource
{
    protected static ?string $model = FinClientsException::class;

    protected static ?string $modelLabel = 'Cliente de Exceção';

    protected static ?string $pluralModelLabel = 'Clientes que não recebem cobrança automática';

    protected static ?string $navigationLabel = 'Exceções';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 22;

    /** security.yml do módulo (legado): credencial financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('ref_client')
                    ->label('Cliente')
                    ->required()
                    ->unique()
                    ->validationMessages(['unique' => 'Este cliente já está na lista de exceção.'])
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Pessoa::query()
                        ->where('nome', 'like', "%{$search}%")
                        ->orWhere('cnpj', 'like', "%{$search}%")
                        ->orderBy('nome')
                        ->limit(30)
                        ->pluck('nome', 'pessoa_id'))
                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome'))
                    ->columnSpanFull(),
                Textarea::make('justificativa')
                    ->label('Justificativa')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->has('client')->with(['client', 'createdBy']))
            ->defaultSort('client.nome')
            ->paginated(false)
            ->emptyStateHeading('Nenhum registro encontrado')
            ->columns([
                TextColumn::make('client.nome')
                    ->label('Nome')
                    ->width('30%')
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client.cnpj')
                    ->label('CNPJ')
                    ->searchable(),
                TextColumn::make('justificativa')
                    ->label('Justificativa')
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label('Data Cadastro')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('createdBy.nome')
                    ->label('Usuário'),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->modalDescription('Confirmar exclusão?'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageClientExceptions::route('/'),
        ];
    }
}

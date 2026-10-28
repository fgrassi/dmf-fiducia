<?php

namespace App\Filament\Resources\FinancialContacts;

use App\Filament\Resources\FinancialContacts\Pages\ManageFinancialContacts;
use App\Models\FinContatoCobranca;
use App\Models\Pessoa;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** contatoFinanceiro (legado): contatos de cliente que sempre recebem cobranças e recibos. */
class FinancialContactResource extends Resource
{
    protected static ?string $model = FinContatoCobranca::class;

    protected static ?string $modelLabel = 'Contato Financeiro';

    protected static ?string $pluralModelLabel = 'Contatos de cliente que sempre recebem cobranças/recibos';

    protected static ?string $navigationLabel = 'Exceções Contato Cliente';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-at-symbol';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 26;

    /** security.yml do módulo (legado): credencial financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('ref_pessoa')
                    ->label('Cliente')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Pessoa::query()
                        ->where('nome', 'like', "%{$search}%")
                        ->orWhere('cnpj', 'like', "%{$search}%")
                        ->orderBy('nome')
                        ->limit(30)
                        ->pluck('nome', 'pessoa_id'))
                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome'))
                    ->columnSpanFull(),
                TextInput::make('email')
                    ->label('Contato')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('descr')
                    ->label('Descrição')
                    ->required()
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
                    ->wrap()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client.cnpj')
                    ->label('CNPJ')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Contato')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('descr')
                    ->label('Descrição')
                    ->wrap(),
                TextColumn::make('createdBy.nome')
                    ->label('Adicionado por'),
                TextColumn::make('created_at')
                    ->label('Adicionado em')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->modalDescription('Confirmar exclusão?'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFinancialContacts::route('/'),
        ];
    }
}

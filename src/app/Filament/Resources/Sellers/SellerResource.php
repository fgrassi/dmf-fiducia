<?php

namespace App\Filament\Resources\Sellers;

use App\Actions\Users\SaveSeller;
use App\Filament\Resources\Sellers\Pages\ManageSellers;
use App\Models\User;
use App\Models\Vendedor;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * vendedor (legado): cadastro de vendedores e dos usuários vinculados. Excluir é lógico (deleted_at).
 *
 * ponytail: sem a ficha do vendedor (carteira de clientes, prospecção e restrições), só o cadastro.
 */
class SellerResource extends Resource
{
    private const YES_NO = ['s' => 'Sim', 'n' => 'Não'];

    protected static ?string $model = Vendedor::class;

    protected static ?string $modelLabel = 'Vendedor';

    protected static ?string $pluralModelLabel = 'Vendedores';

    protected static ?string $navigationLabel = 'Vendedores';

    protected static ?string $slug = 'configuracoes/vendedores';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-briefcase';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationParentItem = 'Configurações Usuários';

    protected static ?int $navigationSort = 50;

    /** security.yml do módulo (legado): credencial auxiliares. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('auxiliares');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('nome')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('E-mail')
                    ->email()
                    ->required()
                    ->maxLength(255),
                Select::make('idiomas_id')
                    ->label('Idioma dos e-mails')
                    ->options(fn () => DB::table('idiomas')->where('ativo', 1)->orderBy('sequencia')->pluck('nome', 'idiomas_id'))
                    ->required(),
                Select::make('sede_id')
                    ->label('Escritório')
                    ->options(fn () => DB::table('sedes')->orderBy('nome')->pluck('nome', 'id')),
                Select::make('receber_avisos')
                    ->label('Receber avisos')
                    ->options(self::YES_NO)
                    ->default('s')
                    ->required()
                    ->native(false),
                Select::make('ctr_ferias')
                    ->label('Controle Férias')
                    ->options([1 => 'Sim', 0 => 'Não'])
                    ->default(0)
                    ->required()
                    ->native(false),
                Select::make('usuarios')
                    ->label('Usuários vinculados')
                    ->multiple()
                    ->searchable()
                    ->columnSpanFull()
                    ->getSearchResultsUsing(fn (string $search) => User::query()
                        ->where(fn (Builder $query) => $query->where('nome', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                        ->orderBy('nome')
                        ->limit(30)
                        ->get()
                        ->mapWithKeys(fn (User $user) => [$user->getKey() => "{$user->nome} ({$user->email})"])
                        ->all())
                    ->getOptionLabelsUsing(fn (array $values) => User::query()
                        ->whereKey($values)
                        ->get()
                        ->mapWithKeys(fn (User $user) => [$user->getKey() => "{$user->nome} ({$user->email})"])
                        ->all()),
            ]);
    }

    public static function table(Table $table): Table
    {
        $offices = DB::table('sedes')->pluck('nome', 'id');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('deleted_at'))
            ->defaultSort('nome')
            ->columns([
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable(),
                TextColumn::make('sede_id')
                    ->label('Escritório')
                    ->formatStateUsing(fn ($state) => $offices[$state] ?? ''),
                TextColumn::make('receber_avisos')
                    ->label('Recebe avisos')
                    ->formatStateUsing(fn ($state) => self::YES_NO[$state] ?? 'Não'),
                TextColumn::make('ctr_ferias')
                    ->label('Controle Férias')
                    ->formatStateUsing(fn ($state) => $state ? 'Sim' : 'Não'),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data, Vendedor $record) => [
                        ...$data,
                        'usuarios' => DB::table('cmn_vendedor_usuario')->where('ref_vendedor', $record->getKey())->whereNull('deleted_at')->pluck('ref_usuario')->all(),
                    ])
                    ->using(fn (Vendedor $record, array $data) => app(SaveSeller::class)($data, auth()->id(), $record)),
                DeleteAction::make()
                    ->using(fn (Vendedor $record) => $record->forceFill(['deleted_at' => now(), 'deleted_by' => auth()->id()])->save()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSellers::route('/'),
        ];
    }
}

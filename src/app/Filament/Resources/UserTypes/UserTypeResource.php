<?php

namespace App\Filament\Resources\UserTypes;

use App\Actions\Users\SaveUserType;
use App\Filament\Resources\UserTypes\Pages\ManageUserTypes;
use App\Models\TipoUsuario;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/** tipoUsuario (legado): tipos de usuário e as empresas × aplicações a que dão acesso. */
class UserTypeResource extends Resource
{
    protected static ?string $model = TipoUsuario::class;

    protected static ?string $modelLabel = 'Tipo de Usuário';

    protected static ?string $pluralModelLabel = 'Tipos de Usuário';

    protected static ?string $navigationLabel = 'Tipos de Usuário';

    protected static ?string $slug = 'configuracoes/tipos-de-usuario';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationParentItem = 'Configurações Usuários';

    protected static ?int $navigationSort = 30;

    /** security.yml do módulo (legado): credencial auxiliares. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('auxiliares');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nome')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                Select::make('aplicacoes')
                    ->label('Empresas × aplicações')
                    ->helperText('Busque pelo nome da empresa')
                    ->multiple()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => self::companyApplicationOptions(fn ($query) => $query->where('pessoa.nome', 'like', "%{$search}%")->limit(30)))
                    ->getOptionLabelsUsing(fn (array $values) => self::companyApplicationOptions(fn ($query) => $query->whereIn('pa.pessoa_aplicacao_id', $values))),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('nome')
            ->columns([
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data, TipoUsuario $record) => [
                        ...$data,
                        'aplicacoes' => DB::table('pessoa_aplicacao_tipo_usuario')->where('tipo_usuario_id', $record->getKey())->pluck('pessoa_aplicacao_id')->all(),
                    ])
                    ->using(fn (TipoUsuario $record, array $data) => app(SaveUserType::class)($data, $record)),
                DeleteAction::make()
                    ->hidden(fn (TipoUsuario $record) => $record->isReferenced())
                    ->before(fn (TipoUsuario $record) => self::unlinkApplications($record)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUserTypes::route('/'),
        ];
    }

    /** Exclusão remove os vínculos com aplicações junto (o legado deixava órfãos). */
    private static function unlinkApplications(TipoUsuario $type): void
    {
        $links = DB::table('pessoa_aplicacao_tipo_usuario')->where('tipo_usuario_id', $type->getKey());

        DB::table('item_aplicacao')->whereIn('pessoa_apli_tipo_usu_id', (clone $links)->select('pessoa_apli_tipo_usu_id'))->delete();
        $links->delete();
    }

    /** @return array<int, string> pessoa_aplicacao_id => "Empresa — Aplicação" */
    private static function companyApplicationOptions(Closure $constrain): array
    {
        $query = DB::table('pessoa_aplicacao as pa')
            ->join('pessoa', 'pessoa.pessoa_id', '=', 'pa.pessoa_id')
            ->join('aplicacao as a', 'a.aplicacao_id', '=', 'pa.aplicacao_id')
            ->orderBy('pessoa.nome')
            ->select('pa.pessoa_aplicacao_id', 'pessoa.nome as company', 'a.nome as application');

        return $constrain($query)->get()->mapWithKeys(fn ($row) => [$row->pessoa_aplicacao_id => "{$row->company} — {$row->application}"])->all();
    }
}

<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Pessoa;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * usuario (legado): cadastro de usuários, credenciais e vínculos com empresas.
 *
 * ponytail: sem exclusão (o legado apaga a linha, que quase sempre está referenciada: use o status "Deletado"),
 * sem foto (o formulário do legado também não edita) e sem as telas show/listDmf.
 */
class UserResource extends Resource
{
    /** sfGuardSecurityUser::getAvailableCredentials (legado). */
    private const CREDENTIALS = [
        'admin' => 'Administrator',
        'import' => 'Import',
        'export' => 'Export',
        'booking' => 'Booking',
        'embarque' => 'Embarques',
        'fechamento' => 'Responsável Fechamento',
        'empresa' => 'Empresas',
        'auxiliares' => 'Cadastros Auxiliares',
        'localidade' => 'Localidades',
        'docs' => 'Documentação',
        'relatorio' => 'Relatórios',
        'usuario' => 'Cadastro de Usuários',
        'user_credential' => 'Permissões de Usuários',
        'followup' => 'Follow Up',
        'vendedor' => 'Vendedor',
        'sales_manager' => 'Gerência Vendedor',
        'agenda_vendedor' => 'Agenda Vendedor',
        'excluir_cobranca' => 'Excluir Cobrança',
        'it' => 'IT',
        'auth_imp' => 'Autoriza Contato Impo',
        'auth_exp' => 'Autoriza Contato Expo',
        'lib_emb' => 'Libera Embarque para Cobrança',
        'lib_doc' => 'Envio Docs Origem',
        'lib_free_hand' => 'Liberação de Free Hand',
        'lib_val_des' => 'Pendência de Valores Despachante',
        'supervisor' => 'Supervisor',
        'director' => 'Direção',
        'manager' => 'Gerência',
        'financial' => 'Financeiro',
        'meta_expo' => 'Meta Exportação',
        'meta_impo' => 'Meta Importação',
    ];

    private const STATUS = [1 => 'OK', 0 => 'Aguardando aprovação', -1 => 'Deletado'];

    private const YES_NO = ['s' => 'Sim', 'n' => 'Não'];

    /** Aplicacao::idsVisiveis (legado): só estas aplicações aparecem na coluna e no filtro. */
    private const APPLICATIONS = [1 => 'Gerenciador', 4 => 'CRM'];

    /** @var array<string, array<int, list<string>>> aplicações por usuário, uma consulta por página da tabela */
    private static array $applicationsByPage = [];

    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'Usuário';

    protected static ?string $pluralModelLabel = 'Usuários';

    protected static ?string $navigationLabel = 'Usuários';

    protected static ?string $slug = 'configuracoes/usuarios';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationParentItem = 'Configurações Usuários';

    protected static ?int $navigationSort = 40;

    /** security.yml do módulo (legado): credencial admin. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('admin');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dados')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nome')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        TextInput::make('fone')
                            ->label('Telefone')
                            ->maxLength(20),
                        TextInput::make('celular')
                            ->label('Celular')
                            ->maxLength(20),
                        TextInput::make('login')
                            ->label('Login')
                            ->maxLength(255),
                        TextInput::make('senha')
                            ->label('Senha')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->helperText('Deixe em branco para manter a senha atual')
                            ->maxLength(255),
                        TextInput::make('ip')
                            ->label('IP')
                            ->maxLength(100),
                        TextInput::make('skype')
                            ->label('Skype')
                            ->maxLength(255),
                        TextInput::make('setor')
                            ->label('Setor')
                            ->maxLength(255),
                        Select::make('idiomas_id')
                            ->label('Idioma')
                            ->options(fn () => DB::table('idiomas')->where('ativo', 1)->orderBy('sequencia')->pluck('nome', 'idiomas_id')),
                        DatePicker::make('aniversario')
                            ->label('Aniversário'),
                        DatePicker::make('data_fim')
                            ->label('Data fim'),
                        Select::make('status')
                            ->label('Status')
                            ->options(self::STATUS)
                            ->default(1)
                            ->required()
                            ->native(false),
                        Select::make('admin_intranet')
                            ->label('Intranet Admin')
                            ->options(['n' => 'Não', 's' => 'Sim'])
                            ->default('n')
                            ->native(false),
                        TextInput::make('ctr_bloqueio')
                            ->label('Tentativas de login com erro')
                            ->helperText('3 ou mais bloqueia o acesso; zere para desbloquear')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                    ]),
                Section::make('Recebimento de e-mails')
                    ->columns(3)
                    ->schema([
                        Select::make('usuario_padrao')
                            ->label('Contato padrão')
                            ->helperText('Se Sim, é adicionado automaticamente à reserva quando a empresa for selecionada (Cliente, Shipper, Consignee ou Notify)')
                            ->options(self::YES_NO)
                            ->default('s')
                            ->native(false),
                        Select::make('receber_avisos')
                            ->label('Tracking')
                            ->helperText('Mensagens de rastreamento, se o contato estiver na reserva')
                            ->options(self::YES_NO)
                            ->native(false),
                        Select::make('receber_relatorio')
                            ->label('Relatórios')
                            ->helperText('Cópia do Mapa de Reservas, quando há envio agendado')
                            ->options(self::YES_NO)
                            ->native(false),
                        Select::make('receber_cobranca')
                            ->label('Cobrança')
                            ->helperText('Tudo: qualquer cobrança/recibo. Frete: só frete. Taxas: tudo que não for frete')
                            ->options(['s' => 'Tudo', 'n' => 'Não', 'f' => 'Frete', 't' => 'Taxas'])
                            ->native(false),
                        Select::make('receber_documentos')
                            ->label('Documentos')
                            ->helperText('Cópia dos BLs, se o contato estiver na reserva')
                            ->options(self::YES_NO)
                            ->native(false),
                        Select::make('receber_avisos_demurrage')
                            ->label('Avisos Demurrage')
                            ->options(self::YES_NO)
                            ->native(false),
                        CheckboxList::make('categorias')
                            ->label('Categorias de frases do tracking')
                            ->options(fn () => DB::table('frase_categoria')->orderBy('nome')->pluck('nome', 'id'))
                            ->columns(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Credenciais')
                    ->schema([
                        CheckboxList::make('credentials')
                            ->hiddenLabel()
                            ->options(self::CREDENTIALS)
                            ->columns(3),
                    ]),
                Section::make('Empresas, cargos e tipos de usuário')
                    ->schema([
                        Repeater::make('vinculos')
                            ->hiddenLabel()
                            ->addActionLabel('Adicionar empresa')
                            ->columns(3)
                            ->schema([
                                Select::make('pessoa_id')
                                    ->label('Empresa')
                                    ->required()
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search) => Pessoa::query()
                                        ->where('nome', 'like', "%{$search}%")
                                        ->orderBy('nome')
                                        ->limit(30)
                                        ->pluck('nome', 'pessoa_id'))
                                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome')),
                                Select::make('cargos_id')
                                    ->label('Cargo')
                                    ->options(fn () => DB::table('cargos')->orderBy('nome')->pluck('nome', 'cargos_id')),
                                Select::make('tipo_usuario_id')
                                    ->label('Tipo de usuário')
                                    ->options(fn () => DB::table('tipo_usuario')->orderBy('nome')->pluck('nome', 'tipo_usuario_id')),
                            ]),
                    ]),
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
                TextColumn::make('login')
                    ->label('Login')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable(),
                TextColumn::make('fone')
                    ->label('Telefone'),
                TextColumn::make('aplicacoes')
                    ->label('Aplicações')
                    ->badge()
                    ->state(fn (User $record, ListUsers $livewire) => self::applicationsOf($record, $livewire))
                    ->color(fn (string $state) => $state === 'CRM' ? 'info' : 'danger'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => self::STATUS[(int) $state] ?? 'OK')
                    ->color(fn ($state) => match ((int) $state) {
                        0 => 'warning',
                        -1 => 'danger',
                        default => 'success',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::STATUS)
                    ->default('1'),
                SelectFilter::make('aplicacao')
                    ->label('Aplicação')
                    ->options(['1,4' => 'CRM e Gerenciador', '4' => 'CRM', '1' => 'Gerenciador'])
                    ->default('1,4')
                    ->selectablePlaceholder(false)
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'], fn (Builder $query, string $value) => $query->whereIn('usuario_id', DB::table('pessoa_usuario as pu')
                        ->join('pessoa_aplicacao as pa', 'pa.pessoa_id', '=', 'pu.pessoa_id')
                        ->whereIn('pa.aplicacao_id', explode(',', $value))
                        ->select('pu.usuario_id')))),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /** @return list<string> */
    private static function applicationsOf(User $record, ListUsers $livewire): array
    {
        $ids = $livewire->getTableRecords()->modelKeys();

        self::$applicationsByPage[implode(',', $ids)] ??= DB::table('pessoa_usuario as pu')
            ->join('pessoa_aplicacao as pa', 'pa.pessoa_id', '=', 'pu.pessoa_id')
            ->whereIn('pu.usuario_id', $ids)
            ->whereIn('pa.aplicacao_id', array_keys(self::APPLICATIONS))
            ->distinct()
            ->orderBy('pa.aplicacao_id')
            ->get(['pu.usuario_id', 'pa.aplicacao_id'])
            ->groupBy('usuario_id')
            ->map(fn ($rows) => $rows->map(fn ($row) => self::APPLICATIONS[$row->aplicacao_id])->all())
            ->all();

        return self::$applicationsByPage[implode(',', $ids)][$record->getKey()] ?? [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}

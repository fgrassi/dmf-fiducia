<?php

namespace App\Filament\Support;

use App\Actions\Followup\FollowUpsQuery;
use App\Actions\Followup\SaveFollowUp;
use App\Models\AvaliacaoCliente;
use App\Models\Cidade;
use App\Models\CmnClientFup;
use App\Models\Pessoa;
use App\Models\User;
use App\Models\VisitaCliente;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * followup e followupExpo (legado): follow up de atividades com os clientes. As duas áreas só mudam a lista (FollowUpsQuery) e os tipos
 * que dá para registrar nela; a tela é a mesma.
 *
 * Diferenças do legado: o tipo oferecido em cada área é só o que aparece na lista dela (lá dava para registrar um que sumia da lista);
 * a busca por cliente, relato e cidade é a das colunas da tabela, e o filtro Vendedor, que no legado não filtrava nada, passa a filtrar.
 */
abstract class FollowUpResource extends Resource
{
    protected static ?string $model = CmnClientFup::class;

    protected static ?string $modelLabel = 'Follow Up';

    protected static ?string $pluralModelLabel = 'Follow Ups';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    /** @var FollowUpsQuery::IMPORT|FollowUpsQuery::EXPORT */
    protected static string $area;

    /** @var list<string> */
    protected static array $types;

    public static function getEloquentQuery(): Builder
    {
        return app(FollowUpsQuery::class)(static::$area, auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        $isEditing = fn (?Model $record) => $record !== null;

        return $schema
            ->columns(2)
            ->components([
                ReportFields::person('ref_pessoa', 'Cliente')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                        $set('cidades_id', $state ? Pessoa::whereKey($state)->value('cidades_id') : null);
                        static::fillRating($get, $set);
                    })
                    ->disabled($isEditing)
                    ->columnSpanFull(),
                Select::make('cidades_id')
                    ->label('Cidade do Cliente')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Cidade::where('nome', 'like', "%{$search}%")->orderBy('nome')->limit(30)->pluck('nome', 'cidades_id'))
                    ->getOptionLabelUsing(fn ($value) => Cidade::whereKey($value)->value('nome')),
                DatePicker::make('data_ocorrencia')
                    ->label('Data ocorrência')
                    ->default(today())
                    ->required(),
                Select::make('tipo')
                    ->label('Tipo')
                    ->options(array_intersect_key(CmnClientFup::TYPES, array_flip(static::$types)))
                    ->default(static::$types[0])
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => static::fillRating($get, $set))
                    ->disabled($isEditing),
                Textarea::make('descr')
                    ->label('Descrição')
                    ->required()
                    ->rows(5)
                    ->columnSpanFull(),
                Section::make('Avaliação')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('volume_embarques')
                            ->label('Volume de embarques')
                            ->options(CmnClientFup::VOLUMES)
                            ->default(0)
                            ->disabled($isEditing),
                        Select::make('abertura')
                            ->label('Abertura')
                            ->options(CmnClientFup::OPENINGS)
                            ->default(0)
                            ->disabled($isEditing),
                    ]),
                Section::make('Contato')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        DatePicker::make('data_proximo_contato')
                            ->label('Data próximo contato'),
                        Textarea::make('obs')
                            ->label('Observação')
                            ->rows(3),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Data de Registro')
                    ->date('d/m/Y'),
                TextColumn::make('pessoa.nome')
                    ->label('Cliente')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('pessoa.cidade.nome')
                    ->label('Cidade')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('descr')
                    ->label('Relato')
                    ->wrap()
                    ->width('32rem')
                    ->searchable(),
                TextColumn::make('data_ocorrencia')
                    ->label('Data da Ocorrência')
                    ->date('d/m/Y'),
                TextColumn::make('usuario.nome')
                    ->label('Vendedor')
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn ($state) => $state ? 'Enviado' : 'Não enviado'),
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => CmnClientFup::TYPES[$state] ?? 'N/A'),
                TextColumn::make('volume_embarques')
                    ->label('Volume de Embarques')
                    ->wrap()
                    ->width('9rem')
                    ->formatStateUsing(fn ($state) => CmnClientFup::VOLUMES[$state] ?? 'N/A'),
                TextColumn::make('abertura')
                    ->label('Abertura')
                    ->wrap()
                    ->width('9rem')
                    ->formatStateUsing(fn ($state) => CmnClientFup::OPENINGS[$state] ?? 'N/A'),
            ])
            ->filters([
                Filter::make('minhas')
                    ->label('Minhas')
                    ->toggle()
                    ->visible(fn () => (bool) auth()->user()?->hasLegacyCredential('manager'))
                    ->query(fn (Builder $query) => $query->where('ref_user', auth()->id())),
                SelectFilter::make('ref_user')
                    ->label('Vendedor')
                    ->visible(fn () => (bool) auth()->user()?->hasLegacyCredential('manager'))
                    ->options(fn () => User::whereIn('usuario_id', static::getEloquentQuery()->reorder()->select('ref_user'))->orderBy('nome')->pluck('nome', 'usuario_id')),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(function (array $data, CmnClientFup $record) {
                        $visit = VisitaCliente::where('pessoa_id', $record->ref_pessoa)->where('status', 0)->first();

                        return [
                            ...$data,
                            'cidades_id' => $record->pessoa?->cidades_id,
                            'data_proximo_contato' => $visit?->data_proximo_contato,
                            'obs' => $visit?->obs,
                        ];
                    })
                    ->using(fn (array $data, CmnClientFup $record) => app(SaveFollowUp::class)($data, auth()->user(), $record)),
                DeleteAction::make()
                    ->modalDescription('Confirmar exclusão?'),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->label('Novo Follow Up')
            ->using(fn (array $data) => app(SaveFollowUp::class)($data, auth()->user()));
    }

    /** Ao escolher o cliente ou o tipo: a avaliação atual do cliente naquele tipo (ajax getVolumeEmb e getAbertura do legado). */
    private static function fillRating(Get $get, Set $set): void
    {
        $clientId = $get('ref_pessoa');
        $rating = $clientId
            ? AvaliacaoCliente::where('pessoa_id', $clientId)->where('tipo', $get('tipo'))->where('status', 0)->first()
            : null;

        $set('volume_embarques', $rating?->volume_embarques ?? 0);
        $set('abertura', $rating?->abertura ?? 0);
    }
}

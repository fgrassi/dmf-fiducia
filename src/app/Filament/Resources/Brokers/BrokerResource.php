<?php

namespace App\Filament\Resources\Brokers;

use App\Filament\Actions\MergeRecordsAction;
use App\Filament\Resources\Brokers\Pages\ManageBrokers;
use App\Filament\Resources\Brokers\Pages\ViewBroker;
use App\Filament\Resources\Brokers\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Brokers\RelationManagers\PortsRelationManager;
use App\Models\Despachante;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * despachante (legado): cadastro de despachantes, com os contatos, os portos em que atuam (e a matriz de despesas de cada um)
 * e as empresas vinculadas. Ficam de fora o idioma e o "receber avisos" (o formulário do legado não os mostra) e a empresa do
 * formulário (grava em despachante_pessoa, tabela que nenhuma tela lê; o vínculo que vale é o pessoa_despachante, feito na empresa).
 */
class BrokerResource extends Resource
{
    protected static ?string $model = Despachante::class;

    protected static ?string $modelLabel = 'Despachante';

    protected static ?string $pluralModelLabel = 'Despachantes';

    protected static ?string $navigationLabel = 'Despachantes';

    protected static ?string $slug = 'cadastros/despachantes';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 32;

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
                TextInput::make('cnpj')
                    ->label('CNPJ')
                    ->maxLength(20),
                TextInput::make('domain')
                    ->label('Domínio')
                    ->helperText('Contatos com e-mail de outro domínio precisam de justificativa e de aprovação.')
                    ->maxLength(255),
                Textarea::make('dados_bancarios')
                    ->label('Dados Bancários')
                    ->rows(4),
                Textarea::make('dados_contato')
                    ->label('Dados para Contato')
                    ->rows(4),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Detalhes do Despachante')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('nome')
                            ->label('Nome'),
                        TextEntry::make('cnpj')
                            ->label('CNPJ')
                            ->placeholder('Não Informado'),
                        TextEntry::make('domain')
                            ->label('Domínio')
                            ->placeholder('Não Informado'),
                        TextEntry::make('dados_bancarios')
                            ->label('Dados Bancários')
                            ->placeholder('Não Informado')
                            ->prose(),
                        TextEntry::make('dados_contato')
                            ->label('Dados para Contato')
                            ->placeholder('Não Informado')
                            ->prose(),
                        TextEntry::make('companies')
                            ->label('Relação de Empresas')
                            ->state(fn (Despachante $record) => DB::table('pessoa_despachante as pd')
                                ->join('pessoa as p', 'p.pessoa_id', '=', 'pd.pessoa_id')
                                ->where('pd.despachante_id', $record->getKey())
                                ->orderBy('p.nome')
                                ->pluck('p.nome')
                                ->all())
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('Nenhuma empresa vinculada'),
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
            ])
            ->recordActions([
                ViewAction::make(),
                DeleteAction::make()
                    ->modalDescription('Confirmar exclusão?')
                    ->hidden(fn (Despachante $record) => $record->inUse())
                    ->using(function (Despachante $record) {
                        $ports = $record->ports()->pluck('id');
                        DB::table('matriz_despesa_despachante')->whereIn('ref_despachante_porto', $ports)->delete();
                        $record->ports()->delete();
                        $record->contacts()->delete();
                        $record->delete();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    MergeRecordsAction::make('broker'),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ContactsRelationManager::class,
            PortsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageBrokers::route('/'),
            'view' => ViewBroker::route('/{record}'),
        ];
    }
}

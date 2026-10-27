<?php

namespace App\Filament\Resources\NfseRules;

use App\Actions\Cobranca\Nfse\ServiceTaker;
use App\Actions\NfseRules\SaveNfseRule;
use App\Filament\Resources\NfseRules\Pages\ManageNfseRules;
use App\Models\FinClientsNfseRegra;
use App\Models\Pessoa;
use App\Models\RegraDespesaCobranca;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** nfseRegraCliente (legado): regras de NFS-e por cliente, lidas na emissão (tomador padrão e bloqueio). */
class NfseRuleResource extends Resource
{
    public const TAKERS = [ServiceTaker::CLIENT => 'Cliente da Reserva', ServiceTaker::BL => 'Dados do BL'];

    protected static ?string $model = FinClientsNfseRegra::class;

    protected static ?string $modelLabel = 'Regra de NFS-e';

    protected static ?string $pluralModelLabel = 'Regras de NFS-e por Cliente';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 20;

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
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Pessoa::query()
                        ->where('nome', 'like', "%{$search}%")
                        ->orWhere('cnpj', 'like', "%{$search}%")
                        ->orderBy('nome')
                        ->limit(30)
                        ->pluck('nome', 'pessoa_id'))
                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome'))
                    ->columnSpanFull(),
                Select::make('tomador')
                    ->label('Tomador')
                    ->options(self::TAKERS)
                    ->default(ServiceTaker::BL)
                    ->required(),
                Toggle::make('bloquear_emissao')
                    ->label('Bloquear emissão de NFSe')
                    ->inline(false),
                Textarea::make('justificativa')
                    ->label('Justificativa')
                    ->required()
                    ->minLength(3)
                    ->rows(3)
                    ->columnSpanFull(),
                Toggle::make('separar_nf_grupo_despesas')
                    ->label('Separar NFS por Grupo de Despesas')
                    ->live(),
                Select::make('expense_rules')
                    ->label('Regras de despesas')
                    ->multiple()
                    ->options(fn () => RegraDespesaCobranca::query()
                        ->orderBy('nome')
                        ->pluck('nome', 'regra_despesa_cobranca_id'))
                    ->required(fn (Get $get) => (bool) $get('separar_nf_grupo_despesas'))
                    ->visible(fn (Get $get) => (bool) $get('separar_nf_grupo_despesas')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'updatedBy', 'expenseRules']))
            ->defaultSort('ref_client')
            ->columns([
                TextColumn::make('client.nome')
                    ->label('Cliente')
                    ->searchable(),
                TextColumn::make('tomador')
                    ->label('Tomador')
                    ->formatStateUsing(fn ($state) => self::TAKERS[$state] ?? $state),
                IconColumn::make('bloquear_emissao')
                    ->label('Bloqueia emissão')
                    ->boolean(),
                TextColumn::make('expenseRules.nome')
                    ->label('NFS por grupo de despesas')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('justificativa')
                    ->label('Justificativa')
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('updatedBy.nome')
                    ->label('Atualizado por')
                    ->description(fn (FinClientsNfseRegra $r) => $r->updated_at?->format('d/m/Y H:i')),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data, FinClientsNfseRegra $record) => [
                        ...$data,
                        'expense_rules' => $record->expenseRules()->pluck('regra_despesa_cobranca.regra_despesa_cobranca_id')->all(),
                    ])
                    ->using(fn (FinClientsNfseRegra $record, array $data) => app(SaveNfseRule::class)($data, auth()->id(), $record)),
                DeleteAction::make()
                    ->before(fn (FinClientsNfseRegra $record) => $record->expenseRules()->detach()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageNfseRules::route('/'),
        ];
    }
}

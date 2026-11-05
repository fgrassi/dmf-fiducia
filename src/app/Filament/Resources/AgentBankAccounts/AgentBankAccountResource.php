<?php

namespace App\Filament\Resources\AgentBankAccounts;

use App\Filament\Resources\AgentBankAccounts\Pages\ManageAgentBankAccounts;
use App\Filament\Resources\VariedExpenses\VariedExpenseResource;
use App\Models\AgenteBanco;
use App\Models\Pessoa;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * controleCreditDebitAgente/contas, addContas e form (legado): contas bancárias dos agentes. No legado só existiam dentro
 * da consulta de notas, depois de escolher o agente; aqui é uma tela própria com todas as contas e filtro por agente.
 * Incluir e editar, sem excluir (como o legado).
 */
class AgentBankAccountResource extends Resource
{
    protected static ?string $model = AgenteBanco::class;

    protected static ?string $modelLabel = 'Conta Bancária';

    protected static ?string $pluralModelLabel = 'Contas Bancárias dos Agentes';

    protected static ?string $navigationLabel = 'Contas Bancárias dos Agentes';

    protected static ?string $slug = 'financeiro/contas-bancarias-agentes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Config IT';

    protected static ?int $navigationSort = 28;

    /** security.yml do módulo (legado): credenciais manager e financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential(['manager', 'financial']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Select::make('agente_id')
                    ->label('Agente')
                    ->required()
                    ->disabledOn('edit')
                    ->searchable()
                    ->columnSpanFull()
                    ->getSearchResultsUsing(fn (string $search) => self::agentOptions($search))
                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome')),
                TextInput::make('nome_banco')
                    ->label('Banco')
                    ->required()
                    ->maxLength(255),
                TextInput::make('endereco_banco')
                    ->label('Endereço Banco')
                    ->maxLength(255),
                TextInput::make('nome_conta')
                    ->label('Nome da Conta')
                    ->maxLength(255),
                TextInput::make('numero_conta')
                    ->label('Número da Conta')
                    ->maxLength(255),
                TextInput::make('swift')
                    ->label('Swift')
                    ->maxLength(255),
                TextInput::make('iban')
                    ->label('IBAN')
                    ->maxLength(255),
                Textarea::make('endereco_agente')
                    ->label('Endereço do Agente')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('agent'))
            ->defaultSort('agente_banco_id', 'desc')
            ->emptyStateHeading('Nenhuma conta cadastrada')
            ->columns([
                TextColumn::make('agent.nome')
                    ->label('Agente')
                    ->wrap(),
                TextColumn::make('nome_banco')
                    ->label('Banco')
                    ->wrap(),
                TextColumn::make('endereco_banco')
                    ->label('Endereço Banco')
                    ->wrap(),
                TextColumn::make('nome_conta')
                    ->label('Nome da Conta'),
                TextColumn::make('numero_conta')
                    ->label('Número da Conta'),
                TextColumn::make('swift')
                    ->label('Swift'),
                TextColumn::make('iban')
                    ->label('IBAN'),
                TextColumn::make('endereco_agente')
                    ->label('Endereço do Agente')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('agente_id')
                    ->label('Agente')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => self::agentOptions($search))
                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome')),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Editar')
                    ->modalWidth('4xl'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAgentBankAccounts::route('/'),
        ];
    }

    /** @return Collection<int, string> */
    private static function agentOptions(string $search)
    {
        return VariedExpenseResource::agents()
            ->where('pessoa.nome', 'like', "%{$search}%")
            ->limit(30)
            ->pluck('pessoa.nome', 'pessoa.pessoa_id');
    }
}

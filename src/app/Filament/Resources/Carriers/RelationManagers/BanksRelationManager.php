<?php

namespace App\Filament\Resources\Carriers\RelationManagers;

use App\Models\CmnBanks;
use App\Models\FinArmadorBanks;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * banco, addBanco e removeBanco (legado): contas em que o armador recebe. A exclusão é lógica (deleted_at/by) e as
 * contas de FinArmadorBanks::DISABLED_IDS não aparecem.
 */
class BanksRelationManager extends RelationManager
{
    protected static string $relationship = 'banks';

    protected static ?string $title = 'Dados Bancários';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('ref_bank')
                    ->label('Banco')
                    ->options(fn () => CmnBanks::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                TextInput::make('bank_agency')
                    ->label('Agencia')
                    ->required()
                    ->maxLength(30),
                TextInput::make('bank_account')
                    ->label('Conta Corrente')
                    ->required()
                    ->maxLength(50),
                TextInput::make('id_code')
                    ->label('Cód. de Ident.')
                    ->maxLength(150),
                Textarea::make('obs')
                    ->label('Obs.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->active()->with('bank'))
            ->emptyStateHeading('Nenhum registro encontrado')
            ->columns([
                TextColumn::make('bank.name')
                    ->label('Banco'),
                TextColumn::make('bank.bank_number')
                    ->label('Número'),
                TextColumn::make('bank_agency')
                    ->label('Agencia'),
                TextColumn::make('bank_account')
                    ->label('Conta'),
                TextColumn::make('id_code')
                    ->label('Codigo'),
                TextColumn::make('obs')
                    ->label('Descrição'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar novo banco')
                    ->mutateDataUsing(fn (array $data) => [...$data, 'created_at' => now()->toDateString(), 'created_by' => auth()->id()]),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateDataUsing(fn (array $data) => [...$data, 'updated_at' => now()->toDateString(), 'updated_by' => auth()->id()]),
                DeleteAction::make()
                    ->modalDescription('Confirmar exclusão?')
                    ->using(fn (FinArmadorBanks $record) => $record->update(['deleted_at' => now(), 'deleted_by' => auth()->id()])),
            ]);
    }
}

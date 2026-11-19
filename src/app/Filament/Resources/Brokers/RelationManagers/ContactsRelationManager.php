<?php

namespace App\Filament\Resources\Brokers\RelationManagers;

use App\Actions\Brokers\SaveBrokerContact;
use App\Models\Despachante;
use App\Models\DespachanteContato;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** despachante/contatos, addContato e removeContact (legado): contatos do despachante; excluir só marca status -1. */
class ContactsRelationManager extends RelationManager
{
    private const STATUS = [0 => 'Aguardando aprovação', 1 => 'OK'];

    private const KINDS = ['i' => 'IMPO', 'e' => 'EXPO'];

    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Contatos';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        /** @var Despachante $broker */
        $broker = $this->getOwnerRecord();

        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->live(onBlur: true)
                    ->maxLength(100),
                Toggle::make('tipo')
                    ->label('Desconsolidação')
                    ->formatStateUsing(fn ($state) => $state === 'd')
                    ->dehydrateStateUsing(fn ($state) => $state ? 'd' : ''),
                Select::make('impor_expor')
                    ->label('Tipo de Contato')
                    ->options(self::KINDS)
                    ->default('i')
                    ->required(),
                Textarea::make('justificativa')
                    ->label('Justificativa')
                    ->helperText("O domínio informado é diferente do cadastrado para esse despachante ({$broker->domain}). Por favor insira uma justificativa.")
                    ->required()
                    ->visible(fn (Get $get) => filled($broker->domain)
                        && filled($get('email'))
                        && str($get('email'))->after('@')->toString() !== $broker->domain),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('status', '<>', -1)->orWhereNull('status')))
            ->emptyStateHeading('Nenhum registro encontrado')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome'),
                TextColumn::make('email')
                    ->label('Email'),
                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn ($state) => self::STATUS[(int) $state] ?? 'OK')
                    ->badge(),
                TextColumn::make('tipo')
                    ->label('Desconsolidação')
                    ->formatStateUsing(fn ($state) => $state === 'd' ? 'SIM' : 'NÃO'),
                TextColumn::make('impor_expor')
                    ->label('Tipo de Contato')
                    ->formatStateUsing(fn ($state) => self::KINDS[$state] ?? ''),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar contato')
                    ->using(fn (array $data) => app(SaveBrokerContact::class)($this->getOwnerRecord(), $data, auth()->id())),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (DespachanteContato $record, array $data) => app(SaveBrokerContact::class)($this->getOwnerRecord(), $data, auth()->id(), $record)),
                DeleteAction::make()
                    ->modalDescription('Confirmar exclusão?')
                    ->using(fn (DespachanteContato $record) => $record->update(['status' => -1])),
            ]);
    }
}

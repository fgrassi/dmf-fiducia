<?php

namespace App\Filament\Resources\Tradings\RelationManagers;

use App\Actions\Trading\SaveTradingContact;
use App\Models\Trading;
use App\Models\TradingContato;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** trading/contato, addContato e removeContact (legado): contatos da trading; excluir só marca status -1. */
class ContactsRelationManager extends RelationManager
{
    private const STATUS = [0 => 'Aguardando aprovação', 1 => 'OK'];

    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Contatos';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        /** @var Trading $trading */
        $trading = $this->getOwnerRecord();

        return $schema
            ->components([
                TextInput::make('nome')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->live(onBlur: true)
                    ->maxLength(255),
                Textarea::make('justificativa')
                    ->label('Justificativa')
                    ->helperText("O domínio informado é diferente do cadastrado para essa trading ({$trading->domain}). Por favor insira uma justificativa.")
                    ->required()
                    ->visible(fn (Get $get) => filled($trading->domain)
                        && filled($get('email'))
                        && str($get('email'))->after('@')->toString() !== $trading->domain),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('status', '<>', -1)->orWhereNull('status')))
            ->emptyStateHeading('Nenhum registro encontrado')
            ->columns([
                TextColumn::make('nome')
                    ->label('Nome'),
                TextColumn::make('email')
                    ->label('Email'),
                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn ($state) => self::STATUS[(int) $state] ?? 'OK')
                    ->badge(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar contato')
                    ->using(fn (array $data) => app(SaveTradingContact::class)($this->getOwnerRecord(), $data, auth()->id())),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->modalDescription('Confirmar exclusão?')
                    ->using(fn (TradingContato $record) => $record->update(['status' => -1])),
            ]);
    }
}

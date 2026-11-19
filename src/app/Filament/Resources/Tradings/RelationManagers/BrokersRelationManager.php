<?php

namespace App\Filament\Resources\Tradings\RelationManagers;

use App\Actions\Trading\SyncTradingBrokerContacts;
use App\Models\Despachante;
use App\Models\TradingDespachante;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * trading/listDespachantes, despachante e removeDespachante (legado): despachantes da trading e, de cada um, quais
 * contatos ativos recebem (tracking, financeiro ou ambos) e se recebem o relatório em PDF.
 */
class BrokersRelationManager extends RelationManager
{
    protected static string $relationship = 'brokers';

    protected static ?string $title = 'Despachantes';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('despachante_id')
                    ->label('Despachante')
                    ->options(fn () => Despachante::orderBy('nome')->pluck('nome', 'despachante_id'))
                    ->searchable()
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('broker'))
            ->emptyStateHeading('Nenhum despachante vinculado')
            ->columns([
                TextColumn::make('broker.nome')
                    ->label('Nome'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Vincular despachante'),
            ])
            ->recordActions([
                Action::make('contacts')
                    ->label('Contatos')
                    ->icon('heroicon-o-users')
                    ->modalHeading(fn (TradingDespachante $record) => "Contatos de {$record->broker?->nome}")
                    ->fillForm(fn (TradingDespachante $record) => ['contacts' => $this->contactRows($record)])
                    ->schema([
                        Repeater::make('contacts')
                            ->hiddenLabel()
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(4)
                            ->schema([
                                Hidden::make('contact_id'),
                                TextInput::make('label')
                                    ->label('Contato')
                                    ->disabled()
                                    ->dehydrated(false),
                                Toggle::make('linked')
                                    ->label('Vincular'),
                                Select::make('tipo')
                                    ->label('Tipo')
                                    ->options(['t' => 'Tracking', 'f' => 'Financeiro', 'a' => 'Tracking / Financeiro'])
                                    ->default('t')
                                    ->required(),
                                Select::make('relatorio')
                                    ->label('Receber relatórios')
                                    ->options(['0' => 'Não', '1' => 'Sim'])
                                    ->default('0')
                                    ->required(),
                            ]),
                    ])
                    ->action(fn (TradingDespachante $record, array $data) => app(SyncTradingBrokerContacts::class)(
                        $record->trading_id,
                        $record->despachante_id,
                        $data['contacts'],
                    )),
                DeleteAction::make()
                    ->modalDescription('Tem certeza que deseja remover esse Despachante?')
                    ->using(function (TradingDespachante $record) {
                        DB::table('trading_contato_despachante')->where('trading_id', $record->trading_id)->where('despachante_id', $record->despachante_id)->delete();
                        $record->delete();
                    }),
            ]);
    }

    /** Contatos ativos do despachante (status 1), com o vínculo atual com a trading. */
    private function contactRows(TradingDespachante $record): array
    {
        $links = DB::table('trading_contato_despachante')
            ->where('trading_id', $record->trading_id)
            ->where('despachante_id', $record->despachante_id)
            ->get()
            ->keyBy('despachante_contato_id');

        return DB::table('despachante_contato')
            ->where('ref_despachante', $record->despachante_id)
            ->where('status', 1)
            ->orderBy('name')
            ->get()
            ->map(fn ($contact) => [
                'contact_id' => $contact->id,
                'label' => "{$contact->name} ({$contact->email})",
                'linked' => $links->has($contact->id),
                'tipo' => $links[$contact->id]->tipo ?? 't',
                'relatorio' => $links[$contact->id]->relatorio ?? '0',
            ])
            ->all();
    }
}

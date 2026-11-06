<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Actions\Comissoes\AddCommission;
use App\Actions\Comissoes\RemoveCommission;
use App\Actions\Comissoes\ShippingCommissionsReport;
use App\Models\CmnCurrency;
use App\Models\ComissaoEmbarque;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Comissão do embarque (comissaoEmbarque / addComissao / removeComissao do booking, legado). */
class CommissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'commissions';

    protected static ?string $title = 'Comissões';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)
                    ->schema([
                        Select::make('responsavel_id')
                            ->label('Responsável')
                            ->required()
                            ->searchable()
                            ->options(fn () => $this->responsibles())
                            ->createOptionForm([
                                TextInput::make('nome')
                                    ->label('Nome')
                                    ->required()
                                    ->maxLength(255),
                                Textarea::make('dados_bancarios')
                                    ->label('Dados bancários'),
                            ])
                            ->createOptionUsing(fn (array $data) => DB::table('comissao_embarque_responsavel')->insertGetId([
                                'nome' => $data['nome'],
                                'dados_bancarios' => $data['dados_bancarios'] ?? null,
                            ])),
                        Select::make('tipo_comissao')
                            ->label('Tipo de comissão')
                            ->required()
                            ->options(ShippingCommissionsReport::COMMISSION_TYPES)
                            ->default(0)
                            ->selectablePlaceholder(false),
                        Select::make('tipo')
                            ->label('Tipo')
                            ->options(ShippingCommissionsReport::DIRECTIONS)
                            ->default(0)
                            ->selectablePlaceholder(false),
                        Select::make('moeda_id')
                            ->label('Moeda')
                            ->required()
                            ->options(fn () => CmnCurrency::query()->orderBy('name')->get()->mapWithKeys(fn (CmnCurrency $c) => [$c->id => "{$c->symbol} - {$c->name}"])),
                        TextInput::make('valor_unitario')
                            ->label('Valor unitário')
                            ->required()
                            ->numeric(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('comissao_embarque.*')
                ->addSelect(['responsavel' => DB::table('comissao_embarque_responsavel')->select('nome')->whereColumn('comissao_embarque_responsavel.comissao_embarque_responsavel_id', 'comissao_embarque.comissao_embarque_responsavel_id')])
                ->with('moeda')
                ->orderBy('comissao_embarque.comissao_embarque_id'))
            ->paginated(false)
            ->columns([
                TextColumn::make('responsavel')
                    ->label('Responsável'),
                TextColumn::make('tipo_comissao')
                    ->label('Tipo de comissão')
                    ->formatStateUsing(fn ($state) => ShippingCommissionsReport::COMMISSION_TYPES[(int) $state] ?? ''),
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => ShippingCommissionsReport::DIRECTIONS[(int) $state] ?? ''),
                TextColumn::make('moeda.name')
                    ->label('Moeda')
                    ->formatStateUsing(fn ($state, ComissaoEmbarque $record) => $record->moeda ? "{$record->moeda->name} ({$record->moeda->symbol})" : ''),
                TextColumn::make('valor_unitario')
                    ->label('Valor unitário')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar comissão')
                    ->using(fn (array $data) => ComissaoEmbarque::findOrFail(app(AddCommission::class)($this->getOwnerRecord()->getKey(), $data, auth()->id()))),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->modalHeading('Tem certeza de que deseja remover este item?')
                    ->using(fn (ComissaoEmbarque $record) => app(RemoveCommission::class)($record->getKey(), auth()->id())),
            ]);
    }

    /** @return array<int, string> */
    private function responsibles(): array
    {
        return DB::table('comissao_embarque_responsavel')->orderBy('nome')->pluck('nome', 'comissao_embarque_responsavel_id')->all();
    }
}

<?php

namespace App\Filament\Resources\Commissioned;

use App\Filament\Pages\ShippingCommissions;
use App\Filament\Resources\Commissioned\Pages\ManageCommissioned;
use App\Models\ComissaoEmbarqueResponsavel;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** comissoes/list (legado), "Relação de Comissionados": quem recebe ou paga comissão do embarque; o nome abre as comissões dele. */
class CommissionedResource extends Resource
{
    protected static ?string $model = ComissaoEmbarqueResponsavel::class;

    protected static ?string $modelLabel = 'Comissionado';

    protected static ?string $pluralModelLabel = 'Comissionados';

    protected static ?string $navigationLabel = 'Comissionados';

    protected static ?string $slug = 'financeiro/relatorios/comissionados';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Relatórios';

    protected static ?int $navigationSort = 13;

    /** Mesma credencial do relatório de comissões. */
    public static function canAccess(): bool
    {
        return ShippingCommissions::canAccess();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nome')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                Textarea::make('dados_bancarios')
                    ->label('Dados bancários'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['commissions as open_count' => fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('status')->orWhere('status', 0))]))
            ->defaultSort('nome')
            ->columns([
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable()
                    ->url(fn (ComissaoEmbarqueResponsavel $record) => ShippingCommissions::getUrl(['responsavel_id' => $record->getKey()])),
                TextColumn::make('open_count')
                    ->label('Comissões em aberto')
                    ->alignCenter()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('comissoes')
                    ->label('Comissões')
                    ->icon(Heroicon::OutlinedReceiptPercent)
                    ->url(fn (ComissaoEmbarqueResponsavel $record) => ShippingCommissions::getUrl(['responsavel_id' => $record->getKey()])),
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (ComissaoEmbarqueResponsavel $record) => $record->isReferenced()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCommissioned::route('/'),
        ];
    }
}

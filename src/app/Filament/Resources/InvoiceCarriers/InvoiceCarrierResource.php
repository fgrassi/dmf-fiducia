<?php

namespace App\Filament\Resources\InvoiceCarriers;

use App\Filament\Resources\InvoiceCarriers\Pages\ManageInvoiceCarriers;
use App\Models\Armador;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** incluir/editar/excluirArmador do controleinvoice (legado): armadores que exigem invoice por operação. */
class InvoiceCarrierResource extends Resource
{
    public const OPERATIONS = ['invoice_impo' => 'Importação', 'invoice_expo' => 'Exportação'];

    protected static ?string $model = Armador::class;

    protected static ?string $modelLabel = 'Armador';

    protected static ?string $pluralModelLabel = 'Armadores com controle de invoice';

    protected static ?string $navigationLabel = 'Armadores';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Controle de Invoices';

    protected static ?int $navigationSort = 13;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public static function enabled(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('invoice_impo', 1)->orWhere('invoice_expo', 1));
    }

    /** CheckboxList "operações" → colunas; ao menos uma é obrigatória (required no form). */
    public static function operations(array $chosen): array
    {
        return collect(self::OPERATIONS)
            ->map(fn ($label, $column) => (int) in_array($column, $chosen, true))
            ->all();
    }

    public static function operationsField(): CheckboxList
    {
        return CheckboxList::make('operacoes')
            ->label('Operações')
            ->options(self::OPERATIONS)
            ->required()
            ->validationMessages(['required' => 'Selecione ao menos uma operação.']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => self::enabled($query))
            ->defaultSort('nome')
            ->paginated(false)
            ->emptyStateHeading('Nenhum armador com controle de invoice habilitado.')
            ->columns([
                TextColumn::make('nome')
                    ->label('Armador')
                    ->searchable(),
                IconColumn::make('invoice_impo')
                    ->label('Importação')
                    ->boolean(),
                IconColumn::make('invoice_expo')
                    ->label('Exportação')
                    ->boolean(),
            ])
            ->recordActions([
                Action::make('editar')
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->modalHeading(fn (Armador $record) => "Editar {$record->nome}")
                    ->fillForm(fn (Armador $record) => ['operacoes' => array_keys(array_filter($record->only(array_keys(self::OPERATIONS))))])
                    ->schema([self::operationsField()])
                    ->action(function (Armador $record, array $data, Action $action) {
                        $record->forceFill(self::operations($data['operacoes']))->save();
                        $action->success();
                    })
                    ->successNotificationTitle('Armador atualizado.'),
                Action::make('remover')
                    ->label('Remover')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Armador $record) => "Remover {$record->nome} do controle de invoice?")
                    ->action(function (Armador $record, Action $action) {
                        $record->forceFill(self::operations([]))->save();
                        $action->success();
                    })
                    ->successNotificationTitle('Armador removido do controle de invoice.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageInvoiceCarriers::route('/'),
        ];
    }
}

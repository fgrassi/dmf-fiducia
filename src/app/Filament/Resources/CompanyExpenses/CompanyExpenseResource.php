<?php

namespace App\Filament\Resources\CompanyExpenses;

use App\Actions\Expenses\ConfirmExpensePayment;
use App\Actions\Expenses\DeleteExpense;
use App\Actions\Expenses\DuplicateExpense;
use App\Actions\Expenses\ManageExpenseFiles;
use App\Actions\Expenses\SaveExpense;
use App\Filament\Resources\CompanyExpenses\Pages\ManageCompanyExpenses;
use App\Models\CategoriaDespesa;
use App\Models\CentroCusto;
use App\Models\CmnBanks;
use App\Models\CmnCurrency;
use App\Models\Despesa;
use App\Models\Fornecedor;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * despesa (legado): "Relação de Despesas", as contas a pagar da empresa. Fornecedores, centros de custo, categorias e
 * vencimentos (as outras abas do legado) ficam em Configuração Despesa (ExpenseSettings). Edição direta nas células vira o
 * formulário de edição.
 */
class CompanyExpenseResource extends Resource
{
    protected static ?string $model = Despesa::class;

    protected static ?string $modelLabel = 'Despesa';

    protected static ?string $pluralModelLabel = 'Relação de Despesas';

    protected static ?string $navigationLabel = 'Relação de Despesas';

    protected static ?string $slug = 'financeiro/despesas/relacao';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Despesas';

    protected static ?int $navigationSort = 1;

    /** Bancos que o legado esconde da lista do pagamento. */
    private const HIDDEN_BANKS = [5, 8, 9];

    /** index do legado: diretor, financeiro com gerente ou o usuário 2972; vale o mesmo para as outras abas. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canManageCompanyExpenses();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                self::nameSelect('fornecedor_id', 'Fornecedor', 'supplier'),
                self::nameSelect('centro_custo_id', 'Centro de Custo', 'costCenter'),
                self::nameSelect('categoria_despesa_id', 'Categoria', 'category'),
                Select::make('empresa')
                    ->label('Empresa')
                    ->options(Despesa::COMPANIES),
                TextInput::make('valor')
                    ->label('Valor')
                    ->required()
                    ->numeric(),
                Select::make('moeda_id')
                    ->label('Moeda')
                    ->options(fn () => self::currencies()),
                DatePicker::make('data_prevista')
                    ->label('Data Prevista')
                    ->default(today())
                    ->required(),
                Select::make('tipo_despesa')
                    ->label('Tipo de Despesa')
                    ->options(Despesa::EXPENSE_TYPES)
                    ->default(1)
                    ->selectablePlaceholder(false)
                    ->required(),
                TextInput::make('nota_fiscal')
                    ->label('Nota Fiscal')
                    ->maxLength(50),
                Textarea::make('descricao')
                    ->label('Descrição')
                    ->columnSpanFull(),
                Textarea::make('observacao')
                    ->label('Observação')
                    ->columnSpanFull(),
                Select::make('tipo_pagamento')
                    ->label('Tipo de Pagamento')
                    ->options(Despesa::PAYMENT_TYPES)
                    ->hiddenOn('create'),
                Select::make('banco_id')
                    ->label('Banco')
                    ->options(fn () => self::banks())
                    ->hiddenOn('create'),
                Select::make('status')
                    ->label('Status')
                    ->options(Despesa::STATUSES)
                    ->selectablePlaceholder(false)
                    ->hiddenOn('create'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier', 'costCenter', 'category', 'currency', 'bank']))
            ->defaultSort('despesa_id', 'desc')
            ->paginationPageOptions([40, 100])
            ->defaultPaginationPageOption(40)
            ->filtersFormColumns(3)
            ->emptyStateHeading('Nenhuma despesa encontrada')
            ->columns([
                TextColumn::make('supplier.nome')
                    ->label('Fornecedor')
                    ->wrap(),
                TextColumn::make('empresa')
                    ->label('Empresa')
                    ->formatStateUsing(fn (?string $state) => Despesa::COMPANIES[$state] ?? ''),
                TextColumn::make('costCenter.nome')
                    ->label('Centro de Custo')
                    ->wrap(),
                TextColumn::make('category.nome')
                    ->label('Categoria')
                    ->wrap(),
                TextColumn::make('descricao')
                    ->label('Descrição')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('valor')
                    ->label('Valor (R$)')
                    ->numeric(2, ',', '.')
                    ->alignEnd(),
                TextColumn::make('currency.symbol')
                    ->label('Moeda'),
                TextColumn::make('nota_fiscal')
                    ->label('Nota Fiscal')
                    ->searchable(),
                TextColumn::make('tipo_pagamento')
                    ->label('Tipo Pagamento')
                    ->formatStateUsing(fn ($state) => Despesa::PAYMENT_TYPES[$state] ?? ''),
                TextColumn::make('bank.name')
                    ->label('Banco'),
                TextColumn::make('data_prevista')
                    ->label('Data Prevista')
                    ->date('d/m/Y'),
                TextColumn::make('data_efetiva')
                    ->label('Data Efetiva')
                    ->date('d/m/Y')
                    ->placeholder('Pagar')
                    ->icon(Heroicon::OutlinedCurrencyDollar)
                    ->iconPosition(IconPosition::After)
                    ->color('primary')
                    ->action(self::paymentAction()),
                TextColumn::make('observacao')
                    ->label('Observação')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tipo_despesa')
                    ->label('Tipo Despesa')
                    ->formatStateUsing(fn ($state) => Despesa::EXPENSE_TYPES[$state] ?? ''),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Despesa::STATUSES[$state] ?? '')
                    ->color(fn ($state) => (int) $state === Despesa::STATUS_CLOSED ? 'success' : 'warning'),
            ])
            ->filters([
                SelectFilter::make('fornecedor_id')
                    ->label('Fornecedor')
                    ->multiple()
                    ->searchable()
                    ->options(fn () => Fornecedor::query()->orderBy('nome')->pluck('nome', 'fornecedor_id')),
                SelectFilter::make('centro_custo_id')
                    ->label('Centro de Custo')
                    ->options(fn () => CentroCusto::query()->orderBy('nome')->pluck('nome', 'centro_custo_id')),
                SelectFilter::make('categoria_despesa_id')
                    ->label('Categoria')
                    ->multiple()
                    ->searchable()
                    ->options(fn () => CategoriaDespesa::query()->orderBy('nome')->pluck('nome', 'categoria_despesa_id')),
                SelectFilter::make('banco_id')
                    ->label('Banco')
                    ->options(fn () => self::banks()),
                SelectFilter::make('tipo_pagamento')
                    ->label('Tipo de Pagamento')
                    ->options(Despesa::PAYMENT_TYPES),
                SelectFilter::make('tipo_despesa')
                    ->label('Tipo de Despesa')
                    ->options(Despesa::EXPENSE_TYPES),
                SelectFilter::make('empresa')
                    ->label('Empresa')
                    ->options(Despesa::COMPANIES),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(Despesa::STATUSES)
                    ->placeholder('Todos')
                    ->default((string) Despesa::STATUS_OPEN),
                Filter::make('busca')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('descricao')->label('Descrição'),
                            TextInput::make('valor')->label('Valor')->numeric(),
                            TextInput::make('nota_fiscal')->label('Nota Fiscal'),
                            TextInput::make('observacao')->label('Observação'),
                            DatePicker::make('prevista_de')->label('Data prevista de'),
                            DatePicker::make('prevista_ate')->label('Data prevista até'),
                            DatePicker::make('efetiva_de')->label('Data efetiva de'),
                            DatePicker::make('efetiva_ate')->label('Data efetiva até'),
                        ]),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when(filled($data['descricao'] ?? null), fn (Builder $query) => $query->where('descricao', 'like', '%'.trim($data['descricao']).'%'))
                        ->when(filled($data['valor'] ?? null), fn (Builder $query) => $query->where('valor', $data['valor']))
                        ->when(filled($data['nota_fiscal'] ?? null), fn (Builder $query) => $query->where('nota_fiscal', $data['nota_fiscal']))
                        ->when(filled($data['observacao'] ?? null), fn (Builder $query) => $query->where('observacao', $data['observacao']))
                        ->when(filled($data['prevista_de'] ?? null), fn (Builder $query) => $query->whereDate('data_prevista', '>=', $data['prevista_de']))
                        ->when(filled($data['prevista_ate'] ?? null), fn (Builder $query) => $query->whereDate('data_prevista', '<=', $data['prevista_ate']))
                        ->when(filled($data['efetiva_de'] ?? null), fn (Builder $query) => $query->whereDate('data_efetiva', '>=', $data['efetiva_de']))
                        ->when(filled($data['efetiva_ate'] ?? null), fn (Builder $query) => $query->whereDate('data_efetiva', '<=', $data['efetiva_ate'])))
                    ->indicateUsing(fn (array $data) => self::busca($data)),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->recordActions([
                EditAction::make()
                    ->label('Editar')
                    ->modalWidth('4xl')
                    ->using(fn (array $data, Despesa $record) => app(SaveExpense::class)($data, (int) auth()->id(), $record)),
                Action::make('arquivos')
                    ->label('Arquivos')
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->color('gray')
                    ->modalHeading('Arquivos da Despesa')
                    ->modalSubmitActionLabel('Salvar')
                    ->schema(fn (Despesa $record) => [
                        Placeholder::make('atuais')
                            ->label('Arquivos anexados')
                            ->content(fn () => self::fileLinks($record)),
                        CheckboxList::make('remover')
                            ->label('Remover arquivos')
                            ->options($record->files()->pluck('arquivo', 'arquivo_despesa_id')->all())
                            ->visible($record->files()->exists()),
                        FileUpload::make('novos')
                            ->label('Anexar arquivos')
                            ->multiple()
                            ->maxFiles(10)
                            ->maxSize(10240)
                            ->acceptedFileTypes([
                                'application/pdf', 'image/png', 'image/jpeg',
                                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            ])
                            ->storeFiles(false),
                    ])
                    ->action(function (array $data, Despesa $record, ManageExpenseFiles $files) {
                        try {
                            $files->attach($record, array_values($data['novos'] ?? []));
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        $files->remove($record, $data['remover'] ?? []);
                        Notification::make()->title('Arquivos atualizados')->success()->send();
                    }),
                Action::make('duplicar')
                    ->label('Duplicar')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Duplicar despesa?')
                    ->action(function (Despesa $record, DuplicateExpense $duplicate) {
                        $duplicate($record, (int) auth()->id());
                        Notification::make()->title('Despesa duplicada')->success()->send();
                    }),
                Action::make('remover')
                    ->label('Remover')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Confirmar exclusão?')
                    ->action(function (Despesa $record, DeleteExpense $delete) {
                        $delete($record);
                        Notification::make()->title('Despesa removida')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCompanyExpenses::route('/'),
        ];
    }

    /**
     * Texto dos filtros ativos que o relatório imprime ("Filtros Ativos:" do legado).
     *
     * @param  array<string, mixed>  $filters  estado de `tableFilters`
     * @return list<string>
     */
    public static function activeFilterLabels(array $filters): array
    {
        $names = fn (string $model, string $column, mixed $ids) => $model::query()->whereKey(array_filter((array) $ids))->pluck($column)->implode(', ');
        $labels = [];

        if (filled($filters['fornecedor_id']['values'] ?? null)) {
            $labels[] = 'Fornecedor: '.$names(Fornecedor::class, 'nome', $filters['fornecedor_id']['values']);
        }
        if (filled($filters['empresa']['value'] ?? null)) {
            $labels[] = 'Empresa: '.(Despesa::COMPANIES[$filters['empresa']['value']] ?? '');
        }
        if (filled($filters['centro_custo_id']['value'] ?? null)) {
            $labels[] = 'Centro de Custo: '.$names(CentroCusto::class, 'nome', $filters['centro_custo_id']['value']);
        }
        if (filled($filters['categoria_despesa_id']['values'] ?? null)) {
            $labels[] = 'Categoria: '.$names(CategoriaDespesa::class, 'nome', $filters['categoria_despesa_id']['values']);
        }
        if (filled($filters['banco_id']['value'] ?? null)) {
            $labels[] = 'Banco: '.CmnBanks::query()->whereKey($filters['banco_id']['value'])->value('name');
        }
        if (filled($filters['tipo_pagamento']['value'] ?? null)) {
            $labels[] = 'Tipo Pagamento: '.(Despesa::PAYMENT_TYPES[$filters['tipo_pagamento']['value']] ?? '');
        }
        if (filled($filters['tipo_despesa']['value'] ?? null)) {
            $labels[] = 'Tipo Despesa: '.(Despesa::EXPENSE_TYPES[$filters['tipo_despesa']['value']] ?? '');
        }
        if (filled($filters['status']['value'] ?? null)) {
            $labels[] = 'Status: '.(Despesa::STATUSES[$filters['status']['value']] ?? '');
        }

        return [...$labels, ...self::busca($filters['busca'] ?? [])];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function busca(array $data): array
    {
        $date = fn (string $key) => filled($data[$key] ?? null) ? date('d/m/Y', strtotime($data[$key])) : null;

        return array_values(array_filter([
            filled($data['descricao'] ?? null) ? 'Descrição: '.$data['descricao'] : null,
            filled($data['valor'] ?? null) ? 'Valor: '.$data['valor'] : null,
            filled($data['nota_fiscal'] ?? null) ? 'Nota Fiscal: '.$data['nota_fiscal'] : null,
            filled($data['observacao'] ?? null) ? 'Observação: '.$data['observacao'] : null,
            $date('prevista_de') ? 'Data Prevista Min: '.$date('prevista_de') : null,
            $date('prevista_ate') ? 'Data Prevista Max: '.$date('prevista_ate') : null,
            $date('efetiva_de') ? 'Data Efetiva Min: '.$date('efetiva_de') : null,
            $date('efetiva_ate') ? 'Data Efetiva Max: '.$date('efetiva_ate') : null,
        ]));
    }

    /** Clique na data efetiva (como no legado) abre a confirmação do pagamento. */
    private static function paymentAction(): Action
    {
        return Action::make('pagar')
            ->label('Pagamento')
            ->icon(Heroicon::OutlinedCurrencyDollar)
            ->modalHeading('Confirmando o Pagamento')
            ->modalWidth('3xl')
            ->fillForm(fn (Despesa $record) => [
                'data_efetiva' => $record->data_efetiva ?? today(),
                'tipo_pagamento' => $record->tipo_pagamento ?: null,
                'banco_id' => $record->banco_id,
                'nota_fiscal' => $record->nota_fiscal,
                'juros' => $record->juros,
                'multa' => $record->multa,
                'desconto' => $record->desconto,
                'moeda_id' => $record->moeda_id,
                'taxa' => $record->taxa,
                'valor' => $record->valor,
            ])
            ->schema([
                Grid::make(2)->schema([
                    DatePicker::make('data_efetiva')->label('Data Efetiva')->required(),
                    Select::make('tipo_pagamento')->label('Tipo de Pagamento')->options(Despesa::PAYMENT_TYPES)->required(),
                    Select::make('banco_id')->label('Banco')->options(fn () => self::banks()),
                    TextInput::make('nota_fiscal')->label('Nota Fiscal')->maxLength(50),
                    TextInput::make('juros')->label('Juros')->numeric(),
                    TextInput::make('multa')->label('Multa')->numeric(),
                    TextInput::make('desconto')->label('Desconto')->numeric(),
                    Select::make('moeda_id')->label('Moeda')->options(fn () => self::currencies()),
                    TextInput::make('taxa')->label('Taxa')->numeric(),
                    TextInput::make('valor')->label('Valor')->numeric()->required(),
                ]),
            ])
            ->action(function (array $data, Despesa $record, ConfirmExpensePayment $confirm) {
                $confirm($record, $data, (int) auth()->id());
                Notification::make()->title('Pagamento confirmado')->success()->send();
            });
    }

    /** Select de fornecedor, centro de custo e categoria: o legado cria o registro com o texto digitado se não existir. */
    private static function nameSelect(string $field, string $label, string $relationship): Select
    {
        return Select::make($field)
            ->label($label)
            ->relationship($relationship, 'nome')
            ->searchable()
            ->preload()
            ->required()
            ->createOptionForm([TextInput::make('nome')->label('Nome')->required()->maxLength(255)]);
    }

    /** @return Collection<int|string, string> */
    private static function currencies()
    {
        return CmnCurrency::query()->orderBy('name')->pluck('symbol', 'id');
    }

    /** @return Collection<int|string, string> */
    private static function banks()
    {
        return CmnBanks::query()->whereNotIn('id', self::HIDDEN_BANKS)->orderBy('name')->pluck('name', 'id');
    }

    private static function fileLinks(Despesa $expense): HtmlString
    {
        $base = rtrim((string) config('filesystems.disks.tracking_uploads.url'), '/').'/'.ManageExpenseFiles::DIRECTORY;
        $links = $expense->files->map(fn ($file) => '<a href="'.e($base.'/'.$file->arquivo).'" target="_blank" class="underline">'.e($file->arquivo).'</a>');

        return new HtmlString($links->isEmpty() ? 'Nenhum arquivo anexado' : $links->implode('<br>'));
    }
}

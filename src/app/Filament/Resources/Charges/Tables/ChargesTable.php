<?php

namespace App\Filament\Resources\Charges\Tables;

use App\Actions\Cobranca\Listing\BuildChargeListQuery;
use App\Enums\Cobranca\ChargeStatus;
use App\Filament\Resources\Charges\ChargeResource;
use App\Models\FinCharges;
use App\Models\FinChargesTypes;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ChargesTable
{
    private const NFSE_COLORS = [
        'autorizada' => 'success', 'rejeitada' => 'danger', 'erro' => 'danger', 'erro_http' => 'danger',
        'cancelada' => 'gray', 'substituida' => 'info',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['type', 'bl', 'embarque.cliente', 'latestNfse'])
                ->withSum('items as items_total', 'item_value'))
            ->defaultSort('id', 'desc')
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('id')->label('Nº')->sortable(),
                TextColumn::make('type.name')->label('Tipo'),
                TextColumn::make('embarque.booking')->label('Booking'),
                TextColumn::make('bl.bl')->label('BL'),
                // TextColumn::make('num_nota_fiscal')->label('Número NF'),
                TextColumn::make('embarque.cliente.nome')->label('Cliente')->wrap(),
                TextColumn::make('total')->label('Valor Total')->alignEnd()
                    // legado: soma dos itens + IOF (não o total_value gravado)
                    ->state(fn (FinCharges $r) => 'R$ '.number_format((float) $r->items_total + (float) $r->valor_iof, 2, ',', '.')),
                TextColumn::make('created_at')->label('Data Criação')->date('d/m/Y')->sortable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (ChargeStatus $state) => $state->label())
                    ->color(fn (ChargeStatus $state) => $state->isSettled() ? 'success' : 'gray'),
                IconColumn::make('aging')->label('')
                    ->state(fn (FinCharges $r) => $r->agingColor())
                    ->icon(Heroicon::Flag)
                    ->color(fn (string $state) => $state),
                TextColumn::make('nfse')->label('NFSe')->badge()
                    ->state(fn (FinCharges $r) => $r->latestNfse?->status ?? 'nao_gerada')
                    ->formatStateUsing(fn (string $state) => BuildChargeListQuery::NFSE_OPTIONS[$state === 'erro_http' ? 'erro' : $state] ?? ucfirst($state))
                    ->color(fn (string $state) => self::NFSE_COLORS[$state] ?? 'warning'),
            ])
            ->filters([
                Filter::make('busca')
                    ->label('Busca')
                    ->schema([
                        Fieldset::make('Identificação')->columns(3)->schema([
                            TextInput::make('id_cobranca')->label('Num. Cobrança'),
                            TextInput::make('booking')->label('Booking'),
                            TextInput::make('hbl')->label('HBL'),
                            TextInput::make('cliente')->label('Cliente'),
                            TextInput::make('num_nota_fiscal')->label('Número NF'),
                            TextInput::make('id_fpr')->label('Número Recibo'),
                        ]),
                        Fieldset::make('Classificação')->columns(4)->schema([
                            Select::make('ref_type')->label('Tipo')->options(fn () => FinChargesTypes::pluck('name', 'id')),
                            Select::make('status')->label('Status')
                                ->options(collect(ChargeStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                            Select::make('status_nfse')->label('Status NFSe')->options(BuildChargeListQuery::NFSE_OPTIONS),
                            TextInput::make('valor_total')->label('Valor Total')->placeholder('1.234,56'),
                        ]),
                        Fieldset::make('Datas')->columns(5)->schema([
                            DatePicker::make('created_at')->label('Criação'),
                            DatePicker::make('data_ini')->label('Baixa de'),
                            DatePicker::make('data_fim')->label('Baixa até'),
                            DatePicker::make('venc_ini')->label('Vencimento de'),
                            DatePicker::make('venc_fim')->label('Vencimento até'),
                        ]),
                    ])
                    ->columnSpanFull()
                    ->query(fn (Builder $query, array $data) => app(BuildChargeListQuery::class)->applyFilters($query, $data)),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(1)
            ->recordUrl(fn (FinCharges $record) => ChargeResource::getUrl('view', ['record' => $record]));
    }
}

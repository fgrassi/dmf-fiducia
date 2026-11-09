<?php

namespace App\Filament\Resources\ControlePas;

use App\Filament\Resources\ControlePas\Pages\ListControlePas;
use App\Filament\Resources\ControlePas\Pages\ViewControlePa;
use App\Models\ControlePa;
use App\Models\EmbEmbarqueFile;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** controlePa (legado): Gerenciamento de Freight Invoice (PA), menu Financeiro. */
class ControlePaResource extends Resource
{
    protected static ?string $model = ControlePa::class;

    protected static ?string $modelLabel = 'Freight Invoice';

    protected static ?string $pluralModelLabel = 'Gerenciamento de Freight Invoice (PA)';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 65;

    /** O módulo do legado só exige login; o menu fica no Financeiro, então a credencial é financial como as telas vizinhas. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    /** Reserva ligada ao controle (cancelada fica de fora) com a instrução especial; a ordem é pelo ETD previsto, do mais novo ao mais antigo. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->join('embarque', 'embarque.embarque_id', '=', 'controle_pa.embarque_id')
            ->where('embarque.finalizado', '<>', 'c')
            ->select('controle_pa.*')
            ->selectSub(
                DB::table('instrucao_especial_embarque')
                    ->select('instrucao')
                    ->whereColumn('instrucao_especial_embarque.embarque_id', 'controle_pa.embarque_id')
                    ->limit(1),
                'special_instruction',
            )
            ->with(['embarque.cliente', 'embarque.armador'])
            ->orderByDesc('embarque.saida_prevista');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('embarque.booking')
                    ->label('Embarque')
                    ->searchable(),
                TextColumn::make('embarque.cliente.nome')
                    ->label('Cliente')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('embarque.saida_efetiva')
                    ->label('ETD')
                    ->date('d/m/Y'),
                TextColumn::make('embarque.chegada_efetiva')
                    ->label('ETA')
                    ->date('d/m/Y'),
                TextColumn::make('embarque.armador.nome')
                    ->label('Armador')
                    ->wrap(),
                TextColumn::make('special_instruction')
                    ->label('Instrução Especial')
                    ->width('18rem')
                    ->wrap(),
                TextColumn::make('flag_fi_dmf')
                    ->label('Fi DMF')
                    ->formatStateUsing(fn ($state, ControlePa $record) => $record->dmfStatus())
                    ->wrap(),
                TextColumn::make('data_envio_fi_agente')
                    ->label('Data Envio Agente')
                    ->date('d/m/Y')
                    ->wrap(),
                TextColumn::make('flag_fi_armador')
                    ->label('Fi Armador')
                    ->formatStateUsing(fn ($state, ControlePa $record) => $record->carrierStatus())
                    ->wrap(),
                TextColumn::make('data_pgto_armador')
                    ->label('Pagamento Armador')
                    ->date('d/m/Y'),
                TextColumn::make('data_recibo_armador')
                    ->label('Recibo Armador')
                    ->date('d/m/Y'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Gerenciar'),
            ]);
    }

    public static function fileLinks(ControlePa $record, int $fileType): array
    {
        return $record->filesOfType($fileType)
            ->map(fn (EmbEmbarqueFile $link) => $link->file)
            ->filter()
            ->map(fn ($file) => new HtmlString(sprintf(
                '<a href="%s" target="_blank" class="text-primary-600 underline">%s</a>',
                e(Storage::disk('tracking_uploads')->url('docs_emb/'.rawurlencode($file->name))),
                e($file->name),
            )))
            ->all();
    }

    public static function infolist(Schema $schema): Schema
    {
        $files = fn (int $type) => TextEntry::make("files_{$type}")
            ->label('Arquivos')
            ->state(fn (ControlePa $record) => self::fileLinks($record, $type))
            ->listWithLineBreaks()
            ->placeholder('Nenhum arquivo');

        return $schema
            ->components([
                Section::make('Reserva')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('embarque.booking')
                            ->label('Embarque'),
                        TextEntry::make('embarque.cliente.nome')
                            ->label('Cliente'),
                        TextEntry::make('embarque.armador.nome')
                            ->label('Armador'),
                    ]),
                Section::make('Freight Invoice DMF')
                    ->schema([
                        TextEntry::make('flag_fi_dmf')
                            ->label('Status')
                            ->formatStateUsing(fn ($state, ControlePa $record) => $record->dmfStatus())
                            ->badge(),
                        $files(EmbEmbarqueFile::DMF_INVOICE)
                            ->visible(fn (ControlePa $record) => (int) $record->flag_fi_dmf !== 0),
                        TextEntry::make('data_envio_fi_agente')
                            ->label('Data de envio ao agente')
                            ->date('d/m/Y')
                            ->placeholder('N/D')
                            ->visible(fn (ControlePa $record) => $record->canSetDate(ControlePa::DATE_SENT_TO_AGENT)),
                    ]),
                Section::make('Freight Invoice Armador')
                    ->visible(fn (ControlePa $record) => $record->canAttach(EmbEmbarqueFile::CARRIER_INVOICE))
                    ->schema([$files(EmbEmbarqueFile::CARRIER_INVOICE)]),
                Section::make('Pagamento Armador')
                    ->visible(fn (ControlePa $record) => $record->canSetDate(ControlePa::DATE_CARRIER_PAYMENT))
                    ->schema([
                        TextEntry::make('data_pgto_armador')
                            ->label('Data de pagamento')
                            ->date('d/m/Y')
                            ->placeholder('N/D'),
                        $files(EmbEmbarqueFile::PAYMENT_RECEIPT),
                    ]),
                Section::make('Recibo Armador')
                    ->visible(fn (ControlePa $record) => $record->canSetDate(ControlePa::DATE_CARRIER_RECEIPT))
                    ->schema([
                        TextEntry::make('data_recibo_armador')
                            ->label('Data do recibo')
                            ->date('d/m/Y')
                            ->placeholder('N/D'),
                        $files(EmbEmbarqueFile::CARRIER_RECEIPT),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListControlePas::route('/'),
            'view' => ViewControlePa::route('/{record}'),
        ];
    }
}

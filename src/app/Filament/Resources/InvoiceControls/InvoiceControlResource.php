<?php

namespace App\Filament\Resources\InvoiceControls;

use App\Actions\InvoiceControls\ChargeInvoiceControls;
use App\Actions\InvoiceControls\InvoiceGate;
use App\Enums\Cobranca\InvoiceControlStatus;
use App\Filament\Resources\InvoiceControls\Pages\ListInvoiceControls;
use App\Filament\Resources\InvoiceControls\Pages\ViewInvoiceControl;
use App\Models\ControleInvoiceMaster;
use App\Models\ControleInvoiceMasterLog;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/** controleinvoice (legado): invoices de MBL exigidos pelo armador, que seguram a cobrança enquanto pendentes. */
class InvoiceControlResource extends Resource
{
    public const UPLOAD_DIR = 'controleinvoice';

    protected static ?string $model = ControleInvoiceMaster::class;

    protected static ?string $modelLabel = 'Invoice';

    protected static ?string $pluralModelLabel = 'Controle de Invoices';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 40;

    /** security.yml do módulo (legado): credencial financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public static function operation(?string $imporExpor): string
    {
        return $imporExpor === 'i' ? 'Importação' : 'Exportação';
    }

    public static function fileUrl(ControleInvoiceMaster $record): ?string
    {
        return $record->file_name
            ? Storage::disk('tracking_uploads')->url(self::UPLOAD_DIR.'/'.rawurlencode($record->file_name))
            : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['armador', 'embarque.cliente']))
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('armador.nome')
                    ->label('Armador'),
                TextColumn::make('embarque.cliente.nome')
                    ->label('Cliente')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('embarque.booking')
                    ->label('Reserva')
                    ->searchable(),
                TextColumn::make('embarque.impor_expor')
                    ->label('Tipo')
                    ->formatStateUsing(fn (?string $state) => self::operation($state)),
                TextColumn::make('mbl_number')
                    ->label('MBL')
                    ->searchable(),
                TextColumn::make('embarque.saida_prevista')
                    ->label('Data de Embarque')
                    ->formatStateUsing(fn ($state) => InvoiceGate::date($state)),
                TextColumn::make('embarque.chegada_prevista')
                    ->label('Data Chegada')
                    ->formatStateUsing(fn ($state) => InvoiceGate::date($state)),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('armador_id')
                    ->label('Armador')
                    ->relationship('armador', 'nome')
                    ->searchable()
                    ->preload(),
            ])
            ->toolbarActions([
                // executeBatchLiberar: libera os pendentes sem arquivo e já gera a cobrança
                BulkAction::make('liberar')
                    ->label('Liberar e gerar cobrança')
                    ->icon('heroicon-o-lock-open')
                    ->visible(fn ($livewire) => $livewire->activeTab === 'pendentes')
                    ->requiresConfirmation()
                    ->modalDescription('Liberar os invoices selecionados e gerar a cobrança sem arquivo? Eles ficam aguardando o upload.')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $total = app(ChargeInvoiceControls::class)(
                            $records->where('status', InvoiceControlStatus::Pending),
                            'Liberação em lote sem arquivo. Aguardando upload do invoice.',
                            auth()->id(),
                        );

                        self::notifyBatch($total, "{$total} invoice(s) liberado(s) e enviados para cobrança.", 'Nenhum invoice pendente foi liberado.');
                    }),
                // executeBatchLiberarSemInvoice: dispensa o arquivo (OK sem anexo)
                BulkAction::make('liberarSemInvoice')
                    ->label('Liberar sem invoice')
                    ->icon('heroicon-o-check-badge')
                    ->color('gray')
                    ->visible(fn ($livewire) => $livewire->activeTab === 'liberados')
                    ->requiresConfirmation()
                    ->modalDescription('Liberar os invoices selecionados como OK sem arquivo?')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $total = $records->filter(fn (ControleInvoiceMaster $r) => $r->releaseWithoutInvoice(auth()->id()))->count();

                        self::notifyBatch($total, "{$total} invoice(s) liberado(s) sem arquivo.", 'Nenhum invoice foi liberado.');
                    }),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Invoice')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('embarque.booking')
                            ->label('Reserva'),
                        TextEntry::make('embarque.impor_expor')
                            ->label('Tipo')
                            ->formatStateUsing(fn (?string $state) => self::operation($state)),
                        TextEntry::make('armador.nome')
                            ->label('Armador'),
                        TextEntry::make('embarque.cliente.nome')
                            ->label('Cliente'),
                        TextEntry::make('mbl_number')
                            ->label('MBL')
                            ->copyable(),
                        TextEntry::make('embarque.saida_prevista')
                            ->label('ETD')
                            ->formatStateUsing(fn ($state) => InvoiceGate::date($state)),
                        TextEntry::make('embarque.chegada_prevista')
                            ->label('ETA')
                            ->formatStateUsing(fn ($state) => InvoiceGate::date($state)),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->helperText(fn (ControleInvoiceMaster $r) => $r->releasedWithoutFile() ? 'Liberado sem arquivo: marcado como OK sem anexo.' : null),
                    ]),
                Section::make('Arquivo')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('file_name')
                            ->label('Arquivo')
                            ->placeholder('Nenhum arquivo enviado')
                            ->url(fn (ControleInvoiceMaster $r) => self::fileUrl($r), shouldOpenInNewTab: true)
                            ->icon(fn (?string $state) => $state ? 'heroicon-o-paper-clip' : null)
                            ->color('primary'),
                        TextEntry::make('creator.nome')
                            ->label('Criado por')
                            ->placeholder('—'),
                        TextEntry::make('uploaded_at')
                            ->label('Enviado em')
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('—'),
                    ]),
                Section::make('Histórico')
                    ->schema([
                        RepeatableEntry::make('logs')
                            ->hiddenLabel()
                            ->placeholder('Nenhum registro')
                            ->table([
                                TableColumn::make('Ação'),
                                TableColumn::make('De'),
                                TableColumn::make('Para'),
                                TableColumn::make('Usuário'),
                                TableColumn::make('Data'),
                                TableColumn::make('Detalhe'),
                            ])
                            ->schema([
                                TextEntry::make('acao')
                                    ->formatStateUsing(fn (string $state) => ControleInvoiceMasterLog::ACTIONS[$state] ?? $state),
                                TextEntry::make('status_anterior')
                                    ->badge()
                                    ->placeholder('—'),
                                TextEntry::make('status_novo')
                                    ->badge()
                                    ->placeholder('—'),
                                TextEntry::make('user.nome')
                                    ->state(fn (ControleInvoiceMasterLog $r) => $r->user?->nome ?? ($r->created_by ? "#{$r->created_by}" : 'Sistema')),
                                TextEntry::make('created_at')
                                    ->dateTime('d/m/Y H:i:s'),
                                TextEntry::make('historico')
                                    ->placeholder('—')
                                    ->wrap(),
                            ]),
                    ]),
            ]);
    }

    private static function notifyBatch(int $total, string $success, string $none): void
    {
        $total
            ? Notification::make()
                ->title($success)
                ->success()
                ->send()
            : Notification::make()
                ->title($none)
                ->warning()
                ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoiceControls::route('/'),
            'view' => ViewInvoiceControl::route('/{record}'),
        ];
    }
}

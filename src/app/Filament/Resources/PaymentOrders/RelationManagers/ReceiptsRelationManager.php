<?php

namespace App\Filament\Resources\PaymentOrders\RelationManagers;

use App\Actions\PaymentOrders\DeletePaymentReceipt;
use App\Models\EmbEmbarqueFile;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Comprovantes de pagamento do armador anexados à reserva da ordem (showSuccess do legado). */
class ReceiptsRelationManager extends RelationManager
{
    protected static string $relationship = 'receipts';

    protected static ?string $title = 'Comprovante de Pagamento';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('emb_embarque_files.*')
                ->addSelect([
                    'file_name' => DB::table('cmn_files')->select('name')->whereColumn('cmn_files.id', 'emb_embarque_files.ref_file'),
                    'file_date' => DB::table('cmn_files')->select('created_at')->whereColumn('cmn_files.id', 'emb_embarque_files.ref_file'),
                ])
                ->orderByDesc('emb_embarque_files.id'))
            ->paginated(false)
            ->emptyStateHeading('Nenhum comprovante anexado.')
            ->columns([
                TextColumn::make('kind')
                    ->label('Arquivo')
                    ->state('comprovante de pagamento'),
                TextColumn::make('file_date')
                    ->label('Data')
                    ->date('d/m/Y'),
            ])
            ->recordActions([
                Action::make('abrir')
                    ->label('Abrir')
                    ->icon('heroicon-o-document-arrow-down')
                    ->visible(fn (EmbEmbarqueFile $record) => filled($record->file_name) && filled(config('services.tracking.url')))
                    ->url(fn (EmbEmbarqueFile $record) => Storage::disk('tracking_uploads')->url('docs_emb/'.rawurlencode($record->file_name)), shouldOpenInNewTab: true),
                DeleteAction::make()
                    ->using(function (EmbEmbarqueFile $record) {
                        app(DeletePaymentReceipt::class)($record);

                        return true;
                    }),
            ]);
    }
}

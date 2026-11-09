<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Actions\ControlePa\DeleteFreightInvoice;
use App\Actions\ControlePa\FreightInvoicePdf;
use App\Actions\ControlePa\GenerateFreightInvoice;
use App\Actions\ControlePa\SendFreightInvoice;
use App\Models\ControlePa;
use App\Models\Embarque;
use App\Models\EmbEmbarqueFile;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Aba Freight Invoice da reserva de exportação (enviaFreightInvoice / freightInvoice / enviaFI / removeDocs do booking, legado). */
class FreightInvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'freightInvoices';

    protected static ?string $title = 'Freight Invoice';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->impor_expor === 'e';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('file'))
            ->emptyStateHeading('Nenhuma Freight Invoice gerada')
            ->emptyStateDescription(fn () => $this->creationHint())
            ->paginated(false)
            ->columns([
                TextColumn::make('file.name')
                    ->label('Arquivo'),
                TextColumn::make('file.created_at')
                    ->label('Data')
                    ->date('d/m/Y'),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn () => $this->control()?->dmfStatus())
                    ->badge(),
            ])
            ->headerActions([
                // freightInvoice: só com Freight Invoice "Sim" e sem controle PA aberto
                Action::make('criar')
                    ->label('Criar Freight Invoice')
                    ->icon('heroicon-o-document-plus')
                    ->visible(fn () => $this->canGenerate())
                    ->requiresConfirmation()
                    ->modalDescription('Deseja criar esta Freight Invoice?')
                    ->action(fn () => $this->run(fn () => app(GenerateFreightInvoice::class)($this->booking(), auth()->id()), 'Freight Invoice criada.')),
            ])
            ->recordActions([
                Action::make('baixar')
                    ->label('Baixar')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn (EmbEmbarqueFile $record) => Storage::disk('tracking_uploads')->url('docs_emb/'.rawurlencode((string) $record->file?->name)), shouldOpenInNewTab: true),
                // enviaFI: o envelope só aparece enquanto "Não enviado"
                Action::make('enviar')
                    ->label('Enviar para Financeiro e EXPO')
                    ->icon('heroicon-o-envelope')
                    ->visible(fn () => $this->control() && (int) $this->control()->flag_fi_dmf === 0)
                    ->requiresConfirmation()
                    ->modalDescription('Deseja enviar cópia desta Freight Invoice para o Financeiro e Exportação?')
                    ->action(fn (EmbEmbarqueFile $record) => $this->run(fn () => app(SendFreightInvoice::class)($record, (string) auth()->user()->nome, auth()->user()->email), 'Freight Invoice enviada.')),
                Action::make('excluir')
                    ->label('Excluir')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Esta ação não pode ser desfeita! O controle PA da reserva (datas e status) também é apagado, para poder gerar outra Freight Invoice.')
                    ->action(fn (EmbEmbarqueFile $record) => $this->run(fn () => app(DeleteFreightInvoice::class)($record), 'Freight Invoice removida.')),
            ]);
    }

    private function booking(): Embarque
    {
        return $this->getOwnerRecord();
    }

    private function control(): ?ControlePa
    {
        return $this->booking()->controlePa()->first();
    }

    private function canGenerate(): bool
    {
        return (int) $this->booking()->flag_fi === 1
            && array_key_exists((string) $this->booking()->recebedor_freight_invoice, FreightInvoicePdf::RECEIVERS)
            && ! $this->control();
    }

    private function creationHint(): ?string
    {
        if ((int) $this->booking()->flag_fi !== 1) {
            return 'A reserva está com Freight Invoice "Não".';
        }

        return blank($this->booking()->recebedor_freight_invoice)
            ? 'É necessário selecionar o recebedor do pagamento para gerar o documento!'
            : null;
    }

    private function run(callable $change, string $success): void
    {
        try {
            $change();
        } catch (DomainException $e) {
            Notification::make()
                ->title($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title($success)
            ->success()
            ->send();
    }
}

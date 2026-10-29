<?php

namespace App\Filament\Resources\Embarques\Pages;

use App\Actions\Booking\Documents\ExpenseStatement;
use App\Actions\Booking\Documents\ExpenseStatementPdf;
use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Models\Embarque;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewEmbarque extends ViewRecord
{
    protected static string $resource = EmbarqueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                $this->statementAction('valoresCompra', 'Valores de compra', ExpenseStatement::PURCHASE),
                $this->statementAction('valoresDespesa', 'Valores de despesa', ExpenseStatement::SALE),
                $this->statementAction('valoresDocumentacao', 'Valores de despesa (documentação)', ExpenseStatement::DOCUMENTATION)
                    ->visible(fn (Embarque $record) => $record->tipo_embarque === 'm'),
            ])
                ->label('Documentos')
                ->icon(Heroicon::OutlinedDocumentText)
                ->button(),
        ];
    }

    /** valoresCompra, valoresDespesa e valoresDespesasDocumentacao (legado): PDF da relação de despesas. */
    private function statementAction(string $name, string $label, string $kind): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(function (Embarque $record) use ($kind) {
                try {
                    return app(ExpenseStatementPdf::class)($record, $kind);
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }
}

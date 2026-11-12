<?php

namespace App\Filament\Resources\Embarques\Pages;

use App\Actions\Booking\Documents\BillingInstructionPdf;
use App\Actions\Booking\Documents\ExpenseStatement;
use App\Actions\Booking\Documents\ExpenseStatementPdf;
use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Models\Embarque;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;

class ViewEmbarque extends ViewRecord
{
    protected static string $resource = EmbarqueResource::class;

    /** Resumo e relações (despesas, comissões...) viram abas no topo, em vez de empilhadas. */
    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Resumo';
    }

    /** Grupos que viram submenu (abas internas) em vez de ter os relation managers empilhados na mesma aba. */
    private const SUBMENU_GROUPS = ['Notas', 'Confirmação'];

    public function getRelationManagerTabComponent(string|int $key): ?Tab
    {
        $tab = parent::getRelationManagerTabComponent($key);
        $group = $this->getCachedRelationManagers()[$key] ?? null;

        if (! $tab || ! $group instanceof RelationGroup || ! in_array($group->getLabel(), self::SUBMENU_GROUPS, true)) {
            return $tab;
        }

        return $tab->schema(fn (): array => [
            Tabs::make()
                ->contained(false)
                ->tabs(collect($group->getManagers())
                    ->map(fn (string $manager): Tab => $manager::getTabComponent($this->getRecord(), static::class)
                        ->schema([
                            Livewire::make($manager, [...$this->getRelationManagerLivewireData(), ...$manager::getDefaultProperties()])
                                ->key($manager),
                        ]))
                    ->all()),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('instrucaoCobranca')
                    ->label('Instrução de cobrança')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn (Embarque $record) => app(BillingInstructionPdf::class)($record)),
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

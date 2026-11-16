<?php

namespace App\Filament\Resources\Embarques\Pages;

use App\Actions\Booking\ChangeStatus;
use App\Actions\Booking\Documents\BillingInstructionPdf;
use App\Actions\Booking\Documents\ExpenseStatement;
use App\Actions\Booking\Documents\ExpenseStatementPdf;
use App\Actions\Booking\ReleaseShipment;
use App\Actions\Booking\UpdateEtd;
use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Models\Embarque;
use Carbon\Carbon;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
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
            ActionGroup::make([
                $this->statusAction('reativar', 'Reativar reserva', ChangeStatus::OPEN, Heroicon::OutlinedPlayCircle)
                    ->visible(fn (Embarque $record) => $record->finalizado === ChangeStatus::SUSPENDED),
                $this->statusAction('suspender', 'Suspender reserva', ChangeStatus::SUSPENDED, Heroicon::OutlinedPauseCircle)
                    ->visible(fn (Embarque $record) => $record->finalizado !== ChangeStatus::CANCELLED && $record->impor_expor === 'e' && $record->tipo_embarque === 'm'),
                $this->statusAction('cancelar', 'Cancelar reserva', ChangeStatus::CANCELLED, Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (Embarque $record) => $record->finalizado !== ChangeStatus::CANCELLED),
                $this->etdAction(),
                $this->releaseAction(),
            ])
                ->label('Reserva')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->button(),
        ];
    }

    /** cancelarEmbarque, suspenderEmbarque e removerPendencia (legado): exigem justificativa, que vai para o rastreamento. */
    private function statusAction(string $name, string $label, string $status, Heroicon $icon): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->modalHeading($label)
            ->schema([
                Textarea::make('justificativa')
                    ->label('Justificativa')
                    ->helperText('Fica registrada na rastreabilidade da reserva.')
                    ->required()
                    ->rows(4),
            ])
            ->action(function (Embarque $record, array $data) use ($status, $label) {
                try {
                    app(ChangeStatus::class)($record, $status, $data['justificativa'], auth()->id());
                } catch (DomainException $e) {
                    return Notification::make()->title($e->getMessage())->danger()->send();
                }

                Notification::make()->title("{$label}: feito")->success()->send();
            });
    }

    /** atualizarEtd (legado): o ETA acompanha, mantendo o transit time. Some depois da confirmação de embarque. */
    private function etdAction(): Action
    {
        return Action::make('atualizarEtd')
            ->label('Atualizar ETD')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->visible(fn (Embarque $record) => $record->finalizado !== ChangeStatus::CANCELLED && ! $record->departureConfirmations()->exists())
            ->fillForm(fn (Embarque $record): array => ['etd' => $record->saida_prevista])
            ->schema([
                DatePicker::make('etd')
                    ->label('Saída prevista (ETD)')
                    ->required(),
            ])
            ->action(function (Embarque $record, array $data) {
                try {
                    app(UpdateEtd::class)($record, Carbon::parse($data['etd']), auth()->id());
                } catch (DomainException $e) {
                    return Notification::make()->title($e->getMessage())->danger()->send();
                }

                Notification::make()->title('ETD atualizado')->success()->send();
            });
    }

    /** liberaEmbarque (legado): só a credencial lib_emb dá baixa. */
    private function releaseAction(): Action
    {
        return Action::make('liberarEmbarque')
            ->label('Liberar embarque')
            ->icon(Heroicon::OutlinedLockOpen)
            ->requiresConfirmation()
            ->visible(fn (Embarque $record) => auth()->user()->hasLegacyCredential('lib_emb') && $record->hasPendingRelease())
            ->action(function (Embarque $record) {
                app(ReleaseShipment::class)($record->getKey(), auth()->id());

                Notification::make()->title('Embarque liberado')->success()->send();
            });
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

<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Actions\Booking\Bls\ImportBookingData;
use App\Actions\Booking\Bls\ImportOnlineDrafts;
use App\Actions\Booking\Bls\OnlineDraftDetails;
use App\Actions\Booking\Bls\PendingDrafts;
use App\Actions\Booking\Bls\ProtestLetterPdf;
use App\Actions\Booking\Bls\SaveBl;
use App\Actions\Booking\Bls\SaveContainerDamages;
use App\Actions\Booking\Bls\ToggleOnlineBl;
use App\Filament\Resources\Bls\BlResource;
use App\Filament\Support\BlPrintActions;
use App\Models\Bl;
use App\Models\Embarque;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/** Aba Aprovação BL da reserva (listAprovacaoBl / editAprovacaoBl / updateAprovacaoBl / deleteAprovacaoBl do booking, legado): os House BLs. */
class BlsRelationManager extends RelationManager
{
    protected static string $relationship = 'bls';

    protected static ?string $title = 'Aprovação BL';

    /** A reserva suspensa (p) não tem a aba no legado. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->finalizado !== 'p';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('bl')
            ->paginated(false)
            ->emptyStateHeading('Nenhum BL cadastrado')
            ->columns([
                TextColumn::make('bl')
                    ->label('BL')
                    ->weight('bold'),
                TextColumn::make('mbl_number')
                    ->label('MBL'),
                TextColumn::make('invoice')
                    ->label('Invoice'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('status_bl_online')
                    ->label('BL online')
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'a' ? 'success' : 'gray')
                    ->formatStateUsing(fn (?string $state): ?string => match ($state) {
                        'a' => 'ABERTO',
                        'f' => 'FECHADO',
                        default => null,
                    }),
                TextColumn::make('impressoes')
                    ->label('Originais impressos')
                    ->state(fn (Bl $record): string => "{$record->cntOriginal}/{$record->numImpressao}"),
            ])
            ->headerActions([
                Action::make('novo')
                    ->label('Adicionar novo BL')
                    ->icon(Heroicon::OutlinedPlus)
                    ->url(fn (): string => BlResource::getUrl('create', ['embarque' => $this->booking()]))
                    ->openUrlInNewTab(),
                $this->onlineDraftsAction(),
                $this->damagesAction(),
            ])
            ->recordActions([
                BlPrintActions::group(fn (): Embarque => $this->booking()),
                Action::make('editar')
                    ->label('Editar')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (Bl $record): string => BlResource::getUrl('edit', ['embarque' => $this->booking(), 'record' => $record]))
                    ->openUrlInNewTab(),
                Action::make('alternarBlOnline')
                    ->label(fn (Bl $record): string => $record->status_bl_online === 'f' ? 'Abrir BL online' : 'Fechar BL online')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->visible(fn (Bl $record): bool => filled($record->status_bl_online))
                    ->requiresConfirmation()
                    ->modalDescription(fn (Bl $record): ?string => $record->status_bl_online === 'f' ? null : 'Os dados e os containers do BL voltam a ser os do draft online.')
                    ->action(fn (Bl $record) => app(ToggleOnlineBl::class)($record)),
                Action::make('importarDadosReserva')
                    ->label('Importar dados da reserva')
                    ->icon(Heroicon::OutlinedArrowDownOnSquare)
                    ->color('gray')
                    ->visible(fn (): bool => $this->booking()->finalizado === 'c')
                    ->requiresConfirmation()
                    ->modalHeading(fn (): string => "Importar dados da Reserva {$this->booking()->booking}?")
                    ->modalDescription('Os dados atuais do BL serão apagados!')
                    ->action(function (Bl $record): void {
                        app(ImportBookingData::class)($this->booking(), $record, auth()->id());

                        Notification::make()
                            ->title('Dados da reserva importados para o BL')
                            ->success()
                            ->send();
                    }),
                DeleteAction::make()
                    ->modalHeading('Excluir BL?')
                    ->using(fn (Bl $record) => app(SaveBl::class)->delete($this->booking(), $record, auth()->id())),
            ]);
    }

    /** "Importar Draft Online": os drafts que o cliente mandou pelo link e que ainda não viraram BL. */
    private function onlineDraftsAction(): Action
    {
        return Action::make('draftOnline')
            ->label('Importar Draft Online')
            ->icon(Heroicon::OutlinedCloudArrowDown)
            ->color('gray')
            ->modalHeading('Importar Drafts Online')
            ->modalWidth('4xl')
            ->modalSubmitActionLabel('Importar Selecionados')
            ->modalSubmitAction(fn (Action $action) => $this->pendingDrafts()['drafts'] === [] ? false : $action)
            ->schema(function (): array {
                $pending = $this->pendingDrafts();

                if ($pending['drafts'] === []) {
                    return [View::make('filament.bls.draft-token')->viewData(['token' => $pending['token']])];
                }

                $details = app(OnlineDraftDetails::class);
                $drafts = collect($pending['drafts']);

                return [
                    CheckboxList::make('ids')
                        ->label('Drafts pendentes')
                        ->required()
                        ->options($drafts->mapWithKeys(fn (array $draft) => [$draft['bl_online_id'] => "draft_{$draft['group']}_v{$draft['version']} — {$draft['created_at']}"])->all())
                        ->descriptions($drafts->mapWithKeys(fn (array $draft) => [$draft['bl_online_id'] => new HtmlString(view('filament.bls.online-draft', $details($draft['bl_online_id']))->render())])->all()),
                ];
            })
            ->action(function (array $data): void {
                try {
                    $result = app(ImportOnlineDrafts::class)($this->booking(), $data['ids'] ?? []);
                } catch (DomainException $e) {
                    Notification::make()
                        ->title($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("{$result['imported']} draft(s) importado(s)")
                    ->body($result['skipped'] || $result['errors'] ? "{$result['skipped']} ignorado(s), {$result['errors']} com erro." : null)
                    ->color($result['errors'] ? 'warning' : 'success')
                    ->send();
            });
    }

    /** @return array{drafts: list<array<string, mixed>>, token: ?array<string, mixed>} */
    private function pendingDrafts(): array
    {
        return app(PendingDrafts::class)($this->booking());
    }

    /** "Avarias e Carta Protesto": a observação de avaria de cada container e o PDF da notificação. */
    private function damagesAction(): Action
    {
        return Action::make('avarias')
            ->label('Avarias e Carta Protesto')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('gray')
            ->modalHeading('Avarias e Carta Protesto')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Salvar')
            ->extraModalFooterActions(fn (Action $action): array => [$action->makeModalSubmitAction('gerarPdf', arguments: ['pdf' => true])->label('Gerar PDF')])
            ->fillForm(fn (): array => ['damages' => $this->damageRows()->pluck('observacao_avaria', 'container_id')->all()])
            ->schema(fn (): array => $this->damageRows()->isEmpty()
                ? [Text::make('Nenhum container lançado nos BLs desta reserva.')]
                : $this->damageRows()->map(fn (object $row) => Textarea::make("damages.{$row->container_id}")
                    ->label("BL {$row->bl} — Container {$row->numero}")
                    ->placeholder('N/D')
                    ->rows(2))->all())
            ->action(function (array $data, array $arguments) {
                app(SaveContainerDamages::class)($this->booking(), $data['damages'] ?? []);

                return ($arguments['pdf'] ?? false) ? app(ProtestLetterPdf::class)($this->booking()) : null;
            });
    }

    /** @return Collection<int, object> */
    private function damageRows(): Collection
    {
        return DB::table('container as c')
            ->join('bl as b', 'b.bl_id', '=', 'c.bl_id')
            ->where('b.embarque_id', $this->booking()->getKey())
            ->orderBy('b.bl')
            ->orderBy('c.container_id')
            ->get(['c.container_id', 'c.numero', 'c.observacao_avaria', 'b.bl']);
    }

    private function booking(): Embarque
    {
        return $this->getOwnerRecord();
    }
}

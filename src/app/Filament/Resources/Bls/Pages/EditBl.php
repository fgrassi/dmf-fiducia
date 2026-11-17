<?php

namespace App\Filament\Resources\Bls\Pages;

use App\Actions\Booking\Bls\BlDefaults;
use App\Actions\Booking\Bls\ImportBlDraft;
use App\Actions\Booking\Bls\SaveBl;
use App\Filament\Resources\Bls\BlResource;
use App\Filament\Resources\Bls\Pages\Concerns\ManagesBl;
use App\Filament\Support\BlPrintActions;
use App\Models\Bl;
use App\Models\Container;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Editando BL (editAprovacaoBl, legado): os campos vazios voltam a trazer o da reserva e o navio acompanha a reserva. */
class EditBl extends EditRecord
{
    use ManagesBl;

    protected static string $resource = BlResource::class;

    public function getTitle(): string
    {
        return "Editando BL ({$this->getRecord()->bl})";
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $defaults = app(BlDefaults::class)($this->booking());

        return [
            ...$defaults,
            ...array_filter($data, fn ($value) => filled($value)),
            'vessel' => $defaults['vessel'],
            'containers' => Container::where('bl_id', $this->getRecord()->getKey())->orderBy('container_id')->get()->toArray(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->saveBl($record, $data);
    }

    public function saveAndFinalize(): void
    {
        $this->finalize = true;
        $this->save();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->importDraftAction(),
            BlPrintActions::group(fn () => $this->booking(), fn (): Bl => $this->getRecord()),
            DeleteAction::make()
                ->modalHeading('Excluir BL?')
                ->using(fn (Bl $record) => app(SaveBl::class)->delete($this->booking(), $record, auth()->id())),
        ];
    }

    /** "Importar Draft": preenche o BL com a planilha de draft; o que o BL tinha é substituído. */
    private function importDraftAction(): Action
    {
        return Action::make('importarDraft')
            ->label('Importar Draft')
            ->icon(Heroicon::OutlinedDocumentArrowUp)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Importar Arquivo de Draft')
            ->modalDescription('Tem certeza que deseja importar o arquivo? Toda informação existente será substituída pelo conteúdo do arquivo.')
            ->schema([
                FileUpload::make('arquivo')
                    ->label('Arquivo')
                    ->required()
                    ->disk('local')
                    ->directory('drafts-tmp')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel']),
            ])
            ->action(function (array $data): void {
                $path = Storage::disk('local')->path($data['arquivo']);

                try {
                    app(ImportBlDraft::class)($this->getRecord(), $path);
                } catch (DomainException $e) {
                    Notification::make()
                        ->title($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                } finally {
                    Storage::disk('local')->delete($data['arquivo']);
                }

                $this->getRecord()->refresh();
                $this->fillForm();

                Notification::make()
                    ->title('Draft importado')
                    ->success()
                    ->send();
            });
    }

    /** Salvar (BL aberto) ou Atualizar; só o BL aberto oferece finalizar. */
    protected function getFormActions(): array
    {
        $open = $this->getRecord()->status === Bl::OPEN;

        return [
            $this->getSaveFormAction()
                ->label($open ? 'Salvar' : 'Atualizar'),
            $this->finalizeAction('saveAndFinalize')
                ->visible($open),
            $this->cancelAction(),
        ];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'BL salvo';
    }
}

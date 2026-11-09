<?php

namespace App\Filament\Resources\ControlePas\Pages;

use App\Actions\ControlePa\AttachControlePaFile;
use App\Actions\ControlePa\SetControlePaDate;
use App\Filament\Resources\ControlePas\ControlePaResource;
use App\Models\ControlePa;
use App\Models\EmbEmbarqueFile;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewControlePa extends ViewRecord
{
    protected static string $resource = ControlePaResource::class;

    public function getSubheading(): ?string
    {
        return $this->getRecord()->embarque?->booking;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->dateAction('dataEnvioAgente', 'Data de envio ao agente', ControlePa::DATE_SENT_TO_AGENT),
            $this->uploadAction('anexarFiArmador', 'Anexar FI do armador', EmbEmbarqueFile::CARRIER_INVOICE),
            $this->dateAction('dataPagamento', 'Data de pagamento', ControlePa::DATE_CARRIER_PAYMENT),
            $this->uploadAction('anexarComprovante', 'Anexar comprovante de pagamento', EmbEmbarqueFile::PAYMENT_RECEIPT),
            $this->dateAction('dataRecibo', 'Data do recibo', ControlePa::DATE_CARRIER_RECEIPT),
            $this->uploadAction('anexarRecibo', 'Anexar recibo do armador', EmbEmbarqueFile::CARRIER_RECEIPT),
        ];
    }

    private function dateAction(string $name, string $label, string $field): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-calendar-days')
            ->color('gray')
            ->visible(fn () => $this->getRecord()->canSetDate($field))
            ->fillForm(fn () => ['data' => $this->getRecord()->{$field}?->toDateString()])
            ->schema([
                DatePicker::make('data')
                    ->label($label)
                    ->required(),
            ])
            ->action(fn (array $data) => $this->run(fn () => app(SetControlePaDate::class)($this->getRecord(), $field, $data['data']), 'Data salva.'));
    }

    /** O legado só mostra o envio enquanto a etapa não tem arquivo. */
    private function uploadAction(string $name, string $label, int $fileType): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-arrow-up-tray')
            ->visible(fn () => $this->getRecord()->canAttach($fileType) && $this->getRecord()->filesOfType($fileType)->isEmpty())
            ->schema([
                FileUpload::make('arquivo')
                    ->hiddenLabel()
                    ->required()
                    ->storeFiles(false)
                    ->acceptedFileTypes([
                        'application/pdf',
                        'image/jpeg',
                        'image/png',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ]),
            ])
            ->action(fn (array $data) => $this->run(fn () => app(AttachControlePaFile::class)($this->getRecord(), $fileType, $data['arquivo'], auth()->id()), 'Arquivo anexado.'));
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

        $this->refreshFormData(['data_envio_fi_agente', 'data_pgto_armador', 'data_recibo_armador', 'flag_fi_armador']);

        Notification::make()
            ->title($success)
            ->success()
            ->send();
    }
}

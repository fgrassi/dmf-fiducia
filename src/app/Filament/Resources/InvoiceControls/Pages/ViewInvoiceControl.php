<?php

namespace App\Filament\Resources\InvoiceControls\Pages;

use App\Actions\InvoiceControls\ChargeInvoiceControls;
use App\Enums\Cobranca\InvoiceControlStatus;
use App\Filament\Resources\InvoiceControls\InvoiceControlResource;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ViewInvoiceControl extends ViewRecord
{
    protected static string $resource = InvoiceControlResource::class;

    /** extensoesPermitidas do legado (pdf, jpg, jpeg, png, doc, docx, xls, xlsx). */
    private const MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function getSubheading(): ?string
    {
        return "{$this->getRecord()->embarque?->booking} » {$this->getRecord()->mbl_number}";
    }

    protected function getHeaderActions(): array
    {
        return [
            // addArquivo: o arquivo deixa o invoice OK; à tarde pergunta se a cobrança já vai ao cliente
            Action::make('enviarArquivo')
                ->label('Enviar arquivo')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalHeading('Enviar arquivo do invoice')
                ->modalSubmitActionLabel('Enviar')
                ->schema([
                    FileUpload::make('arquivo')
                        ->hiddenLabel()
                        ->required()
                        ->disk('tracking_uploads')
                        ->directory(InvoiceControlResource::UPLOAD_DIR)
                        ->acceptedFileTypes(self::MIME_TYPES)
                        // o nome guardado mantém a extensão do cliente (vai para web/uploads do tracking): valida ela também
                        ->rules(['extensions:pdf,jpg,jpeg,png,doc,docx,xls,xlsx'])
                        ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file) => now()->format('dmyGi').'_'
                            .preg_replace('/[^A-Za-z0-9-._\-]/', '', str_replace(' ', '_', $file->getClientOriginalName()))),
                ])
                ->action(function (array $data) {
                    $this->getRecord()->markReceived(basename($data['arquivo']), auth()->id());

                    Notification::make()
                        ->title('Arquivo do invoice enviado.')
                        ->success()
                        ->send();

                    if (now()->hour >= 12) {
                        $this->replaceMountedAction('gerarCobranca', ['aposUpload' => true]);
                    }
                }),
            Action::make('liberarSemInvoice')
                ->label('Liberar sem invoice')
                ->icon('heroicon-o-check-badge')
                ->color('gray')
                ->visible(fn () => $this->getRecord()->status !== InvoiceControlStatus::Ok)
                ->requiresConfirmation()
                ->modalDescription('Liberar este invoice como OK sem anexar arquivo?')
                ->action(function () {
                    $this->getRecord()->releaseWithoutInvoice(auth()->id());

                    Notification::make()
                        ->title('Invoice liberado sem arquivo.')
                        ->success()
                        ->send();
                }),
            // forcarCobranca: sem arquivo, o pendente fica liberado aguardando upload
            Action::make('gerarCobranca')
                ->label('Gerar cobrança agora')
                ->icon('heroicon-o-banknotes')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription(fn (array $arguments) => match (true) {
                    $arguments['aposUpload'] ?? false => 'Enviar cobrança para o cliente imediatamente?',
                    filled($this->getRecord()->file_name) => 'Gerar cobrança manual para este invoice agora?',
                    default => 'Gerar cobrança agora sem o arquivo? O invoice ficará liberado e aguardando upload.',
                })
                ->action(function () {
                    $sent = app(ChargeInvoiceControls::class)(
                        [$this->getRecord()],
                        'Cobrança gerada sem arquivo. Aguardando upload do invoice.',
                        auth()->id(),
                    );

                    $sent
                        ? Notification::make()
                            ->title('Cobrança enviada para geração.')
                            ->body('Bloqueios (despesas, cotações, liberação) são enviados por e-mail ao financeiro.')
                            ->success()
                            ->send()
                        : Notification::make()
                            ->title('Cobrança desta reserva já disparada há instantes.')
                            ->warning()
                            ->send();
                }),
        ];
    }
}

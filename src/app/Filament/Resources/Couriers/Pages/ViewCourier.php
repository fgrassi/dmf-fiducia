<?php

namespace App\Filament\Resources\Couriers\Pages;

use App\Actions\Courier\UploadCourierFile;
use App\Filament\Actions\CourierAgentExpenseAction;
use App\Filament\Actions\CourierPdfAction;
use App\Filament\Resources\Couriers\CourierResource;
use App\Models\ControleCourier;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCourier extends ViewRecord
{
    protected static string $resource = CourierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CourierPdfAction::make(),
            CourierAgentExpenseAction::make()
                ->after(fn () => $this->redirect(CourierResource::getUrl('view', ['record' => $this->getRecord()]))),
            Action::make('enviarArquivo')
                ->label('Enviar arquivo')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalWidth('md')
                ->schema([
                    FileUpload::make('file')
                        ->label('Arquivo')
                        ->required()
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                        ->rules(['mimes:pdf,jpg,jpeg,png'])
                        ->storeFiles(false),
                ])
                ->action(function (array $data, ControleCourier $record, UploadCourierFile $upload, Action $action) {
                    try {
                        $upload($record, $data['file']);
                    } catch (DomainException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();
                    }

                    Notification::make()->title('Arquivo adicionado com Sucesso')->success()->send();

                    return redirect(CourierResource::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}

<?php

namespace App\Filament\Resources\Boxes\Pages;

use App\Actions\BoxControl\BoxLabelPdf;
use App\Actions\BoxControl\CloseBox;
use App\Filament\Resources\Boxes\BoxResource;
use App\Models\ControleCaixa;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewBox extends ViewRecord
{
    protected static string $resource = BoxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('etiqueta')
                ->label('Gera Etiqueta')
                ->icon(Heroicon::OutlinedPrinter)
                ->action(fn (ControleCaixa $record, BoxLabelPdf $label) => $label($record)),
            Action::make('darBaixa')
                ->label('Dar baixa')
                ->icon(Heroicon::OutlinedArrowDownCircle)
                ->visible(fn (ControleCaixa $record) => $record->status === ControleCaixa::OPEN)
                ->requiresConfirmation()
                ->modalHeading('Fechar Caixa')
                ->modalDescription('Tem certeza que deseja fechar esta caixa?')
                ->action(function (ControleCaixa $record, CloseBox $close) {
                    $close($record);
                    Notification::make()->title('Caixa fechada.')->success()->send();

                    return redirect(BoxResource::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}

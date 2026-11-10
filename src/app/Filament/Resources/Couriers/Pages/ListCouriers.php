<?php

namespace App\Filament\Resources\Couriers\Pages;

use App\Actions\Courier\CreateCourier;
use App\Filament\Resources\Couriers\CourierResource;
use App\Filament\Resources\Couriers\Schemas\CourierForm;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListCouriers extends ListRecords
{
    protected static string $resource = CourierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('incluir')
                ->label('Incluir Courrier')
                ->icon(Heroicon::OutlinedPlus)
                ->modalHeading('Incluir Courrier')
                ->modalWidth('md')
                ->schema(CourierForm::create())
                ->action(function (array $data, CreateCourier $create) {
                    $courier = $create($data);
                    Notification::make()->title('Registro Adicionado com Sucesso')->success()->send();

                    return redirect(CourierResource::getUrl('view', ['record' => $courier]));
                }),
        ];
    }
}

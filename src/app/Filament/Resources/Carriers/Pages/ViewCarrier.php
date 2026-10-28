<?php

namespace App\Filament\Resources\Carriers\Pages;

use App\Actions\Carriers\PortalReferenceMap;
use App\Actions\Carriers\SaveCarrier;
use App\Filament\Resources\Carriers\CarrierResource;
use App\Models\Armador;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCarrier extends ViewRecord
{
    protected static string $resource = CarrierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->mutateRecordDataUsing(fn (array $data) => [
                    ...$data,
                    'portal_refs' => [
                        'impo' => PortalReferenceMap::decode($data['integracao_portal_refs_impo']),
                        'expo' => PortalReferenceMap::decode($data['integracao_portal_refs_expo']),
                    ],
                ])
                ->using(fn (Armador $record, array $data) => app(SaveCarrier::class)($record, $data)),
        ];
    }
}

<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Enums\ContainerTime;

class DemurrageRelationManager extends ContainerTimeRelationManager
{
    protected static string $relationship = 'demurrages';

    protected static string $operation = 'i';

    protected static ?string $title = 'Demurrage';

    protected function type(): ContainerTime
    {
        return ContainerTime::Demurrage;
    }
}

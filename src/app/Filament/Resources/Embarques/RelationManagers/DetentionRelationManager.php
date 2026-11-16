<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Enums\ContainerTime;

class DetentionRelationManager extends ContainerTimeRelationManager
{
    protected static string $relationship = 'detentions';

    protected static string $operation = 'e';

    protected static ?string $title = 'Detention';

    protected function type(): ContainerTime
    {
        return ContainerTime::Detention;
    }
}

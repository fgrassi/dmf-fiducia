<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Enums\AmsDestination;

class AmsUaeRelationManager extends AmsRelationManager
{
    protected static string $relationship = 'amsUae';

    protected static ?string $title = 'Emirados Árabes';

    protected static function destinationOf(): AmsDestination
    {
        return AmsDestination::Uae;
    }
}

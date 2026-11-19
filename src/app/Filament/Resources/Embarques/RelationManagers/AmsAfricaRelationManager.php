<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use App\Enums\AmsDestination;

class AmsAfricaRelationManager extends AmsRelationManager
{
    protected static string $relationship = 'amsAfrica';

    protected static ?string $title = 'África do Sul';

    protected static function destinationOf(): AmsDestination
    {
        return AmsDestination::Africa;
    }
}

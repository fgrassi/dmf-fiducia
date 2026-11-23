<?php

namespace App\Filament\Support;

use Filament\Resources\Pages\ManageRecords;

abstract class ManageFollowUps extends ManageRecords
{
    protected function getHeaderActions(): array
    {
        return [
            static::getResource()::createAction(),
        ];
    }
}

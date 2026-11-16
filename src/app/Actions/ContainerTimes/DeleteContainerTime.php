<?php

namespace App\Actions\ContainerTimes;

use Illuminate\Database\Eloquent\Model;

/** executeRemoveDemurrage / executeRemoveDetention (legado). */
class DeleteContainerTime
{
    public function __invoke(Model $record): void
    {
        $record->delete();
    }
}

<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

/** Subaba "Chegada" do grupo Confirmação. */
class ArrivalConfirmationsRelationManager extends DepartureConfirmationsRelationManager
{
    protected static string $relationship = 'arrivalConfirmations';

    protected static ?string $title = 'Confirmação de chegada';

    protected static ?string $emptyLabel = 'Chegada ainda não confirmada';
}

<?php

namespace App\Filament\Resources\ImportFollowUps;

use App\Actions\Followup\FollowUpsQuery;
use App\Filament\Resources\ImportFollowUps\Pages\ManageImportFollowUps;
use App\Filament\Support\FollowUpResource;

/** followup (legado): Follow Up IMPO. */
class ImportFollowUpResource extends FollowUpResource
{
    protected static ?string $navigationLabel = 'Follow Up IMPO';

    protected static ?string $slug = 'follow-up-impo';

    protected static ?int $navigationSort = 1;

    protected static string $area = FollowUpsQuery::IMPORT;

    protected static array $types = ['i', 'f'];

    public static function getPages(): array
    {
        return ['index' => ManageImportFollowUps::route('/')];
    }
}

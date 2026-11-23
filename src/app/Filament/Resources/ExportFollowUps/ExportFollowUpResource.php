<?php

namespace App\Filament\Resources\ExportFollowUps;

use App\Actions\Followup\FollowUpsQuery;
use App\Filament\Resources\ExportFollowUps\Pages\ManageExportFollowUps;
use App\Filament\Support\FollowUpResource;

/** followupExpo (legado): Follow Up EXPO. */
class ExportFollowUpResource extends FollowUpResource
{
    protected static ?string $navigationLabel = 'Follow Up EXPO';

    protected static ?string $slug = 'follow-up-expo';

    protected static ?int $navigationSort = 2;

    protected static string $area = FollowUpsQuery::EXPORT;

    protected static array $types = ['e'];

    public static function getPages(): array
    {
        return ['index' => ManageExportFollowUps::route('/')];
    }
}

<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Actions\Companies\SaveCompany;
use App\Filament\Resources\Companies\CompanyResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(SaveCompany::class)($data, auth()->id());
    }
}

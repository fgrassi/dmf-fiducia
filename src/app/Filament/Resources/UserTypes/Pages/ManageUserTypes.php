<?php

namespace App\Filament\Resources\UserTypes\Pages;

use App\Actions\Users\SaveUserType;
use App\Filament\Resources\UserTypes\UserTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageUserTypes extends ManageRecords
{
    protected static string $resource = UserTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Novo Tipo de Usuário')
                ->using(fn (array $data) => app(SaveUserType::class)($data)),
        ];
    }
}

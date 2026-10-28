<?php

namespace App\Filament\Resources\ExpenseGroups\Pages;

use App\Filament\Resources\ExpenseGroups\ExpenseGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExpenseGroup extends CreateRecord
{
    protected static string $resource = ExpenseGroupResource::class;
}

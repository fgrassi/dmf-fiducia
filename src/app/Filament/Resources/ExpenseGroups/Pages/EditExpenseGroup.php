<?php

namespace App\Filament\Resources\ExpenseGroups\Pages;

use App\Filament\Resources\ExpenseGroups\ExpenseGroupResource;
use Filament\Resources\Pages\EditRecord;

class EditExpenseGroup extends EditRecord
{
    protected static string $resource = ExpenseGroupResource::class;
}

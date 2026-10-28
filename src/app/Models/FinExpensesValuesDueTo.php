<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** "Due To" (fin_expenses_values_due_to): nome e imagem (em uploads/images do tracking). */
class FinExpensesValuesDueTo extends Model
{
    protected $table = 'fin_expenses_values_due_to';

    public $timestamps = false;

    protected $guarded = [];
}

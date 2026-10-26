<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinExpenses extends Model
{
    protected $table = 'fin_expenses';

    protected $primaryKey = 'id';

    public $timestamps = false;
}

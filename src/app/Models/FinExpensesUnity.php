<?php

namespace App\Models;

use App\Models\Concerns\HasReferences;
use Illuminate\Database\Eloquent\Model;

class FinExpensesUnity extends Model
{
    use HasReferences;

    protected $table = 'fin_expenses_unities';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $references = ['fin_expenses' => 'ref_unity'];
}

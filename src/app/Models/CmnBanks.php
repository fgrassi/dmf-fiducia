<?php

namespace App\Models;

use App\Models\Concerns\HasReferences;
use Illuminate\Database\Eloquent\Model;

class CmnBanks extends Model
{
    use HasReferences;

    protected $table = 'cmn_banks';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $references = [
        'fin_charges' => 'ref_bank',
        'fin_armador_banks' => 'ref_bank',
        'despesa' => 'banco_id',
        'representante_armador' => 'banco_id',
        'fin_payment_process' => 'ref_banco',
    ];
}

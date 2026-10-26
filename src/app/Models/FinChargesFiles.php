<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinChargesFiles extends Model
{
    protected $table = 'fin_charges_files';

    protected $primaryKey = 'id';

    public $timestamps = false;

    public function file(): BelongsTo
    {
        return $this->belongsTo(CmnFile::class, 'ref_file', 'id');
    }
}

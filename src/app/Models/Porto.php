<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Porto extends Model
{
    protected $table = 'porto';

    protected $primaryKey = 'porto_id';

    public $timestamps = false;

    protected $guarded = [];

    /** Porto::__toString (legado): nome (país)(uncode). */
    protected function label(): Attribute
    {
        return Attribute::get(fn () => "{$this->nome} (".DB::table('paises')->where('paises_id', $this->ref_pais)->value('uncode').")({$this->uncode})");
    }
}

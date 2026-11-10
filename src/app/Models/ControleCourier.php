<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Courier recebido (controle_courier no legado); com `status_vinculo_nota = 's'` ele já virou despesa variada do agente. */
class ControleCourier extends Model
{
    public const LINKED = 's';

    /** ControleCourier::getContaUtilizadaNome (legado). */
    public const ACCOUNTS = ['d' => 'DMF', 'a' => 'AGENTE'];

    protected $table = 'controle_courier';

    protected $primaryKey = 'controle_courier_id';

    public $timestamps = false;

    protected $guarded = [];

    public function type(): BelongsTo
    {
        return $this->belongsTo(TipoCourier::class, 'tipo_courier_id', 'tipo_courier_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(CtrCourierEmbarque::class, 'controle_courier_id', 'controle_courier_id');
    }

    public function names(): HasMany
    {
        return $this->hasMany(CtrCourierSemEmbarque::class, 'controle_courier_id', 'controle_courier_id');
    }

    public function isLinked(): bool
    {
        return $this->status_vinculo_nota === self::LINKED;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Trading extends Model
{
    protected $table = 'trading';

    protected $primaryKey = 'trading_id';

    public $timestamps = false;

    protected $guarded = [];

    public function contacts(): HasMany
    {
        return $this->hasMany(TradingContato::class, 'trading_id', 'trading_id');
    }

    public function brokers(): HasMany
    {
        return $this->hasMany(TradingDespachante::class, 'trading_id', 'trading_id');
    }

    /** Em uso: reserva ou empresa vinculada (não pode excluir). */
    public function inUse(): bool
    {
        return Embarque::where('trading_id', $this->getKey())->exists()
            || DB::table('pessoa_trading')->where('trading_id', $this->getKey())->exists();
    }
}

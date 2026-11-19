<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** status: 1 OK, 0 aguardando aprovação (e-mail fora do domínio), -1 excluído. */
class TradingContato extends Model
{
    protected $table = 'trading_contato';

    protected $primaryKey = 'trading_contato_id';

    public $timestamps = false;

    protected $guarded = [];
}

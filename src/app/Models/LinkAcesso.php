<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Link com login e senha de acesso (links_acesso); excluir só marca deleted_at/deleted_by. */
class LinkAcesso extends Model
{
    protected $table = 'links_acesso';

    public $timestamps = false;

    protected $guarded = [];
}

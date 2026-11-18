<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NoticeSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'recipients' => 'array', 'cc' => 'array'];
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\HasReferences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Comissionado (comissao_embarque_responsavel): quem recebe ou paga a comissão do embarque. */
class ComissaoEmbarqueResponsavel extends Model
{
    use HasReferences;

    protected $table = 'comissao_embarque_responsavel';

    protected $primaryKey = 'comissao_embarque_responsavel_id';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $references = [
        'comissao_embarque' => 'comissao_embarque_responsavel_id',
    ];

    public function commissions(): HasMany
    {
        return $this->hasMany(ComissaoEmbarque::class, 'comissao_embarque_responsavel_id', 'comissao_embarque_responsavel_id');
    }
}

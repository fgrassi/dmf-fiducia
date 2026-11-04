<?php

namespace App\Models;

use App\Models\Concerns\HasReferences;
use Illuminate\Database\Eloquent\Model;

/** Fornecedor das despesas da empresa (tabela `fornecedor` do legado; não é o cadastro de pessoas). */
class Fornecedor extends Model
{
    use HasReferences;

    protected $table = 'fornecedor';

    protected $primaryKey = 'fornecedor_id';

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected array $references = [
        'despesa' => 'fornecedor_id',
        'despesa_vencimento' => 'fornecedor_id',
    ];
}

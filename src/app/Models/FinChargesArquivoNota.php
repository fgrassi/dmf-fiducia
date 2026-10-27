<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Arquivos de nota fiscal anexados à cobrança (aba "Arquivos de NF" do legado), em uploads/nfcobranca. */
class FinChargesArquivoNota extends Model
{
    protected $table = 'fin_charges_arquivo_nota';

    protected $primaryKey = 'fin_charges_arquivo_nota_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'usuario_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'usuario_id');
    }
}

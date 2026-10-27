<?php

namespace App\Models;

use App\Enums\Cobranca\InvoiceControlStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Histórico do controle de invoice (ControleInvoiceMasterLogService do legado). */
class ControleInvoiceMasterLog extends Model
{
    public const CREATION = 'criacao';

    public const RELEASE = 'liberacao';

    public const UPLOAD = 'upload';

    public const CHARGE = 'cobranca';

    public const ACTIONS = [
        self::CREATION => 'Criação',
        self::RELEASE => 'Liberação',
        self::UPLOAD => 'Upload',
        self::CHARGE => 'Cobrança',
    ];

    protected $table = 'controle_invoice_master_log';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'status_anterior' => InvoiceControlStatus::class,
            'status_novo' => InvoiceControlStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'usuario_id');
    }
}

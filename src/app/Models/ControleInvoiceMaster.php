<?php

namespace App\Models;

use App\Enums\Cobranca\InvoiceControlStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Controle de invoice do MBL (embarque + mbl_number), criado pendente pelo InvoiceGate quando o armador exige invoice.
 * Pendente bloqueia a IC; OK (arquivo recebido ou dispensado) e Liberado (aguardando arquivo) deixam a cobrança sair.
 */
class ControleInvoiceMaster extends Model
{
    protected $table = 'controle_invoice_master';

    protected $primaryKey = 'controle_invoice_master_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => InvoiceControlStatus::class,
            'created_at' => 'datetime',
            'uploaded_at' => 'datetime',
        ];
    }

    public function embarque(): BelongsTo
    {
        return $this->belongsTo(Embarque::class, 'embarque_id', 'embarque_id');
    }

    public function armador(): BelongsTo
    {
        return $this->belongsTo(Armador::class, 'armador_id', 'armador_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'usuario_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ControleInvoiceMasterLog::class, 'controle_invoice_master_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /** marcaComoRecebido: o arquivo deixa o invoice OK, qualquer que fosse o status. */
    public function markReceived(string $fileName, ?int $userId): void
    {
        $this->transition(InvoiceControlStatus::Ok, ControleInvoiceMasterLog::UPLOAD, "Arquivo do invoice enviado: {$fileName}", $userId, [
            'file_name' => $fileName,
            'uploaded_at' => now(),
        ]);
    }

    /** liberarParaCobranca: só o pendente passa a liberado, aguardando o arquivo. */
    public function releaseForBilling(string $note, ?int $userId): bool
    {
        if ($this->status !== InvoiceControlStatus::Pending) {
            return false;
        }

        $this->transition(InvoiceControlStatus::ReleasedAwaitingFile, ControleInvoiceMasterLog::RELEASE, $note, $userId);

        return true;
    }

    /** liberarSemInvoice: OK sem arquivo (invoice dispensado). */
    public function releaseWithoutInvoice(?int $userId): bool
    {
        if ($this->status === InvoiceControlStatus::Ok) {
            return false;
        }

        $this->transition(InvoiceControlStatus::Ok, ControleInvoiceMasterLog::RELEASE, 'Liberado sem arquivo de invoice.', $userId);

        return true;
    }

    public function releasedWithoutFile(): bool
    {
        return $this->status === InvoiceControlStatus::Ok && blank($this->file_name);
    }

    public function log(string $action, ?string $note, ?int $userId, ?InvoiceControlStatus $from, ?InvoiceControlStatus $to): void
    {
        $this->logs()->create([
            'acao' => $action,
            'historico' => $note,
            'status_anterior' => $from,
            'status_novo' => $to,
            'created_at' => now(),
            'created_by' => $userId,
        ]);
    }

    private function transition(InvoiceControlStatus $to, string $action, string $note, ?int $userId, array $attributes = []): void
    {
        $from = $this->status;
        $this->forceFill([...$attributes, 'status' => $to])->save();
        $this->log($action, $note, $userId, $from, $to);
    }
}

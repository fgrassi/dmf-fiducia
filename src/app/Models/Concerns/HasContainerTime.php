<?php

namespace App\Models\Concerns;

use App\Models\Embarque;
use App\Models\TipoContainer;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Datas e dias de demurrage/detention de um container (FinDemurrage::getDemurrageTime e getStatusDemurrage, legado). */
trait HasContainerTime
{
    public function embarque(): BelongsTo
    {
        return $this->belongsTo(Embarque::class, 'ref_embarque', 'embarque_id');
    }

    public function tipoContainer(): BelongsTo
    {
        return $this->belongsTo(TipoContainer::class, 'ref_tipo_container', 'tipo_container_id');
    }

    /** Free time do container: o do próprio registro quando informado (só demurrage), senão o da reserva. */
    abstract public function freeTimeDays(): int;

    public function isClosed(): bool
    {
        return $this->data_retirada !== null && $this->data_devolucao !== null;
    }

    /** Dias de operação, contando o primeiro dia (o legado devolvia 1 sem datas; aqui fica sem valor). */
    public function operationDays(): ?int
    {
        return $this->isClosed() ? (int) abs($this->data_retirada->diffInDays($this->data_devolucao)) + 1 : null;
    }

    /** Dias além do free time para exibir; nulo se ainda não fechou ou se está dentro dele. */
    public function overdueDays(): ?int
    {
        $days = $this->operationDays();

        return $days !== null && $days - $this->freeTimeDays() >= 0 ? $days - $this->freeTimeDays() : null;
    }

    /** Há cobrança? Verdadeiro quando o intervalo (1 + dias entre retirada e devolução) passa do free time; nulo sem as duas datas. */
    public function isCharged(): ?bool
    {
        return $this->isClosed()
            ? 1 + $this->data_retirada->diffInDays($this->data_devolucao, false) > $this->freeTimeDays()
            : null;
    }
}

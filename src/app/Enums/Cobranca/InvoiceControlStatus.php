<?php

namespace App\Enums\Cobranca;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** controle_invoice_master.status (ControleInvoiceMaster::labelsStatus do legado). Só Pendente bloqueia a cobrança. */
enum InvoiceControlStatus: int implements HasColor, HasLabel
{
    case Pending = 0;
    case Ok = 1;
    case ReleasedAwaitingFile = 2;

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Ok => 'OK',
            self::ReleasedAwaitingFile => 'Liberado, aguardando arquivos',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'danger',
            self::Ok => 'success',
            self::ReleasedAwaitingFile => 'warning',
        };
    }
}

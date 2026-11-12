<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Operação da reserva (impor_expor): Impo em azul, Expo em verde, igual em todas as telas. */
enum OperationType: string implements HasColor, HasLabel
{
    case Import = 'i';
    case Export = 'e';

    public function getLabel(): string
    {
        return match ($this) {
            self::Import => 'Importação',
            self::Export => 'Exportação',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Import => 'info',
            self::Export => 'success',
        };
    }

    /** @return array<string, string> valor => rótulo, para Select/filtros (a coluna segue string, sem cast para enum). */
    public static function options(): array
    {
        return array_column(array_map(fn (self $c) => [$c->value, $c->getLabel()], self::cases()), 1, 0);
    }

    /** Rótulo para valores do legado fora de i/e (vazio, outros): devolve o valor como veio. */
    public static function labelFor(?string $value): string
    {
        return self::tryFrom((string) $value)?->getLabel() ?? (string) $value;
    }
}

<?php

namespace App\Actions\PaymentOrders\Generation;

use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * PaymentOrderSelection::withLock (legado): uma geração de ordens por módulo por vez. No MySQL usa o mesmo GET_LOCK do
 * tracking, então a geração do legado e a do sistema novo se excluem enquanto os dois estiverem ligados; em outro banco
 * (testes) só impede a reentrada no mesmo processo.
 */
class PaymentGenerationLock
{
    public const BUSY = 'Outra geração de ordens está em andamento. Tente novamente.';

    /** @var array<string, bool> */
    private static array $held = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws DomainException lock ocupado por mais de 10 segundos
     */
    public function __invoke(string $module, Closure $callback): mixed
    {
        if (self::$held[$module] ?? false) {
            return $callback();
        }

        $name = "tracking:payment-generation:{$module}";
        $mysql = DB::connection()->getDriverName() === 'mysql';

        if ($mysql && (int) DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$name])->acquired !== 1) {
            throw new DomainException(self::BUSY);
        }

        self::$held[$module] = true;

        try {
            return $callback();
        } finally {
            unset(self::$held[$module]);

            if ($mysql) {
                DB::selectOne('SELECT RELEASE_LOCK(?)', [$name]);
            }
        }
    }
}

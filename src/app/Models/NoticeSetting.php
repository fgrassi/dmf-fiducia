<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class NoticeSetting extends Model
{
    /** Segundos que a leitura em memória vale (o agendador consulta todos os avisos a cada minuto). */
    private const CACHE_SECONDS = 10;

    /** @var Collection<string, NoticeSetting>|null */
    private static ?Collection $cache = null;

    private static float $loadedAt = 0;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saved(fn () => self::flush());
        static::deleted(fn () => self::flush());
    }

    /** Ajuste do aviso/rotina (chave = comando), lido uma vez para todos e renovado a cada poucos segundos ou ao gravar. */
    public static function for(string $notice): ?self
    {
        if (self::$cache === null || microtime(true) - self::$loadedAt > self::CACHE_SECONDS) {
            self::$cache = self::query()->get()->keyBy('notice');
            self::$loadedAt = microtime(true);
        }

        return self::$cache->get($notice);
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'recipients' => 'array', 'cc' => 'array', 'weekdays' => 'array'];
    }
}

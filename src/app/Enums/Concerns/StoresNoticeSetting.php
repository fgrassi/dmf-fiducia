<?php

namespace App\Enums\Concerns;

use App\Models\NoticeSetting;

/** Liga/desliga e horário gravados na tela Avisos por e-mail (notice_settings); sem linha valem o padrão do enum. */
trait StoresNoticeSetting
{
    /** Horário (H:i) padrão; nulo quando a rotina roda a cada hora. */
    abstract protected function defaultTime(): ?string;

    /** Dias da semana padrão (0 domingo a 6 sábado); nulo quando o dia não é de semana (ex.: dia 1º do mês) e não se edita. */
    abstract protected function defaultWeekdays(): ?array;

    public function enabled(): bool
    {
        return $this->setting()?->enabled ?? true;
    }

    /** Horário (H:i) em que roda, o configurado ou o padrão. */
    public function time(): ?string
    {
        $time = $this->setting()?->time ?? $this->defaultTime();

        return $time === null ? null : substr($time, 0, 5);
    }

    /**
     * Dias da semana em que roda (0 domingo a 6 sábado), os configurados ou os padrão; nulo quando o dia é fixo por outra regra.
     *
     * @return list<int>|null
     */
    public function weekdays(): ?array
    {
        $default = $this->defaultWeekdays();

        if ($default === null) {
            return null;
        }

        return array_values(array_map('intval', $this->setting()?->weekdays ?: $default));
    }

    public function runsToday(): bool
    {
        $weekdays = $this->weekdays();

        return $weekdays === null || in_array(now()->dayOfWeek, $weekdays, true);
    }

    /** "Diário", "Dias úteis" ou os dias, como "Seg, Qua, Sex". @param  list<int>  $weekdays */
    protected static function weekdaysLabel(array $weekdays): string
    {
        $weekdays = collect($weekdays)->unique()->sort()->values()->all();

        return match (true) {
            $weekdays === [0, 1, 2, 3, 4, 5, 6] => 'Diário',
            $weekdays === [1, 2, 3, 4, 5] => 'Dias úteis',
            default => collect($weekdays)->map(fn (int $day) => ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'][$day])->implode(', '),
        };
    }

    private function setting(): ?NoticeSetting
    {
        return NoticeSetting::for($this->value);
    }
}

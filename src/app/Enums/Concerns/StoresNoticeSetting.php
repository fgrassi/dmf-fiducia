<?php

namespace App\Enums\Concerns;

use App\Models\NoticeSetting;

/** Liga/desliga e horário gravados na tela Avisos por e-mail (notice_settings); sem linha valem o padrão do enum. */
trait StoresNoticeSetting
{
    /** Horário (H:i) padrão; nulo quando a rotina roda a cada hora. */
    abstract protected function defaultTime(): ?string;

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

    private function setting(): ?NoticeSetting
    {
        return NoticeSetting::firstWhere('notice', $this->value);
    }
}

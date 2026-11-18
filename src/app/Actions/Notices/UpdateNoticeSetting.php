<?php

namespace App\Actions\Notices;

use App\Enums\Notice;
use App\Enums\Routine;
use App\Models\NoticeSetting;

/** Grava o liga/desliga, o horário e os destinatários de um aviso ou rotina automática; só os campos informados mudam. */
class UpdateNoticeSetting
{
    /**
     * @param  array{enabled?: bool, time?: string, weekdays?: list<int>, subject?: string|null, body?: string|null, recipients?: list<string>, cc?: list<string>}  $changes
     */
    public function __invoke(Notice|Routine $notice, array $changes): NoticeSetting
    {
        $setting = NoticeSetting::firstOrNew(['notice' => $notice->value]);
        $setting->fill($changes)->save();

        return $setting;
    }
}

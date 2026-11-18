<?php

namespace Tests\Feature\Actions\Notices;

use App\Actions\Notices\UpdateNoticeSetting;
use App\Enums\Notice;
use App\Enums\Routine;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class NoticeWeekdaysTest extends TestCase
{
    use LegacySchema;

    public function test_default_days_are_the_legacy_ones(): void
    {
        $this->assertSame('Diário', Notice::ExchangeDeadline->days());
        $this->assertSame('Dias úteis', Notice::PendingTakers->days());
        $this->assertSame('Seg', Notice::Provisioning->days());
        $this->assertSame('Dia 1º', Notice::NotesMonthlyReport->days());
        $this->assertNull(Notice::NotesMonthlyReport->weekdays());
        $this->assertSame('A cada hora', Routine::GenerateCharges->days());
        $this->assertSame('Diário', Routine::GenerateCoverSummaries->days());
    }

    public function test_configured_days_change_the_label_and_when_it_runs(): void
    {
        app(UpdateNoticeSetting::class)(Notice::ExchangeDeadline, ['weekdays' => [3, 1]]);
        app(UpdateNoticeSetting::class)(Routine::GenerateCharges, ['weekdays' => [2]]);

        $this->assertSame('Seg, Qua', Notice::ExchangeDeadline->days());
        $this->assertSame('A cada hora · Ter', Routine::GenerateCharges->days());

        foreach (['2026-11-16' => true, '2026-11-17' => false, '2026-11-18' => true, '2026-11-21' => false] as $date => $runs) {
            $this->travelTo($date);
            $this->assertSame($runs, Notice::ExchangeDeadline->runsToday(), $date);
        }
    }

    public function test_the_scheduler_only_fires_on_the_chosen_days_and_keeps_the_fixed_rules(): void
    {
        config(['services.cobranca.automatic_notices' => true]);
        $fires = function (string $command, string $at): bool {
            $this->travelTo($at);
            $event = collect(app(Schedule::class)->events())->first(fn ($e) => preg_match('/ '.preg_quote($command, '/').'[\'" ]?$/', trim($e->command)));

            return $event->isDue($this->app) && $event->filtersPass($this->app);
        };

        $this->assertTrue($fires('financeiro:aviso-limite-cambio', '2026-11-21 08:00'), 'sábado, padrão diário');
        $this->assertFalse($fires('cobranca:aviso-pendencia-tomador', '2026-11-21 08:00'), 'tomadores não roda no sábado');
        $this->assertTrue($fires('cobranca:aviso-pendencia-tomador', '2026-11-16 08:00'));
        $this->assertTrue($fires('financeiro:provisionamento-semanal', '2026-11-16 08:00'), 'provisionamento na segunda');
        $this->assertFalse($fires('financeiro:provisionamento-semanal', '2026-11-17 08:00'));

        app(UpdateNoticeSetting::class)(Notice::PendingTakers, ['weekdays' => [6]]);
        app(UpdateNoticeSetting::class)(Notice::Provisioning, ['weekdays' => [2]]);

        $this->assertTrue($fires('cobranca:aviso-pendencia-tomador', '2026-11-21 08:00'), 'agora roda só no sábado');
        $this->assertFalse($fires('cobranca:aviso-pendencia-tomador', '2026-11-16 08:00'));
        $this->assertTrue($fires('financeiro:provisionamento-semanal', '2026-11-17 08:00'), 'agora na terça');
        $this->assertFalse($fires('financeiro:provisionamento-semanal', '2026-11-16 08:00'));
    }
}

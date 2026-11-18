<?php

namespace Tests\Feature\Actions\Notices;

use App\Actions\Booking\ExpenseReviewNotice;
use App\Actions\Cobranca\Notices\OpenChargeReminders;
use App\Actions\Financeiro\ExchangeDeadlineNotice;
use App\Actions\Notices\UpdateNoticeSetting;
use App\Enums\Notice;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class NoticeSettingsApplyTest extends TestCase
{
    use LegacySchema;

    public function test_a_notice_without_setting_keeps_the_legacy_recipients(): void
    {
        $this->assertTrue(Notice::ExchangeDeadline->enabled());
        $this->assertSame(['financeiro@dmflog.com.br'], Notice::ExchangeDeadline->recipients());
        $this->assertSame(['it@dmflog.com.br'], Notice::ReleaseList->copies());
    }

    public function test_the_time_defaults_to_the_legacy_one_until_it_is_changed(): void
    {
        $this->assertSame('08:00', Notice::ExchangeDeadline->time());
        $this->assertSame('18:00', Notice::ExpenseReviewExport->time());

        app(UpdateNoticeSetting::class)(Notice::ExchangeDeadline, ['time' => '10:45:00']);

        $this->assertSame('10:45', Notice::ExchangeDeadline->time());
    }

    public function test_the_configured_recipients_receive_the_notice(): void
    {
        $this->travelTo('2026-11-09');
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'Agente Hoje', 'data_limite_cambio' => '2026-11-09']);
        app(UpdateNoticeSetting::class)(Notice::ExchangeDeadline, ['recipients' => ['novo@dmflog.com.br', 'outro@dmflog.com.br']]);

        app(ExchangeDeadlineNotice::class)();

        $this->assertSame(['novo@dmflog.com.br', 'outro@dmflog.com.br'], $this->sentRecipients());
    }

    public function test_a_disabled_notice_sends_nothing(): void
    {
        $this->travelTo('2026-11-09');
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'Agente Hoje', 'data_limite_cambio' => '2026-11-09']);
        app(UpdateNoticeSetting::class)(Notice::ExchangeDeadline, ['enabled' => false]);

        $this->assertSame(0, app(ExchangeDeadlineNotice::class)());
        $this->assertSame([], $this->sentSubjects()->all());
    }

    public function test_the_expense_review_notice_is_enabled_per_operation(): void
    {
        app(UpdateNoticeSetting::class)(Notice::ExpenseReviewImport, ['enabled' => false]);

        $this->assertFalse(Notice::ExpenseReviewImport->enabled());
        $this->assertTrue(Notice::ExpenseReviewExport->enabled());
        $this->assertSame(0, app(ExpenseReviewNotice::class)());
    }

    public function test_the_client_reminder_can_be_disabled_and_keeps_the_billing_copy_blind(): void
    {
        $this->assertSame(['cobranca@dmflog.com.br'], Notice::OpenCharges->copies());
        $this->assertTrue(Notice::OpenCharges->copiesAreBlind());
        $this->assertFalse(Notice::OpenCharges->hasFixedRecipients());

        app(UpdateNoticeSetting::class)(Notice::OpenCharges, ['enabled' => false]);

        $this->assertSame(0, app(OpenChargeReminders::class)());
    }
}

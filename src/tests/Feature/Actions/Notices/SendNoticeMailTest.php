<?php

namespace Tests\Feature\Actions\Notices;

use App\Actions\Notices\SendNoticeMail;
use App\Actions\Notices\UpdateNoticeSetting;
use App\Enums\Notice;
use App\Models\NoticeSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class SendNoticeMailTest extends TestCase
{
    use LegacySchema;

    public function test_the_notice_mail_is_sent_with_to_cc_and_bcc(): void
    {
        $sent = app(SendNoticeMail::class)('Assunto', '<p>oi</p>', ['a@dmflog.com.br'], cc: ['b@dmflog.com.br'], bcc: ['c@dmflog.com.br'], replyTo: 'r@dmflog.com.br');

        $this->assertTrue($sent);
        $this->assertSame(['Assunto'], $this->sentSubjects()->all());
        $this->assertSame(['a@dmflog.com.br'], $this->sentRecipients());
        $this->assertSame(['b@dmflog.com.br'], $this->sentRecipients('cc'));
        $this->assertSame(['r@dmflog.com.br'], $this->sentRecipients('replyTo'));
    }

    public function test_a_send_failure_returns_false_and_does_not_stop_the_next_send(): void
    {
        $realMailer = app('mail.manager');
        Mail::shouldReceive('html')->once()->andThrow(new RuntimeException('smtp fora do ar'));
        $first = app(SendNoticeMail::class)('Um', '<p>1</p>', ['x@cliente.com']);

        Mail::swap($realMailer);
        $second = app(SendNoticeMail::class)('Dois', '<p>2</p>', ['y@cliente.com']);

        $this->assertFalse($first);
        $this->assertTrue($second);
        $this->assertSame(['Dois'], $this->sentSubjects()->all());
    }

    public function test_settings_are_read_once_for_all_notices_and_refreshed_after_a_save(): void
    {
        NoticeSetting::flush();
        DB::enableQueryLog();

        foreach (Notice::cases() as $notice) {
            $notice->enabled();
            $notice->time();
            $notice->recipients();
        }

        $this->assertCount(1, DB::getQueryLog());

        app(UpdateNoticeSetting::class)(Notice::HapagPending, ['enabled' => false]);

        $this->assertFalse(Notice::HapagPending->enabled());
    }
}

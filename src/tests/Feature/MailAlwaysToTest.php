<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailAlwaysToTest extends TestCase
{
    public function test_every_recipient_is_replaced_by_the_configured_address(): void
    {
        config(['mail.default' => 'array', 'mail.to.address' => 'suporte1@dmflog.com.br']);
        Mail::purge();

        Mail::html('<p>oi</p>', fn ($mail) => $mail->to('cliente@example.com')->cc('financeiro@dmflog.com.br')->bcc('x@example.com')->subject('Teste'));

        $message = Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage();

        $this->assertSame(['suporte1@dmflog.com.br'], array_map(fn ($a) => $a->getAddress(), $message->getTo()));
        $this->assertSame([], $message->getCc());
        $this->assertSame([], $message->getBcc());
    }
}

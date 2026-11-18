<?php

namespace Tests;

use App\Models\NoticeSetting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        NoticeSetting::flush();
    }

    /**
     * Assuntos dos e-mails enviados pelo mailer array (MAIL_MAILER do phpunit.xml).
     * Os avisos ao financeiro usam Mail::html, que o Mail::fake() não intercepta.
     *
     * @return Collection<int, string>
     */
    protected function sentSubjects(): Collection
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()
            ->map(fn (SentMessage $m) => $m->getOriginalMessage()->getSubject())
            ->values();
    }

    /** Corpo HTML de todos os e-mails enviados pelo mailer array, um depois do outro. */
    protected function sentHtml(): string
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()
            ->map(fn (SentMessage $m) => $m->getOriginalMessage()->getHtmlBody())
            ->implode('');
    }

    /**
     * Destinatários do primeiro e-mail enviado pelo mailer array: Para ('to'), Cópia ('cc') ou Responder para ('replyTo').
     *
     * @return list<string>
     */
    protected function sentRecipients(string $header = 'to'): array
    {
        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();

        return array_map(fn ($address) => $address->getAddress(), $message->{'get'.ucfirst($header)}());
    }
}

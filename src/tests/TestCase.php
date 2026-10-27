<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;

abstract class TestCase extends BaseTestCase
{
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
}

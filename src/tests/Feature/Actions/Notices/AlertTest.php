<?php

namespace Tests\Feature\Actions\Notices;

use App\Actions\Notices\UpdateNoticeSetting;
use App\Enums\Alert;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class AlertTest extends TestCase
{
    use LegacySchema;

    public function test_without_a_setting_the_alert_goes_to_the_default_recipients_and_copy(): void
    {
        Alert::NfseNotIssued->send('Assunto', '<p>oi</p>');

        $this->assertSame(['Assunto'], $this->sentSubjects()->all());
        $this->assertSame(['financeiro@dmflog.com.br'], $this->sentRecipients());
        $this->assertSame(['chamado@dmflog.com.br'], $this->sentRecipients('cc'));
    }

    public function test_the_saved_recipients_replace_the_default_ones_and_the_case_recipients_are_kept_once(): void
    {
        app(UpdateNoticeSetting::class)(Alert::ChargeChecks, ['recipients' => ['novo@dmflog.com.br'], 'cc' => ['copia@dmflog.com.br']]);

        Alert::ChargeChecks->send('Assunto', '<p>oi</p>', also: ['autor@dmflog.com.br', 'novo@dmflog.com.br']);

        $this->assertSame(['autor@dmflog.com.br', 'novo@dmflog.com.br'], $this->sentRecipients());
        $this->assertSame(['copia@dmflog.com.br'], $this->sentRecipients('cc'));
    }

    public function test_a_removed_default_copy_is_not_sent(): void
    {
        app(UpdateNoticeSetting::class)(Alert::NfseNotIssued, ['cc' => []]);

        Alert::NfseNotIssued->send('Assunto', '<p>oi</p>');

        $this->assertSame([], $this->sentRecipients('cc'));
    }

    public function test_a_disabled_alert_is_not_sent(): void
    {
        app(UpdateNoticeSetting::class)(Alert::ManualSettlement, ['enabled' => false]);

        Alert::ManualSettlement->send('Assunto', '<p>oi</p>');

        $this->assertCount(0, $this->sentSubjects());
    }

    public function test_every_alert_has_a_description_and_valid_default_addresses(): void
    {
        foreach (Alert::cases() as $alert) {
            $this->assertNotSame('', $alert->label(), $alert->value);
            $this->assertNotEmpty($alert->recipients(), $alert->value);

            foreach ([...$alert->recipients(), ...$alert->copies()] as $address) {
                $this->assertNotFalse(filter_var($address, FILTER_VALIDATE_EMAIL), "{$alert->value}: {$address}");
            }
        }
    }
}

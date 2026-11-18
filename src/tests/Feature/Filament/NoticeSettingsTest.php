<?php

namespace Tests\Feature\Filament;

use App\Enums\Notice;
use App\Enums\Routine;
use App\Filament\Pages\NoticeSettings;
use App\Models\NoticeSetting;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class NoticeSettingsTest extends TestCase
{
    use LegacySchema;

    public function test_edit_changes_recipients_and_copy_of_a_notice(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(NoticeSettings::class)
            ->assertCanSeeTableRecords([Notice::HapagPending->value])
            ->callAction(TestAction::make('edit')->table(Notice::HapagPending->value), ['enabled' => true, 'time' => '09:30', 'recipients' => ['novo@dmflog.com.br'], 'cc' => ['copia@dmflog.com.br']])
            ->assertHasNoActionErrors();

        $this->assertSame(['novo@dmflog.com.br'], Notice::HapagPending->recipients());
        $this->assertSame(['copia@dmflog.com.br'], Notice::HapagPending->copies());
        $this->assertSame('09:30', Notice::HapagPending->time());
    }

    public function test_search_filters_the_list_by_description_and_recipient(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(NoticeSettings::class)
            ->searchTable('Hapag')
            ->assertCanSeeTableRecords([Notice::HapagPending->value])
            ->assertCanNotSeeTableRecords([Notice::ExchangeDeadline->value])
            ->searchTable('douglas@dmflog')
            ->assertCanSeeTableRecords([Notice::ExpenseReviewExport->value])
            ->assertCanNotSeeTableRecords([Notice::ExpenseReviewImport->value]);
    }

    public function test_routines_tab_lists_the_generation_crons_and_edits_the_daily_time_and_switch(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(NoticeSettings::class)
            ->set('activeTab', 'rotinas')
            ->assertCanSeeTableRecords([Routine::GenerateCharges->value, Routine::GenerateCoverSummaries->value])
            ->assertCanNotSeeTableRecords([Notice::HapagPending->value])
            ->callAction(TestAction::make('edit')->table(Routine::GenerateCoverSummaries->value), ['enabled' => false, 'time' => '03:15'])
            ->assertHasNoActionErrors()
            ->callAction(TestAction::make('edit')->table(Routine::GenerateCharges->value), ['enabled' => false])
            ->assertHasNoActionErrors();

        $this->assertFalse(Routine::GenerateCoverSummaries->enabled());
        $this->assertSame('03:15', Routine::GenerateCoverSummaries->time());
        $this->assertFalse(Routine::GenerateCharges->enabled());
        $this->assertNull(Routine::GenerateCharges->time());
    }

    public function test_edit_rejects_an_invalid_address_and_an_empty_recipient_list(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(NoticeSettings::class)
            ->callAction(TestAction::make('edit')->table(Notice::HapagPending->value), ['enabled' => true, 'time' => '09:30', 'recipients' => ['sem-arroba'], 'cc' => []])
            ->assertHasActionErrors(['recipients.0']);

        Livewire::test(NoticeSettings::class)
            ->callAction(TestAction::make('edit')->table(Notice::HapagPending->value), ['enabled' => true, 'time' => '09:30', 'recipients' => [], 'cc' => []])
            ->assertHasActionErrors(['recipients' => 'required']);

        $this->assertSame(0, NoticeSetting::count());
    }

    public function test_notice_with_recipients_from_the_booking_only_edits_the_copy(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(NoticeSettings::class)
            ->callAction(TestAction::make('edit')->table(Notice::MblRelease->value), ['enabled' => false, 'time' => '08:00', 'cc' => ['a@dmflog.com.br']])
            ->assertHasNoActionErrors();

        $setting = NoticeSetting::firstWhere('notice', Notice::MblRelease->value);
        $this->assertFalse($setting->enabled);
        $this->assertNull($setting->recipients);
        $this->assertSame(['a@dmflog.com.br'], $setting->cc);
    }

    public function test_page_is_restricted_to_admin_and_financial(): void
    {
        $this->actingAs($this->user('booking'));
        $this->assertFalse(NoticeSettings::canAccess());

        $this->actingAs($this->user('financial'));
        $this->assertTrue(NoticeSettings::canAccess());
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.$credentials, 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}

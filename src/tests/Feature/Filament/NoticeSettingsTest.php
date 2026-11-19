<?php

namespace Tests\Feature\Filament;

use App\Actions\Notices\NoticeTexts;
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

    public function test_edit_saves_the_email_text_and_blank_goes_back_to_the_default(): void
    {
        $this->actingAs($this->user('financial'));
        $edit = ['enabled' => true, 'time' => '08:00', 'recipients' => ['a@dmflog.com.br'], 'cc' => []];

        Livewire::test(NoticeSettings::class)
            ->callAction(TestAction::make('edit')->table(Notice::OpenCommissions->value), [...$edit, 'subject' => 'Comissões de {{data}}', 'body' => '<p>Segue:</p>{{lista}}'])
            ->assertHasNoActionErrors();

        $setting = NoticeSetting::firstWhere('notice', Notice::OpenCommissions->value);
        $this->assertSame('Comissões de {{data}}', $setting->subject);
        $this->assertSame('<p>Segue:</p>{{lista}}', $setting->body);

        Livewire::test(NoticeSettings::class)
            ->callAction(TestAction::make('edit')->table(Notice::OpenCommissions->value), [...$edit, 'subject' => '', 'body' => null])
            ->assertHasNoActionErrors();

        $setting->refresh();
        $this->assertNull($setting->subject);
        $this->assertNull($setting->body);
    }

    public function test_edit_opens_with_the_default_text_and_an_unchanged_subject_is_not_saved(): void
    {
        $this->actingAs($this->user('financial'));
        $edit = ['enabled' => true, 'time' => '08:00', 'recipients' => ['a@dmflog.com.br'], 'cc' => []];
        $notice = Notice::OpenCommissions;
        $default = NoticeTexts::for($notice);

        Livewire::test(NoticeSettings::class)
            ->mountAction(TestAction::make('edit')->table($notice->value))
            ->assertSchemaStateSet(['subject' => $default['subject'], 'body' => NoticeTexts::editorBody($notice)])
            ->callMountedAction([...$edit, 'subject' => $default['subject']])
            ->assertHasNoActionErrors();

        $this->assertNull(NoticeSetting::firstWhere('notice', $notice->value)->subject);
    }

    public function test_the_select_appends_the_variable_to_the_body(): void
    {
        $this->actingAs($this->user('financial'));
        $notice = Notice::OpenCommissions;

        Livewire::test(NoticeSettings::class)
            ->mountAction(TestAction::make('edit')->table($notice->value))
            ->fillForm(['insert_body_tag' => 'data'])
            ->assertSchemaStateSet(['body' => NoticeTexts::editorBody($notice).'<p><span data-type="mergeTag" data-id="data"></span></p>', 'insert_body_tag' => null]);
    }

    public function test_preview_renders_the_unsaved_text_with_sample_values(): void
    {
        $this->actingAs($this->user('financial'));
        $page = Livewire::test(NoticeSettings::class)->instance();
        $preview = fn (array $data): string => (string) (new \ReflectionMethod($page, 'preview'))->invoke($page, ['notice' => Notice::ExpenseDue->value], $data);

        $default = html_entity_decode($preview(['subject' => 'Vence {{vencimento}}', 'body' => null]));
        $this->assertStringContainsString('Vence 21/11/2026', $default);
        $this->assertStringContainsString('Lembrete: os vencimentos abaixo ocorrem em 3 dias (21/11/2026)', $default);

        $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Texto novo para '], ['type' => 'mergeTag', 'attrs' => ['id' => 'vencimento']]]]]];
        $custom = html_entity_decode($preview(['subject' => null, 'body' => $document]));
        $this->assertStringContainsString('Texto novo para', $custom);
        $this->assertStringContainsString('21/11/2026', $custom);
        $this->assertStringNotContainsString('Lembrete', $custom);
    }

    public function test_edit_changes_the_weekdays_and_requires_at_least_one(): void
    {
        $this->actingAs($this->user('financial'));
        $edit = ['enabled' => true, 'time' => '08:00', 'recipients' => ['a@dmflog.com.br'], 'cc' => []];

        Livewire::test(NoticeSettings::class)
            ->callAction(TestAction::make('edit')->table(Notice::HapagPending->value), [...$edit, 'weekdays' => ['1', '3', '5']])
            ->assertHasNoActionErrors();

        $this->assertSame([1, 3, 5], Notice::HapagPending->weekdays());
        $this->assertSame('Seg, Qua, Sex', Notice::HapagPending->days());

        Livewire::test(NoticeSettings::class)
            ->callAction(TestAction::make('edit')->table(Notice::HapagPending->value), [...$edit, 'weekdays' => []])
            ->assertHasActionErrors(['weekdays']);
    }

    public function test_the_monthly_report_has_a_fixed_day_and_does_not_ask_for_weekdays(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(NoticeSettings::class)
            ->callAction(TestAction::make('edit')->table(Notice::NotesMonthlyReport->value), ['enabled' => true, 'time' => '09:00', 'recipients' => ['a@dmflog.com.br'], 'cc' => []])
            ->assertHasNoActionErrors();

        $this->assertSame('Dia 1º', Notice::NotesMonthlyReport->days());
        $this->assertSame('09:00', Notice::NotesMonthlyReport->time());
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

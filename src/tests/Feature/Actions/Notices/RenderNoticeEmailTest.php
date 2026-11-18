<?php

namespace Tests\Feature\Actions\Notices;

use App\Actions\Financeiro\ExchangeDeadlineNotice;
use App\Actions\Notices\NoticeTexts;
use App\Actions\Notices\RenderNoticeEmail;
use App\Actions\Notices\UpdateNoticeSetting;
use App\Enums\Notice;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class RenderNoticeEmailTest extends TestCase
{
    use LegacySchema;

    public function test_without_a_saved_text_the_default_subject_and_body_get_the_values(): void
    {
        [$subject, $html] = app(RenderNoticeEmail::class)(Notice::PriorityArrivals, ['dias' => 5, 'lista' => '<table><tr><td>X</td></tr></table>']);

        $this->assertSame('[Aviso] Embarques Prioritários ETA próximo (+5 dias)', $subject);
        $this->assertSame('<h3>Os seguintes embarques prioritários chegam em 5 dias: </h3><table><tr><td>X</td></tr></table>', $html);
    }

    public function test_values_are_escaped_except_the_variables_marked_as_html(): void
    {
        [, $html] = app(RenderNoticeEmail::class)(Notice::MblRelease, ['reserva' => '<b>BKG</b>', 'mbl' => 'M', 'hbl' => 'H', 'etd' => 'E', 'pod' => 'P', 'importador' => 'I']);

        $this->assertStringContainsString('booking &lt;b&gt;BKG&lt;/b&gt; .', $html);

        [, $html] = app(RenderNoticeEmail::class)(Notice::HapagPending, ['lista' => '<ul><li>ok</li></ul>']);

        $this->assertStringContainsString('<ul><li>ok</li></ul>', $html);
    }

    public function test_a_saved_text_replaces_the_default_with_plain_variables_and_editor_merge_tags(): void
    {
        $chip = '<span data-type="mergeTag" data-id="data"></span>';
        app(UpdateNoticeSetting::class)(Notice::OpenCommissions, [
            'subject' => 'Aviso de {{data}}',
            'body' => "<p>Olá, hoje é {$chip}.</p><p>{{lista}}</p>",
        ]);

        [$subject, $html] = app(RenderNoticeEmail::class)(Notice::OpenCommissions, ['data' => '18/11/2026', 'lista' => '<table><tr><td>C1</td></tr></table>']);

        $this->assertSame('Aviso de 18/11/2026', $subject);
        $this->assertStringContainsString('Olá, hoje é 18/11/2026.', $html);
        $this->assertStringContainsString('</p><table><tr><td>C1</td></tr></table>', $html);
        $this->assertStringNotContainsString('{{', $html);
    }

    public function test_the_notice_sends_the_saved_text(): void
    {
        $this->travelTo('2026-11-09');
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'Agente Hoje', 'data_limite_cambio' => '2026-11-09']);
        app(UpdateNoticeSetting::class)(Notice::ExchangeDeadline, ['subject' => 'Câmbio de {{lista}}', 'body' => '<p>Prazos:</p>{{lista}}']);

        app(ExchangeDeadlineNotice::class)();

        $this->assertStringStartsWith('Câmbio de <table', $this->sentSubjects()->first());
        $this->assertStringContainsString('<p>Prazos:</p><table border="1">', $this->sentHtml());
        $this->assertStringContainsString('<td>Agente Hoje</td>', $this->sentHtml());
    }

    public function test_every_notice_has_a_default_text_that_only_uses_its_own_variables(): void
    {
        foreach (Notice::cases() as $notice) {
            $text = NoticeTexts::for($notice);
            preg_match_all('/\{\{(\w+)\}\}/', $text['subject'].$text['body'], $used);

            $this->assertSame([], array_diff($used[1], array_keys($text['tags'])), $notice->value);
        }
    }

    public function test_the_default_body_as_editor_chips_renders_the_table_without_a_span_or_paragraph_around_it(): void
    {
        $body = NoticeTexts::editorBody(Notice::ExchangeDeadline);

        $this->assertStringContainsString('<span data-type="mergeTag" data-id="lista"></span>', $body);
        $this->assertStringNotContainsString('{{', $body);

        [, $html] = app(RenderNoticeEmail::class)->render(Notice::ExchangeDeadline, null, '<h3>Prazos</h3><p><span data-type="mergeTag" data-id="lista"></span></p>', ['lista' => '<table><tr><td>A1</td></tr></table>']);

        $this->assertStringContainsString('<h3>Prazos</h3><table><tr><td>A1</td></tr></table>', $html);
        $this->assertStringNotContainsString('mergeTag', $html);
    }
}

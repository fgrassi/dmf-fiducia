<?php

namespace App\Actions\Notices;

use App\Enums\Notice;
use App\Models\NoticeSetting;
use Filament\Forms\Components\RichEditor\RichContentRenderer;

/**
 * Monta assunto e HTML de um aviso: o texto editado na tela (notice_settings) ou, sem ele, o padrão de NoticeTexts, trocando as
 * {{variáveis}} pelos valores que a rotina calculou. Os valores saem escapados, menos os das variáveis marcadas como HTML (as tabelas).
 */
class RenderNoticeEmail
{
    /**
     * @param  array<string, scalar|null>  $values
     * @return array{0: string, 1: string} assunto e HTML
     */
    public function __invoke(Notice $notice, array $values): array
    {
        $setting = NoticeSetting::for($notice->value);

        return $this->render($notice, $setting?->subject, $setting?->body, $values);
    }

    /**
     * @param  array<string, scalar|null>  $values
     * @return array{0: string, 1: string}
     */
    public function render(Notice $notice, ?string $subject, ?string $body, array $values): array
    {
        $text = NoticeTexts::for($notice);
        $raw = array_map(fn ($value) => (string) $value, $values);
        $safe = [];
        $subjectValues = [];

        foreach ($raw as $tag => $value) {
            $safe['{{'.$tag.'}}'] = ($text['tags'][$tag]['html'] ?? false) ? $value : e($value);
            $subjectValues['{{'.$tag.'}}'] = $value;
        }

        $html = filled($body)
            ? RichContentRenderer::make($body)->mergeTags(array_combine(array_keys($raw), array_map(fn ($tag) => '{{'.$tag.'}}', array_keys($raw))))->toHtml()
            : $text['body'];

        // o chip do editor vira só {{variável}} (sem o <span>, que não pode envolver uma tabela)
        $html = preg_replace('#<span[^>]*data-type="mergeTag"[^>]*>(\{\{\w+\}\})</span>#', '$1', $html);

        // o editor põe a variável sozinha dentro de um <p>; tabela dentro de parágrafo é HTML inválido
        $htmlTags = array_keys(array_filter($text['tags'], fn (array $tag) => $tag['html'] ?? false));
        $html = preg_replace('#<p>\s*(\{\{(?:'.implode('|', array_map('preg_quote', $htmlTags ?: ['-'])).')\}\})\s*</p>#', '$1', $html);

        return [strtr(filled($subject) ? $subject : $text['subject'], $subjectValues), strtr($html, $safe)];
    }
}

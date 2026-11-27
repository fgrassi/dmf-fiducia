<?php

namespace App\Actions\Notices;

use App\Enums\Notice;
use App\Models\NoticeSetting;

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
        // variável que a rotina não preenche (ex.: {{reserva}} num aviso de lista) sai em branco, não como {{texto}}
        $raw = [...array_fill_keys(array_keys($text['tags']), ''), ...array_map(fn ($value) => (string) $value, $values)];
        $safe = [];
        $subjectValues = [];

        foreach ($raw as $tag => $value) {
            $safe['{{'.$tag.'}}'] = ($text['tags'][$tag]['html'] ?? false) ? $value : e($value);
            $subjectValues['{{'.$tag.'}}'] = $value;
        }

        return [strtr(filled($subject) ? $subject : $text['subject'], $subjectValues), strtr(filled($body) ? $body : $text['body'], $safe)];
    }
}

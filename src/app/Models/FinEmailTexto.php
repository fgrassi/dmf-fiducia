<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Textos do e-mail da Instrução de Cobrança: HTML com marcadores {{nome}}, localizado pelo mnemônico. */
class FinEmailTexto extends Model
{
    public const PLACEHOLDERS = [
        'bl', 'booking', 'invoice', 'vencimento_boleto', 'vencimento_boleto_frase', 'armador', 'cliente',
        'horario_comprovante', 'boleto_status', 'late_payment', 'despachante_nome', 'despachante_endereco',
    ];

    protected $table = 'fin_email_texto';

    protected $guarded = [];

    public const CREATED_AT = null;

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    /**
     * getCorpoPorMnemonico: o corpo ativo com os marcadores trocados; vazio (e registrado no log) se não existir.
     * Os valores entram como HTML: quem chama escapa o que vem da reserva.
     *
     * @param  array<string, string>  $values
     */
    public static function body(string $mnemonic, array $values = []): string
    {
        $text = self::active($mnemonic);

        if (! $text) {
            logger()->error("[FinEmailTexto] mnemônico não encontrado ou inativo: {$mnemonic}");

            return '';
        }

        return strtr((string) $text->corpo, collect($values)->mapWithKeys(fn ($value, $key) => ['{{'.$key.'}}' => $value])->all());
    }

    /** getTituloPorMnemonico. */
    public static function heading(string $mnemonic, string $fallback): string
    {
        return trim((string) self::active($mnemonic)?->titulo) ?: $fallback;
    }

    private static function active(string $mnemonic): ?self
    {
        return self::where('mnemonico', $mnemonic)->where('ativo', 1)->first();
    }
}

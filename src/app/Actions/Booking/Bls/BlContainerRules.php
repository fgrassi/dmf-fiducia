<?php

namespace App\Actions\Booking\Bls;

use App\Models\Container;
use Closure;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Validator;

/** As regras da linha de container do BL (ContainerForm e container_submit, legado), na tela e ao gravar. */
class BlContainerRules
{
    public const ATTRIBUTES = [
        'tipo_container_id' => 'Size/Type',
        'temperatura' => 'Temperatura',
        'numero' => 'Container n',
        'tara' => 'Tara',
        'seals_n1' => 'Seals',
        'qty_pkgs' => 'Qty of Packages',
        'gross_weight' => 'Gross Weight',
        'net_weight' => 'Net Weight',
        'volume' => 'Volume',
    ];

    /** @param array<string, mixed> $row */
    public static function validator(array $row, bool $export): Validator
    {
        $reefer = $export && Container::isReefer($row['tipo_container_id'] ?? null);

        return ValidatorFactory::make($row, [
            'tipo_container_id' => ['required'],
            'temperatura' => $reefer ? ['required', 'integer', 'between:-25,25'] : ['nullable'],
            'numero' => ['required'],
            'tara' => ['required'],
            'seals_n1' => ['required'],
            'qty_pkgs' => ['required'],
            'gross_weight' => ['required', self::decimal('Gross Weight')],
            'net_weight' => ['required', self::positive($row)],
            'volume' => ['required', self::decimal('Volume')],
        ], ['required' => 'Preencha o campo ":attribute"', 'temperatura.required' => 'Preencha o campo "Temperatura" para container REEFER.'], self::ATTRIBUTES);
    }

    /** Peso bruto e volume: só números, com vírgula e sem separador de milhar. */
    private static function decimal(string $label): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($label): void {
            $value = (string) $value;

            if ($value === '') {
                return;
            }

            if (! is_numeric(preg_replace('/,/', '.', $value, 1))) {
                $fail("O campo \"{$label}\" aceita apenas números.");
            } elseif (($dot = strpos($value, '.')) !== false && $dot > 0) {
                $fail("Utilize apenas vírgulas e nenhum separador de milhar no campo \"{$label}\"");
            }
        };
    }

    /** Volume, peso bruto e peso líquido têm que ser maiores que zero. */
    private static function positive(array $row): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($row): void {
            if (self::number($row['volume'] ?? null) <= 0 || self::number($row['gross_weight'] ?? null) <= 0 || self::number($value) <= 0) {
                $fail('É obrigatório informar algum valor de Peso Bruto, Peso Liquido e Volume!');
            }
        };
    }

    /** "1.234,5" => 1234.5 (só o primeiro ponto sai, como no legado). */
    public static function number(mixed $raw): float
    {
        return (float) str_replace(',', '.', preg_replace('/\./', '', (string) $raw, 1));
    }
}

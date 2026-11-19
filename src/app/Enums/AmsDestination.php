<?php

namespace App\Enums;

use App\Models\AmsAfrica;
use App\Models\AmsRegistration;
use App\Models\AmsUae;

/** Destinos que exigem o registro prévio do embarque (AMS) no agente: África do Sul e Emirados Árabes (abas do booking/amsAfrica e booking/amsUae do legado). */
enum AmsDestination: string
{
    case Africa = 'africa';
    case Uae = 'uae';

    /** @return class-string<AmsRegistration> */
    public function model(): string
    {
        return $this === self::Africa ? AmsAfrica::class : AmsUae::class;
    }

    /** Relação da reserva com os registros. */
    public function relation(): string
    {
        return $this === self::Africa ? 'amsAfrica' : 'amsUae';
    }

    /** Coluna da reserva: 1 pendente de envio ao agente, 2 enviado e pendente de confirmação, 3 confirmado. */
    public function statusColumn(): string
    {
        return $this === self::Africa ? 'status_ams_africa' : 'status_ams_uae';
    }

    /** Aba aparece quando o país do porto de destino da reserva é este (paises_id). */
    public function country(): int
    {
        return $this === self::Africa ? 25 : 5;
    }

    /** Pasta do arquivo de confirmação em uploads. */
    public function directory(): string
    {
        return $this === self::Africa ? 'ams_africa' : 'ams_uae';
    }

    public function title(): string
    {
        return $this === self::Africa ? 'África do Sul' : 'Emirados Árabes';
    }

    /** Destino no assunto do e-mail ao agente. */
    public function destinationName(): string
    {
        return $this === self::Africa ? 'South Africa' : 'United Arab Emirates';
    }
}

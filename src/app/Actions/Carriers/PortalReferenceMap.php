<?php

namespace App\Actions\Carriers;

/** IntegracaoPortalReferenceTypes (legado): de-para entre o parâmetro do Portal Integrador e o campo da reserva enviado na consulta. */
class PortalReferenceMap
{
    public const BOOKING = 'carrierBookingReference';

    public const CONTAINER = 'equipmentReference';

    public const BL = 'transportDocumentReference';

    public const SOURCE_BOOKING = 'reserva.booking';

    public const SOURCE_CONTAINER = 'bl.equipamento.nr_container';

    /** @return array<string, string> */
    public static function parameters(): array
    {
        return [
            self::BOOKING => 'Booking armador',
            self::CONTAINER => 'Container',
            self::BL => 'BL / conhecimento',
        ];
    }

    /** @return array<string, string> */
    public static function sources(): array
    {
        return [
            self::SOURCE_BOOKING => 'reserva.booking',
            'bl.mbl' => 'bl.mbl',
            'bl.hbl' => 'bl.hbl',
            self::SOURCE_CONTAINER => 'bl.equipamento.nr_container',
        ];
    }

    /**
     * Coluna vazia ou inválida vira o padrão; a lista antiga de parâmetros é convertida para o mapa.
     *
     * @return array<string, string>
     */
    public static function decode(?string $json): array
    {
        $decoded = json_decode((string) $json, true);

        if (! is_array($decoded)) {
            return self::default();
        }

        if (array_is_list($decoded)) {
            $decoded = array_intersect_key([
                self::BOOKING => self::SOURCE_BOOKING,
                self::CONTAINER => self::SOURCE_CONTAINER,
                self::BL => 'bl.mbl',
            ], array_flip($decoded));
        }

        return self::normalize($decoded);
    }

    /** @param  array<string, mixed>  $map */
    public static function encode(array $map): string
    {
        return json_encode(self::normalize($map));
    }

    /** @param  array<string, string>  $map */
    public static function describe(array $map): string
    {
        return collect($map)
            ->map(fn (string $source, string $parameter) => (self::parameters()[$parameter] ?? $parameter).' ← '.(self::sources()[$source] ?? $source))
            ->implode(' · ');
    }

    /**
     * Mantém só parâmetro e origem conhecidos; sem nenhum válido, volta ao padrão (booking ← reserva.booking).
     *
     * @param  array<string, mixed>  $map
     * @return array<string, string>
     */
    private static function normalize(array $map): array
    {
        $valid = [];

        foreach (array_keys(self::parameters()) as $parameter) {
            $source = trim((string) ($map[$parameter] ?? ''));

            if (array_key_exists($source, self::sources())) {
                $valid[$parameter] = $source;
            }
        }

        return $valid ?: self::default();
    }

    /** @return array<string, string> */
    private static function default(): array
    {
        return [self::BOOKING => self::SOURCE_BOOKING];
    }
}

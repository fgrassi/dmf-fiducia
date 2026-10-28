<?php

namespace Tests\Feature\Actions\Carriers;

use App\Actions\Carriers\PortalReferenceMap;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PortalReferenceMapTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: array<string, string>}> */
    public static function storedColumns(): array
    {
        $default = ['carrierBookingReference' => 'reserva.booking'];

        return [
            'null column' => [null, $default],
            'empty column' => ['', $default],
            'invalid json' => ['{oops', $default],
            'unknown parameter and source are dropped' => ['{"foo":"bar","equipmentReference":"x.y"}', $default],
            'legacy parameter list is converted to sources' => [
                '["carrierBookingReference","equipmentReference"]',
                ['carrierBookingReference' => 'reserva.booking', 'equipmentReference' => 'bl.equipamento.nr_container'],
            ],
            'stored map is kept' => [
                '{"carrierBookingReference":"bl.mbl","equipmentReference":"bl.equipamento.nr_container"}',
                ['carrierBookingReference' => 'bl.mbl', 'equipmentReference' => 'bl.equipamento.nr_container'],
            ],
        ];
    }

    /** @param  array<string, string>  $expected */
    #[DataProvider('storedColumns')]
    public function test_decodes_stored_column_into_valid_map(?string $stored, array $expected): void
    {
        $this->assertSame($expected, PortalReferenceMap::decode($stored));
    }

    public function test_encodes_only_valid_entries_and_falls_back_to_default_when_none_is_left(): void
    {
        $this->assertSame(
            '{"transportDocumentReference":"bl.hbl"}',
            PortalReferenceMap::encode(['transportDocumentReference' => 'bl.hbl', 'equipmentReference' => null]),
        );
        $this->assertSame('{"carrierBookingReference":"reserva.booking"}', PortalReferenceMap::encode([]));
    }

    public function test_describes_map_with_parameter_and_source_labels(): void
    {
        $map = ['carrierBookingReference' => 'bl.mbl', 'equipmentReference' => 'bl.equipamento.nr_container'];

        $this->assertSame(
            'Booking armador ← bl.mbl · Container ← bl.equipamento.nr_container',
            PortalReferenceMap::describe($map),
        );
    }
}

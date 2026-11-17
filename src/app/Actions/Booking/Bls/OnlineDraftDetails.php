<?php

namespace App\Actions\Booking\Bls;

use Illuminate\Support\Facades\DB;

/** visualizarDraftOnline (legado): o draft online completo, com equipamentos e totais, para conferir antes de importar. */
class OnlineDraftDetails
{
    private const WOOD_PACKING = [
        '1' => 'Not Applicable (Not Used)',
        '2' => 'Used : Processed',
        '3' => 'Used : Treated / Certified',
        '4' => 'Used : Not Treated / Not Certified',
    ];

    private const INSTRUCTION_TYPE = ['1' => 'EXPRESS RELEASE', '3' => 'ORIGINAIS NA ORIGEM'];

    private const FREIGHT = ['pp' => 'PREPAID', 'cc' => 'COLLECT', 'pa' => 'ABROAD'];

    /** @return ?array{draft: array<string, mixed>, equipments: list<array<string, mixed>>, totals: array<string, float|int>} */
    public function __invoke(int $draftId): ?array
    {
        $draft = DB::table('bl_online')->where('bl_online_id', $draftId)->first();

        if (! $draft) {
            return null;
        }

        $date = fn (?string $value): string => $value ? date('d/m/Y H:i', strtotime($value)) : '';
        $equipments = DB::table('bl_online_equipments')->where('bl_id', $draftId)->orderBy('id')->get();
        $types = DB::table('tipo_container')->whereIn('tipo_container_id', $equipments->pluck('equipment_id')->filter()->unique())->pluck('nome', 'tipo_container_id');

        return [
            'draft' => [
                'protocolo' => $draft->protocolo,
                'booking' => (string) DB::table('embarque')->where('embarque_id', $draft->embarque_id)->value('booking'),
                'send_at' => $date($draft->send_at),
                'shipper' => $draft->shipper,
                'consignee' => $draft->consignee,
                'notify' => $draft->notify,
                'also_notify' => $draft->also_notify,
                'vessel' => $draft->vessel,
                'port_lading' => $draft->port_lading,
                'port_disc' => $draft->port_disc,
                'place_delivery' => $draft->place_delivery,
                'final_destination' => $draft->final_destination,
                'mark_nos' => $draft->mark_nos,
                'desc_pkgs' => $draft->desc_pkgs,
                'tipo_madeira' => self::WOOD_PACKING[(string) $draft->tipo_madeira] ?? (string) $draft->tipo_madeira,
                'instruction_type' => self::INSTRUCTION_TYPE[(string) $draft->instruction_type] ?? (string) $draft->instruction_type,
                'freight_at' => self::FREIGHT[$draft->freight_at] ?? $draft->freight_at,
                'per_agreement' => match ($draft->per_agreement) {
                    's' => 'SIM',
                    'n' => 'NÃO',
                    default => $draft->per_agreement,
                },
                'observacao' => $draft->observacao,
                'created_at' => $date($draft->created_at),
            ],
            'equipments' => $equipments->map(fn (object $e): array => [
                'type' => $types[$e->equipment_id] ?? '',
                'number' => $e->container_number,
                'tare' => $e->tare,
                'seals' => collect([$e->seal_1, $e->seal_2, $e->seal_3])->filter()->implode(', '),
                'quantity' => (int) $e->quantity,
                'gross_weight' => (float) $e->gross_weight,
                'net_weight' => (float) $e->net_weight,
                'cubic_meters' => (float) $e->cubic_meters,
                'vgm_number' => $e->vgm_number,
                'vgm_weight' => (float) $e->vgm_weight,
                'method' => $e->method,
            ])->all(),
            'totals' => [
                'gross_weight' => $equipments->sum(fn (object $e) => (float) $e->gross_weight),
                'net_weight' => $equipments->sum(fn (object $e) => (float) $e->net_weight),
                'cubic_meters' => $equipments->sum(fn (object $e) => (float) $e->cubic_meters),
                'quantity' => $equipments->sum(fn (object $e) => (int) $e->quantity),
                'vgm_weight' => $equipments->sum(fn (object $e) => (float) $e->vgm_weight),
            ],
        ];
    }
}

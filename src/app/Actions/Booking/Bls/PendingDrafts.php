<?php

namespace App\Actions\Booking\Bls;

use App\Models\Embarque;
use Illuminate\Support\Facades\DB;

/**
 * listarDraftOnlinePendentes (legado): os drafts online da reserva que ainda não viraram BL, com o número do grupo
 * (rascunho original e suas revisões) e a versão, mais o último token pedido ao cliente.
 */
class PendingDrafts
{
    /** @return array{drafts: list<array<string, mixed>>, token: ?array<string, mixed>} */
    public function __invoke(Embarque $booking): array
    {
        return ['drafts' => $this->drafts($booking), 'token' => $this->token($booking)];
    }

    /** @return list<array<string, mixed>> */
    private function drafts(Embarque $booking): array
    {
        $all = DB::table('bl_online')->where('embarque_id', $booking->getKey())->orderBy('created_at')->orderBy('bl_online_id')->get(['bl_online_id', 'draft_id', 'parent_draft_id']);
        $parents = $all->mapWithKeys(fn (object $row) => [(int) $row->draft_id => $row->parent_draft_id ? (int) $row->parent_draft_id : null])->all();

        $groups = [];
        $byDraft = [];

        foreach ($all as $row) {
            $root = $this->root((int) $row->draft_id, $parents) ?? (int) $row->draft_id;
            $groups[$root] ??= count($groups) + 1;
            $byDraft[(int) $row->bl_online_id] = $groups[$root];
        }

        return DB::table('bl_online as o')
            ->leftJoin('bl as b', 'b.bl_online_id', '=', 'o.bl_online_id')
            ->where('o.embarque_id', $booking->getKey())
            ->whereNull('b.bl_id')
            ->orderByDesc('o.updated_at')
            ->orderByDesc('o.created_at')
            ->get(['o.bl_online_id', 'o.version_number', 'o.parent_draft_id', 'o.created_at'])
            ->map(fn (object $row): array => [
                'bl_online_id' => (int) $row->bl_online_id,
                'group' => $byDraft[(int) $row->bl_online_id] ?? 1,
                'version' => (int) $row->version_number ?: 1,
                'created_at' => $row->created_at ? date('d/m/Y H:i', strtotime($row->created_at)) : '',
            ])
            ->all();
    }

    /** findRootParent: a raiz da cadeia de revisões, sem recursão; ciclo devolve null. */
    private function root(int $draftId, array $parents): ?int
    {
        $visited = [];

        while (! empty($parents[$draftId])) {
            if (isset($visited[$draftId])) {
                return null;
            }

            $visited[$draftId] = true;
            $draftId = $parents[$draftId];
        }

        return $draftId;
    }

    /** @return ?array<string, mixed> */
    private function token(Embarque $booking): ?array
    {
        $token = DB::table('bl_online_tokens')->where('booking_id', $booking->getKey())->orderByDesc('created_at')->first();

        if (! $token) {
            return null;
        }

        $date = fn (?string $value): string => $value ? date('d/m/Y H:i', strtotime($value)) : '';

        return [
            'url' => $token->url,
            'created_at' => $date($token->created_at),
            'expires_at' => $date($token->expires_at),
            'email_sent_to' => $token->email_enviado_para,
            'email_sent_at' => $date($token->email_enviado),
            'status' => (int) $token->status,
        ];
    }
}

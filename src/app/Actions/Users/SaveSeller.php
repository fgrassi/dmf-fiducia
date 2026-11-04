<?php

namespace App\Actions\Users;

use App\Models\Vendedor;
use Illuminate\Support\Facades\DB;

/**
 * vendedor/update (legado): grava o vendedor e os usuários vinculados (cmn_vendedor_usuario, com exclusão lógica).
 *
 * Diferença do legado: o formulário do legado só acrescenta vínculos; aqui a seleção é sincronizada, e tirar um
 * usuário da lista marca o vínculo como excluído (e escolher de novo o reativa).
 */
class SaveSeller
{
    /** @param  array<string, mixed>  $data  colunas de vendedor + usuarios (usuario_id) */
    public function __invoke(array $data, int $userId, ?Vendedor $seller = null): Vendedor
    {
        $wanted = array_map('intval', $data['usuarios'] ?? []);
        unset($data['usuarios']);

        return DB::transaction(function () use ($data, $userId, $seller, $wanted) {
            $seller ??= new Vendedor;
            $seller->forceFill($data)->save();
            $id = $seller->getKey();

            $links = DB::table('cmn_vendedor_usuario')->where('ref_vendedor', $id)->get()->groupBy('ref_usuario');

            foreach ($links as $linkedUserId => $rows) {
                $isActive = $rows->contains(fn ($row) => $row->deleted_at === null);

                if (! $isActive && in_array($linkedUserId, $wanted, true)) {
                    DB::table('cmn_vendedor_usuario')->where('id', $rows->last()->id)->update(['deleted_at' => null, 'deleted_by' => null, 'created_by' => $userId]);
                } elseif ($isActive && ! in_array($linkedUserId, $wanted, true)) {
                    DB::table('cmn_vendedor_usuario')->where('ref_vendedor', $id)->where('ref_usuario', $linkedUserId)->whereNull('deleted_at')
                        ->update(['deleted_at' => now(), 'deleted_by' => $userId]);
                }
            }

            DB::table('cmn_vendedor_usuario')->insert(
                collect($wanted)->diff($links->keys())->map(fn ($linkedUserId) => ['ref_usuario' => $linkedUserId, 'ref_vendedor' => $id, 'created_by' => $userId])->values()->all()
            );

            return $seller;
        });
    }
}

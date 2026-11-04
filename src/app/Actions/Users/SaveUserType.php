<?php

namespace App\Actions\Users;

use App\Models\TipoUsuario;
use Illuminate\Support\Facades\DB;

/**
 * tipoUsuario/update (legado): grava o tipo e as empresas × aplicações a que ele dá acesso.
 *
 * Diferença do legado: os vínculos são sincronizados em vez de apagados e recriados, para preservar as permissões
 * por item (item_aplicacao) já ajustadas; só os vínculos novos recebem os itens padrão (1, 3 e 4 com gravação).
 */
class SaveUserType
{
    /** Itens da aplicação (menus da intranet) liberados em todo vínculo novo. */
    private const DEFAULT_ITEMS = [1, 3, 4];

    /** @param  array<string, mixed>  $data  nome e aplicacoes (pessoa_aplicacao_id) */
    public function __invoke(array $data, ?TipoUsuario $type = null): TipoUsuario
    {
        $wanted = array_map('intval', $data['aplicacoes'] ?? []);
        unset($data['aplicacoes']);

        return DB::transaction(function () use ($data, $type, $wanted) {
            $type ??= new TipoUsuario;
            $type->forceFill($data)->save();
            $id = $type->getKey();

            $current = DB::table('pessoa_aplicacao_tipo_usuario')->where('tipo_usuario_id', $id)->pluck('pessoa_apli_tipo_usu_id', 'pessoa_aplicacao_id');

            $removed = $current->except($wanted)->values();
            DB::table('item_aplicacao')->whereIn('pessoa_apli_tipo_usu_id', $removed)->delete();
            DB::table('pessoa_aplicacao_tipo_usuario')->whereIn('pessoa_apli_tipo_usu_id', $removed)->delete();

            foreach (array_diff($wanted, $current->keys()->all()) as $companyApplicationId) {
                $linkId = DB::table('pessoa_aplicacao_tipo_usuario')->insertGetId(['tipo_usuario_id' => $id, 'pessoa_aplicacao_id' => $companyApplicationId]);
                DB::table('item_aplicacao')->insert(array_map(fn ($item) => ['pessoa_apli_tipo_usu_id' => $linkId, 'item_id' => $item, 'gravacao' => 1], self::DEFAULT_ITEMS));
            }

            return $type;
        });
    }
}

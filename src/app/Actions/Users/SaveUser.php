<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * usuario/update (legado): grava o usuário, a senha (bcrypt; em branco mantém a atual), as credenciais, as categorias de
 * frases do tracking e os vínculos empresa × cargo × tipo de usuário, numa transação.
 *
 * Diferença do legado: os vínculos (pessoa_usuario) são sincronizados em vez de apagados e recriados, para o
 * pessoa_usuario_id (usado em justificativa_contato_pessoa e nos contatos da empresa) não mudar a cada gravação.
 */
class SaveUser
{
    /** @param  array<string, mixed>  $data  colunas de usuario + credentials, categorias e vinculos (pessoa_id, cargos_id, tipo_usuario_id) */
    public function __invoke(array $data, ?User $user = null): User
    {
        $credentials = $data['credentials'] ?? [];
        $categories = $data['categorias'] ?? [];
        $links = $data['vinculos'] ?? [];
        unset($data['credentials'], $data['categorias'], $data['vinculos']);

        if (blank($data['senha'] ?? null)) {
            unset($data['senha']);
        } else {
            $data['senha'] = Hash::make($data['senha']);
        }

        return DB::transaction(function () use ($data, $user, $credentials, $categories, $links) {
            $user ??= new User;
            $user->forceFill($data + ['credentials' => $credentials === [] ? null : implode(',', $credentials)])->save();
            $id = $user->getKey();

            DB::table('usuario_frase_categoria')->where('ref_usuario', $id)->delete();
            DB::table('usuario_frase_categoria')->insert(array_map(fn ($category) => ['ref_usuario' => $id, 'ref_categoria_frase' => $category], $categories));

            $this->syncLinks($id, $links);

            return $user;
        });
    }

    /** @param  list<array{pessoa_id: int|string, cargos_id: int|string|null, tipo_usuario_id: int|string|null}>  $rows */
    private function syncLinks(int $userId, array $rows): void
    {
        $wanted = collect($rows)->groupBy(fn ($row) => $row['pessoa_id'].'|'.($row['cargos_id'] ?? ''));
        $kept = [];

        foreach ($wanted as $group) {
            $companyId = $group->first()['pessoa_id'];
            $roleId = $group->first()['cargos_id'] ?: null;

            $linkId = DB::table('pessoa_usuario')
                ->where('usuario_id', $userId)
                ->where('pessoa_id', $companyId)
                ->where('cargos_id', $roleId)
                ->value('pessoa_usuario_id')
                ?? DB::table('pessoa_usuario')->insertGetId(['usuario_id' => $userId, 'pessoa_id' => $companyId, 'cargos_id' => $roleId]);

            $kept[] = $linkId;

            DB::table('pessoa_usuario_tipo_usuario')->where('pessoa_usuario_id', $linkId)->delete();
            DB::table('pessoa_usuario_tipo_usuario')->insert(
                $group->pluck('tipo_usuario_id')->filter()->unique()->map(fn ($type) => ['pessoa_usuario_id' => $linkId, 'tipo_usuario_id' => $type])->all()
            );
        }

        $removed = DB::table('pessoa_usuario')->where('usuario_id', $userId)->whereNotIn('pessoa_usuario_id', $kept)->pluck('pessoa_usuario_id');
        DB::table('pessoa_usuario_tipo_usuario')->whereIn('pessoa_usuario_id', $removed)->delete();
        DB::table('pessoa_usuario')->whereIn('pessoa_usuario_id', $removed)->delete();
    }
}

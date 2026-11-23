<?php

namespace App\Actions\Followup;

use App\Models\CmnClientFup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * followup/list e followupExpo/list (legado): os follow ups de cada área. Admin e gerente veem todos; os demais, só os próprios.
 * Na importação entram os de usuários da importação que não são vendedores; na exportação, esses da exportação mais os gerados
 * ao finalizar uma cotação.
 */
class FollowUpsQuery
{
    public const IMPORT = 'import';

    public const EXPORT = 'export';

    /** @return Builder<CmnClientFup> */
    public function __invoke(string $area, User $user): Builder
    {
        $fromTeam = fn (Builder $query, string $credential) => $query->whereHas('usuario', fn (Builder $u) => $u
            ->where('credentials', 'like', "%{$credential}%")
            ->whereNotIn('usuario_id', DB::table('cmn_vendedor_usuario')->select('ref_usuario')));

        $query = CmnClientFup::query()->with(['pessoa.cidade', 'usuario'])->orderByDesc('id');

        if ($area === self::EXPORT) {
            $query->where('tipo', 'e')->where(fn (Builder $q) => $q
                ->where('descr', 'like', CmnClientFup::QUOTATION_FINISHED_LIKE)
                ->orWhere(fn (Builder $team) => $fromTeam($team, 'export')));
        } else {
            $fromTeam($query, 'import');
            $query->where('tipo', '<>', 'e');
        }

        return $user->hasLegacyCredential('manager') ? $query : $query->where('ref_user', $user->getKey());
    }
}

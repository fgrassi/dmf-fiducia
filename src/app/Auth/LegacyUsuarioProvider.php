<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;

/**
 * Paridade com UsuarioTable::retrieveByLogin no tracking:
 * só considera usuários com status > 0 (exclui aguardando e deletados).
 */
class LegacyUsuarioProvider extends EloquentUserProvider
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?UserContract
    {
        $credentials['__legacy_active'] = function ($query): void {
            $query->activeForLogin();
        };

        return parent::retrieveByCredentials($credentials);
    }
}

<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/** Usuários legados da DMF (tabela `usuario`, compartilhada com tracking e CRM). */
class User extends Authenticatable implements FilamentUser, HasName
{
    use Notifiable;

    protected $table = 'usuario';

    protected $primaryKey = 'usuario_id';

    public $timestamps = false;

    protected $hidden = ['senha'];

    public function getAuthIdentifierName(): string
    {
        return 'usuario_id';
    }

    public function getAuthPassword(): string
    {
        return (string) $this->senha;
    }

    public function getAuthPasswordName(): string
    {
        return 'senha';
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return (int) $this->status > 0
            && (int) $this->ctr_bloqueio < 3
            && filled($this->login)
            && filled($this->senha);
    }

    /** Paridade com UsuarioTable::retrieveByLogin (tracking): status > 0. */
    #[Scope]
    protected function activeForLogin(Builder $query): void
    {
        $query->where($this->qualifyColumn('status'), '>', 0);
    }

    public function getFilamentName(): string
    {
        return (string) ($this->nome ?: $this->login);
    }

    /** @return list<string> */
    public function credentialList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->credentials))));
    }

    public function hasLegacyCredential(string|array $credentials): bool
    {
        $owned = $this->credentialList();

        return in_array('admin', $owned, true)
            || array_intersect((array) $credentials, $owned) !== [];
    }
}

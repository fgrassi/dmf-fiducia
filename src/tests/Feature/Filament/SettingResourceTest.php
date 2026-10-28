<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Settings\Pages\ManageSettings;
use App\Models\Configuracoes;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class SettingResourceTest extends TestCase
{
    use LegacySchema;

    public function test_creates_setting(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(ManageSettings::class)
            ->callAction('create', ['identificacao' => 'dias_aviso', 'valor' => '3', 'descricao' => 'Dias de aviso'])
            ->assertHasNoFormErrors();

        $this->assertSame('3', Configuracoes::sole()->valor);
    }

    public function test_requires_admin_or_financial_credential(): void
    {
        $this->actingAs($this->user('booking'));

        Livewire::test(ManageSettings::class)->assertForbidden();
    }

    private function user(string $credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}

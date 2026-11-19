<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PhraseCategories\Pages\ManagePhraseCategories;
use App\Filament\Resources\Phrases\Pages\ManagePhrases;
use App\Models\FraseCategoria;
use App\Models\FraseTransborda;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

/** Frases e categorias do rastreamento (fraseTransborda e fraseCategoria do legado). */
class PhraseResourceTest extends TestCase
{
    use LegacySchema;

    public function test_phrases_and_categories_are_managed_and_a_category_in_use_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('auxiliares'));

        Livewire::test(ManagePhraseCategories::class)
            ->callAction('create', ['nome' => 'Importação'])
            ->assertHasNoFormErrors();
        $category = FraseCategoria::sole();

        Livewire::test(ManagePhrases::class)
            ->callAction('create', ['valor' => 'Navio atracou', 'frase' => 'O navio atracou em {porto}.', 'categoria_id' => $category->getKey()])
            ->assertHasNoFormErrors();
        $phrase = FraseTransborda::sole();
        $this->assertSame('Importação', $phrase->category->nome);

        Livewire::test(ManagePhrases::class)
            ->callTableAction('edit', $phrase, ['valor' => 'Atracou'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('Atracou', $phrase->fresh()->valor);

        Livewire::test(ManagePhraseCategories::class)->assertTableActionHidden(DeleteAction::class, $category);

        Livewire::test(ManagePhrases::class)->callTableAction(DeleteAction::class, $phrase);
        Livewire::test(ManagePhraseCategories::class)->callTableAction(DeleteAction::class, $category);
        $this->assertNull($category->fresh());
    }

    private function user(string $credentials, string $login = 'ana'): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => $login, 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }
}

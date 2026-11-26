<?php

namespace Tests\Feature\Actions\NfseRules;

use App\Actions\NfseRules\SaveNfseRule;
use App\Models\FinClientsNfseRegra;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class SaveNfseRuleTest extends TestCase
{
    use LegacySchema;

    public function test_creates_the_rule_of_a_client_without_one(): void
    {
        $rule = app(SaveNfseRule::class)($this->data(['separar_nf_grupo_despesas' => true, 'expense_rules' => [7]]), 5);

        $saved = FinClientsNfseRegra::sole();
        $this->assertSame($rule->getKey(), $saved->getKey());
        $this->assertSame([100, 'cliente', 5, 5], [$saved->ref_client, $saved->tomador, $saved->created_by, $saved->updated_by]);
        $this->assertSame([7], DB::table('fin_clients_nfse_regra_despesa')->pluck('regra_despesa_cobranca_id')->all());
    }

    public function test_saving_again_for_the_same_client_updates_the_rule(): void
    {
        app(SaveNfseRule::class)($this->data(['separar_nf_grupo_despesas' => true, 'expense_rules' => [7]]), 5);
        app(SaveNfseRule::class)($this->data(['justificativa' => 'nova justificativa']), 6);

        $saved = FinClientsNfseRegra::sole();
        $this->assertSame(['nova justificativa', 5, 6], [$saved->justificativa, $saved->created_by, $saved->updated_by]);
        $this->assertSame(0, DB::table('fin_clients_nfse_regra_despesa')->count());
    }

    /** @return array<string, mixed> */
    private function data(array $overrides = []): array
    {
        return [...['ref_client' => 100, 'tomador' => 'cliente', 'justificativa' => 'teste'], ...$overrides];
    }
}

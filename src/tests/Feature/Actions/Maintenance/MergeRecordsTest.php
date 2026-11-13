<?php

namespace Tests\Feature\Actions\Maintenance;

use App\Actions\Maintenance\MergeRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class MergeRecordsTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        // tabela do app Fiducia: não está no fixture do legado, mas tem FK para pessoa no banco real
        Schema::create('fiducia_contratos', fn ($table) => $table->unsignedBigInteger('cliente_id'));
    }

    public function test_every_mapped_column_exists_in_the_legacy_schema(): void
    {
        foreach (MergeRecords::ENTITIES as $entity => $config) {
            foreach ($config['references'] as $table => $columns) {
                foreach ($columns as $column) {
                    $this->assertTrue(Schema::hasColumn($table, $column), "{$entity}: {$table}.{$column}");
                }
            }
        }
    }

    public function test_ships_are_moved_to_the_target_and_sources_deleted(): void
    {
        [$a, $b, $c] = [DB::table('navio')->insertGetId(['nome' => 'MSC A']), DB::table('navio')->insertGetId(['nome' => 'MSC A ']), DB::table('navio')->insertGetId(['nome' => 'Outro'])];
        $booking = DB::table('embarque')->insertGetId(['navio_id' => $b, 'navio_id_destino' => $b]);
        $other = DB::table('embarque')->insertGetId(['navio_id' => $c]);

        $merged = app(MergeRecords::class)('ship', [$b], $a);

        $this->assertSame(1, $merged);
        $this->assertEquals([$a, $a], [DB::table('embarque')->where('embarque_id', $booking)->first()->navio_id, DB::table('embarque')->where('embarque_id', $booking)->first()->navio_id_destino]);
        $this->assertSame($c, (int) DB::table('embarque')->where('embarque_id', $other)->first()->navio_id);
        $this->assertSame([$a, $c], DB::table('navio')->orderBy('navio_id')->pluck('navio_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_duplicate_link_on_the_target_is_dropped_instead_of_failing(): void
    {
        DB::statement('CREATE UNIQUE INDEX pessoa_armador_unico ON pessoa_armador (pessoa_id, armador_id)');
        [$a, $b] = [DB::table('armador')->insertGetId(['nome' => 'Hapag']), DB::table('armador')->insertGetId(['nome' => 'Hapag-Lloyd'])];
        $client = DB::table('pessoa')->insertGetId(['nome' => 'Cliente']);
        $solo = DB::table('pessoa')->insertGetId(['nome' => 'Outro cliente']);
        DB::table('pessoa_armador')->insert([['pessoa_id' => $client, 'armador_id' => $a], ['pessoa_id' => $client, 'armador_id' => $b], ['pessoa_id' => $solo, 'armador_id' => $b]]);

        app(MergeRecords::class)('carrier', [$b], $a);

        $this->assertSame(2, DB::table('pessoa_armador')->where('armador_id', $a)->count());
        $this->assertSame(0, DB::table('pessoa_armador')->where('armador_id', $b)->count());
        $this->assertNull(DB::table('armador')->where('armador_id', $b)->first());
    }

    public function test_locations_are_merged_down_the_chain(): void
    {
        [$sp, $spDup] = [DB::table('estados')->insertGetId(['nome' => 'SP']), DB::table('estados')->insertGetId(['nome' => 'Sao Paulo'])];
        $city = DB::table('cidades')->insertGetId(['estados_id' => $spDup, 'nome' => 'Santos']);
        $port = DB::table('porto')->insertGetId(['nome' => 'Santos', 'cidades_id' => $city, 'ref_estado' => $spDup]);

        app(MergeRecords::class)('state', [$spDup], $sp);

        $this->assertSame($sp, (int) DB::table('cidades')->where('cidades_id', $city)->value('estados_id'));
        $this->assertSame($sp, (int) DB::table('porto')->where('porto_id', $port)->value('ref_estado'));
        $this->assertNull(DB::table('estados')->where('estados_id', $spDup)->first());
    }

    public function test_companies_are_merged_and_target_is_never_deleted(): void
    {
        [$a, $b] = [DB::table('pessoa')->insertGetId(['nome' => 'ACME']), DB::table('pessoa')->insertGetId(['nome' => 'ACME Ltda'])];
        $booking = DB::table('embarque')->insertGetId(['pessoa_id_cliente' => $b, 'pessoa_id_notify' => $b]);

        $this->assertSame(0, app(MergeRecords::class)('company', [$a], $a));
        app(MergeRecords::class)('company', [$a, $b], $a);

        $this->assertSame($a, (int) DB::table('embarque')->where('embarque_id', $booking)->first()->pessoa_id_cliente);
        $this->assertSame($a, (int) DB::table('embarque')->where('embarque_id', $booking)->first()->pessoa_id_notify);
        $this->assertNull(DB::table('pessoa')->where('pessoa_id', $b)->first());
        $this->assertNotNull(DB::table('pessoa')->where('pessoa_id', $a)->first());
    }
}

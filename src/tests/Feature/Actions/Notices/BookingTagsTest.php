<?php

namespace Tests\Feature\Actions\Notices;

use App\Actions\Cobranca\Notices\MblReleaseNotice;
use App\Actions\Notices\BookingTags;
use App\Actions\Notices\NoticeTexts;
use App\Actions\Notices\UpdateNoticeSetting;
use App\Enums\Notice;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BookingTagsTest extends TestCase
{
    use LegacySchema;

    private function seedBooking(): void
    {
        DB::table('pessoa')->insert([
            ['pessoa_id' => 1, 'nome' => 'Exportador SA'],
            ['pessoa_id' => 2, 'nome' => 'Importador GmbH'],
            ['pessoa_id' => 3, 'nome' => 'Agente Hamburgo'],
        ]);
        DB::table('armador')->insert(['armador_id' => 7, 'nome' => 'MAERSK']);
        DB::table('paises')->insert(['paises_id' => 1, 'nome' => 'Alemanha']);
        DB::table('porto')->insert([['porto_id' => 4, 'nome' => 'Santos', 'ref_pais' => null], ['porto_id' => 5, 'nome' => 'Hamburgo', 'ref_pais' => 1]]);
        DB::table('embarque')->insert([
            'embarque_id' => 10, 'booking' => 'BKG10', 'master_bl' => 'MBL10', 'house_bl' => 'HBL10', 'tipo_embarque' => 'm', 'impor_expor' => 'e', 'finalizado' => 'a',
            'cliente_id' => 1, 'pessoa_id_importador' => 2, 'pessoa_id_representante' => 3, 'armador_id' => 7, 'porto_origem_id' => 4, 'porto_destino_id' => 5,
            'saida_prevista' => '2026-11-20', 'chegada_prevista' => '2026-12-20',
        ]);
    }

    public function test_it_resolves_the_booking_variables(): void
    {
        $this->seedBooking();

        $this->assertSame([
            'reserva' => 'BKG10', 'mbl' => 'MBL10', 'hbl' => 'HBL10', 'shipper' => 'Exportador SA', 'importador' => 'Importador GmbH', 'agente' => 'Agente Hamburgo',
            'armador' => 'MAERSK', 'porto_origem' => 'Santos', 'porto_destino' => 'Hamburgo', 'data_saida' => '20/11/2026', 'data_chegada' => '20/12/2026',
        ], app(BookingTags::class)(10));
    }

    public function test_a_missing_booking_gives_empty_values_and_a_missing_date_stays_blank(): void
    {
        $values = app(BookingTags::class)(999);

        $this->assertSame(array_keys(BookingTags::TAGS), array_keys($values));
        $this->assertSame([''], array_values(array_unique($values)));
    }

    public function test_only_the_notices_about_one_booking_offer_the_booking_variables(): void
    {
        foreach ([Notice::OpenCharges, Notice::MblRelease] as $notice) {
            $this->assertSame([], array_diff(array_keys(BookingTags::TAGS), array_keys(NoticeTexts::for($notice)['tags'])), $notice->value);
        }

        foreach ([Notice::ExchangeDeadline, Notice::OpenCommissions, Notice::ReleaseList] as $notice) {
            $this->assertArrayNotHasKey('agente', NoticeTexts::for($notice)['tags'], $notice->value);
        }
    }

    public function test_a_saved_text_can_use_the_booking_variables_in_the_real_notice(): void
    {
        $this->travelTo('2026-11-09');
        $this->seedBooking();
        DB::table('embarque')->where('embarque_id', 10)->update(['chegada_prevista' => '2026-11-12']);
        DB::table('agente_contato')->insert(['ref_pessoa' => 3, 'name' => 'Ana', 'email' => 'ana@agente.de', 'operacao' => 'e', 'porto_id' => null, 'created_at' => '2026-01-01', 'created_by' => 1]);
        DB::table('fin_charges')->insert(['ref_booking' => 10, 'ref_type' => 1, 'status' => 0, 'created_at' => '2026-11-01']);
        app(UpdateNoticeSetting::class)(Notice::MblRelease, [
            'subject' => '{{reserva}} / {{hbl}} - {{shipper}} para {{importador}}',
            'body' => '<p>Agente {{agente}}: {{armador}}, {{porto_origem}} → {{porto_destino}}, saída {{data_saida}}, chegada {{data_chegada}}.</p>',
        ]);

        app(MblReleaseNotice::class)();

        $this->assertSame(['BKG10 / HBL10 - Exportador SA para Importador GmbH'], $this->sentSubjects()->all());
        $this->assertStringContainsString('Agente Agente Hamburgo: MAERSK, Santos → Hamburgo, saída 20/11/2026, chegada 12/11/2026.', $this->sentHtml());
    }
}

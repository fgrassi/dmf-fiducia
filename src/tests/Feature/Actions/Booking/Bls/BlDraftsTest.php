<?php

namespace Tests\Feature\Actions\Booking\Bls;

use App\Actions\Booking\Bls\BlDefaults;
use App\Actions\Booking\Bls\ImportBlDraft;
use App\Actions\Booking\Bls\ImportBookingData;
use App\Actions\Booking\Bls\ImportOnlineDrafts;
use App\Actions\Booking\Bls\OnlineDraftDetails;
use App\Actions\Booking\Bls\PendingDrafts;
use App\Actions\Booking\Bls\ProtestLetterPdf;
use App\Actions\Booking\Bls\SaveContainerDamages;
use App\Actions\Booking\Bls\ToggleOnlineBl;
use App\Models\Bl;
use App\Models\Container;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BlDraftsTest extends TestCase
{
    use LegacySchema;

    public function test_the_draft_spreadsheet_fills_the_bl_and_replaces_its_containers(): void
    {
        DB::table('tipo_container')->insert(['tipo_container_id' => 1, 'nome' => "40' HC"]);
        $bl = $this->bl(['bl' => 'OLD', 'gross_weight' => '99', 'volume' => '88']);
        Container::create(['bl_id' => $bl->getKey(), 'numero' => 'OLD-CTN']);

        app(ImportBlDraft::class)($bl, $this->draftFile([['shipper', 3, 0, 'ACME'], ['bl', 3, 20, 'HBL-NEW'], ['mbl', 5, 13, 'MBL-1'], ['net', 32, 20, '900'], ['desc', 26, 8, 'GOODS ']], [[" 40' HC ", 'ABCD1234567', '3.8', 'S1', 'S2', null, 10, 1000.5, 900, 30.25]]));

        $bl->refresh();
        $this->assertSame(['ACME', 'HBL-NEW', 'MBL-1', '900', null, null], [$bl->shipper, $bl->bl, $bl->mbl_number, $bl->net_weight, $bl->gross_weight, $bl->volume]);
        $this->assertSame('GOODS '.BlDefaults::DESCRIPTION, $bl->desc_pkgs);
        $container = Container::where('bl_id', $bl->getKey())->get();
        $this->assertSame(['ABCD1234567'], $container->pluck('numero')->all());
        $this->assertSame(['10,0000', '1000,5000', '900,0000', '30,2500', 'S2'], [$container[0]->qty_pkgs, $container[0]->gross_weight, $container[0]->net_weight, $container[0]->volume, $container[0]->seals_n2]);
    }

    public function test_a_draft_without_a_bl_number_keeps_the_one_the_bl_has(): void
    {
        $bl = $this->bl(['bl' => 'KEEP']);

        app(ImportBlDraft::class)($bl, $this->draftFile([['shipper', 3, 0, 'ACME']], []));

        $this->assertSame('KEEP', $bl->fresh()->bl);
    }

    public function test_a_draft_with_an_unknown_container_type_changes_nothing(): void
    {
        $bl = $this->bl(['shipper' => 'BEFORE']);
        Container::create(['bl_id' => $bl->getKey(), 'numero' => 'KEEP-CTN']);

        try {
            app(ImportBlDraft::class)($bl, $this->draftFile([['shipper', 3, 0, 'ACME']], [['NO SUCH TYPE', 'X', '1', 'S', null, null, 1, 1, 1, 1]]));
            $this->fail('expected a DomainException');
        } catch (DomainException $e) {
            $this->assertStringContainsString('NO SUCH TYPE', $e->getMessage());
        }

        $this->assertSame('BEFORE', $bl->fresh()->shipper);
        $this->assertSame(['KEEP-CTN'], Container::where('bl_id', $bl->getKey())->pluck('numero')->all());
    }

    public function test_pending_drafts_are_grouped_by_revision_chain_and_hide_the_imported_ones(): void
    {
        $booking = $this->booking();
        $first = $this->draft($booking, ['draft_id' => 10, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00', 'version_number' => 1]);
        $second = $this->draft($booking, ['draft_id' => 11, 'parent_draft_id' => 10, 'created_at' => '2026-01-02 10:00:00', 'updated_at' => '2026-01-02 10:00:00', 'version_number' => 2]);
        $other = $this->draft($booking, ['draft_id' => 20, 'created_at' => '2026-01-03 10:00:00', 'updated_at' => '2026-01-03 10:00:00']);
        Bl::create(['embarque_id' => $booking->getKey(), 'bl_online_id' => $first]);
        DB::table('bl_online_tokens')->insert(['booking_id' => $booking->getKey(), 'company_id' => 1, 'token' => 't', 'url' => 'https://x.test/draft', 'created_at' => '2026-01-01 08:00:00']);

        $pending = app(PendingDrafts::class)($booking);

        $this->assertSame([[$other, 2, 1], [$second, 1, 2]], collect($pending['drafts'])->map(fn (array $d) => [$d['bl_online_id'], $d['group'], $d['version']])->all());
        $this->assertSame(['https://x.test/draft', '01/01/2026 08:00'], [$pending['token']['url'], $pending['token']['created_at']]);
    }

    public function test_importing_online_drafts_creates_closed_bls_with_the_sum_of_the_equipments(): void
    {
        $booking = $this->booking();
        $draft = $this->draft($booking, ['shipper' => 'ACME', 'freight_at' => 'ORIGIN']);
        DB::table('bl_online_equipments')->insert([
            ['bl_id' => $draft, 'equipment_id' => 1, 'container_number' => 'AAAA1', 'tare' => '3', 'seal_1' => 'S1', 'quantity' => 5, 'gross_weight' => 1000.5, 'net_weight' => 900, 'cubic_meters' => 30.25],
            ['bl_id' => $draft, 'equipment_id' => 1, 'container_number' => 'BBBB2', 'tare' => null, 'seal_1' => null, 'quantity' => 1, 'gross_weight' => 2000, 'net_weight' => 1, 'cubic_meters' => 10],
        ]);
        $legacy = $this->draft($booking, []);
        DB::table('container_online')->insert(['bl_online_id' => $legacy, 'tipo_container_id' => 1, 'numero' => 'OLD-STYLE', 'qty_pkgs' => '2']);

        $result = app(ImportOnlineDrafts::class)($booking, [$draft, $legacy, 999]);

        $this->assertSame(['imported' => 2, 'skipped' => 1, 'errors' => 0], $result);
        $bl = Bl::where('bl_online_id', $draft)->first();
        $this->assertSame(['f', 'aberto', 'ACME', 'ORIGIN', 3000.5, 901.0, 40.25], [$bl->status_bl_online, $bl->status, $bl->shipper, $bl->freight_at, (float) $bl->gross_weight, (float) $bl->net_weight, (float) $bl->volume]);
        $this->assertSame(['AAAA1', 'BBBB2'], Container::where('bl_id', $bl->getKey())->orderBy('container_id')->pluck('numero')->all());
        $this->assertSame(['OLD-STYLE'], Container::where('bl_id', Bl::where('bl_online_id', $legacy)->value('bl_id'))->pluck('numero')->all());
    }

    public function test_importing_nothing_or_what_was_already_imported_is_refused(): void
    {
        $booking = $this->booking();
        $draft = $this->draft($booking, []);

        try {
            app(ImportOnlineDrafts::class)($booking, []);
            $this->fail('expected a DomainException');
        } catch (DomainException $e) {
            $this->assertSame('Selecione ao menos um draft válido para importar.', $e->getMessage());
        }

        app(ImportOnlineDrafts::class)($booking, [$draft]);

        $this->expectExceptionMessage('Nenhum draft pendente encontrado para os itens selecionados.');
        app(ImportOnlineDrafts::class)($booking, [$draft]);
    }

    public function test_the_online_bl_switch_reopens_without_touching_and_closes_back_to_the_draft(): void
    {
        $booking = $this->booking();
        $draft = $this->draft($booking, ['shipper' => 'FROM DRAFT']);
        DB::table('container_online')->insert(['bl_online_id' => $draft, 'tipo_container_id' => 1, 'numero' => 'ONLINE-CTN']);
        $bl = Bl::create(['embarque_id' => $booking->getKey(), 'bl_online_id' => $draft, 'status_bl_online' => 'f', 'shipper' => 'EDITED']);
        Container::create(['bl_id' => $bl->getKey(), 'numero' => 'EDITED-CTN']);

        app(ToggleOnlineBl::class)($bl);
        $this->assertSame(['a', 'EDITED', ['EDITED-CTN']], [$bl->fresh()->status_bl_online, $bl->fresh()->shipper, Container::where('bl_id', $bl->getKey())->pluck('numero')->all()]);

        app(ToggleOnlineBl::class)($bl->fresh());
        $this->assertSame(['f', 'FROM DRAFT', ['ONLINE-CTN']], [$bl->fresh()->status_bl_online, $bl->fresh()->shipper, Container::where('bl_id', $bl->getKey())->pluck('numero')->all()]);
    }

    public function test_importing_the_booking_data_builds_the_bl_and_a_dummy_container_per_unit(): void
    {
        DB::table('pessoa')->insert([['pessoa_id' => 5, 'nome' => 'EXPORTER'], ['pessoa_id' => 6, 'nome' => 'IMPORTER']]);
        DB::table('regra_despesa_cobranca_item')->insert(['despesa_id' => 50, 'regra_despesa_cobranca_id' => 4]);
        $booking = $this->booking(['booking' => 'EXP-77', 'pessoa_id_cliente' => 5, 'pessoa_id_importador' => 6, 'finalizado' => 'c']);
        DB::table('fin_expenses_values')->insert(['ref_embarque' => $booking->getKey(), 'ref_expense' => 50, 'billing' => 'c']);
        DB::table('mercadoria')->insert([['embarque_id' => $booking->getKey(), 'tipo_container_id' => 1, 'quantidade' => '2'], ['embarque_id' => $booking->getKey(), 'tipo_container_id' => 2, 'quantidade' => '1']]);
        $bl = $this->bl(['embarque_id' => $booking->getKey(), 'shipper' => 'OLD']);
        Container::create(['bl_id' => $bl->getKey(), 'numero' => 'OLD']);

        app(ImportBookingData::class)($booking, $bl, 7);

        $bl->refresh();
        $this->assertSame(['EXPORTER', 'IMPORTER', 'EXP-77', 'EXP-77', 'DESTINATION', '3', '3', '3'], [$bl->shipper, $bl->consignee, $bl->bl, $bl->mbl_number, $bl->freight_at, (string) $bl->gross_weight, (string) $bl->net_weight, (string) $bl->volume]);
        $this->assertSame(['CTN 1', 'CTN 2', 'CTN 3'], Container::where('bl_id', $bl->getKey())->orderBy('container_id')->pluck('numero')->all());
        $booking->refresh();
        $this->assertSame([1, 'EXP-77', 'EXP-77'], [(int) $booking->qtd_bl, $booking->house_bl, $booking->master_bl]);
        $this->assertSame('Atualização de BL', DB::table('rastreamento')->value('assunto'));
    }

    public function test_the_protest_letter_lists_the_containers_with_their_damage_notes(): void
    {
        $booking = $this->booking(['booking' => 'EXP-88']);
        $bl = $this->bl(['embarque_id' => $booking->getKey(), 'bl' => 'HBL-9', 'mbl_number' => 'MBL-9']);
        $damaged = Container::create(['bl_id' => $bl->getKey(), 'numero' => 'DMGD1111111']);
        $clean = Container::create(['bl_id' => $bl->getKey(), 'numero' => 'CLEAN222222']);
        $foreign = Container::create(['bl_id' => $this->bl()->getKey(), 'numero' => 'FOREIGN1111']);

        app(SaveContainerDamages::class)($booking, [$damaged->getKey() => 'porta amassada', $clean->getKey() => '', $foreign->getKey() => 'não é meu']);

        $this->assertSame(['porta amassada', null, null], [$damaged->fresh()->observacao_avaria, $clean->fresh()->observacao_avaria, $foreign->fresh()->observacao_avaria]);

        $pdf = app(ProtestLetterPdf::class)->render($booking);
        $pdf->setCompression(false);
        $content = $pdf->Output('', 'S');

        foreach (['HBL-9', 'MBL-9', 'DMGD1111111: porta amassada'] as $expected) {
            $this->assertStringContainsString($expected, $content);
        }
        $this->assertStringNotContainsString('CLEAN222222: ', $content);
    }

    public function test_the_draft_details_carry_equipments_totals_and_readable_options(): void
    {
        $booking = $this->booking(['booking' => 'EXP-55']);
        DB::table('tipo_container')->insert(['tipo_container_id' => 1, 'nome' => "40' HC"]);
        $draft = $this->draft($booking, ['tipo_madeira' => 2, 'instruction_type' => 3, 'freight_at' => 'cc', 'per_agreement' => 's']);
        DB::table('bl_online_equipments')->insert(['bl_id' => $draft, 'equipment_id' => 1, 'container_number' => 'AAAA1', 'seal_1' => 'S1', 'seal_2' => 'S2', 'quantity' => 5, 'gross_weight' => 10, 'net_weight' => 9, 'cubic_meters' => 1]);

        $details = app(OnlineDraftDetails::class)($draft);

        $this->assertSame(['EXP-55', 'Used : Processed', 'ORIGINAIS NA ORIGEM', 'COLLECT', 'SIM'], [$details['draft']['booking'], $details['draft']['tipo_madeira'], $details['draft']['instruction_type'], $details['draft']['freight_at'], $details['draft']['per_agreement']]);
        $this->assertSame([["40' HC", 'S1, S2']], collect($details['equipments'])->map(fn (array $e) => [$e['type'], $e['seals']])->all());
        $this->assertSame([5, 10.0], [$details['totals']['quantity'], $details['totals']['gross_weight']]);
        $this->assertNull(app(OnlineDraftDetails::class)(999));
    }

    /** @param list<array{0: string, 1: int, 2: int, 3: string}> $cells @param list<array<int, mixed>> $containers */
    private function draftFile(array $cells, array $containers): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();

        foreach ($cells as [, $row, $column, $value]) {
            $sheet->setCellValue([$column + 1, $row], $value);
        }

        $second = $book->createSheet();

        foreach ($containers as $index => $line) {
            foreach ($line as $column => $value) {
                $second->setCellValue([$column + 1, 4 + $index], $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'draft').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    private function draft(Embarque $booking, array $attributes): int
    {
        return DB::table('bl_online')->insertGetId($attributes + ['embarque_id' => $booking->getKey(), 'draft_id' => random_int(1000, 99999), 'consignee_tax_id' => '', 'notify_tax_id' => '', 'shipper_tax_id' => '', 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00']);
    }

    private function bl(array $attributes = []): Bl
    {
        return Bl::create($attributes + ['embarque_id' => $this->booking()->getKey(), 'bl' => 'HBL-1', 'status' => Bl::OPEN]);
    }

    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId($attributes + ['booking' => 'EXP'.random_int(1000, 9999), 'impor_expor' => 'e', 'tipo_embarque' => 'm']));
    }
}

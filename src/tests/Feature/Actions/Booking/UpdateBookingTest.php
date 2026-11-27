<?php

namespace Tests\Feature\Actions\Booking;

use App\Actions\Booking\Creation\BookingParties;
use App\Actions\Booking\Creation\CreateBooking;
use App\Actions\Booking\UpdateBooking;
use App\Models\Embarque;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BookingCreationFixture;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class UpdateBookingTest extends TestCase
{
    use BookingCreationFixture;
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBookingFixture();
        Storage::fake('tracking_uploads');
    }

    public function test_saves_the_changes_without_touching_blank_or_unlisted_columns(): void
    {
        $booking = $this->booking(['via' => 'Santos', 'master_bl' => 'MBL1']);
        DB::table('embarque')->where('embarque_id', $booking->getKey())->update(['armador_id' => 0, 'navio_id' => null, 'observacaoint' => '', 'nr_container' => 'ABC']);

        $updated = $this->update($booking, ['armador_id' => null, 'navio_id' => null, 'observacaoint' => null, 'via' => null, 'master_bl' => null, 'invoice' => 'INV9']);

        $this->assertSame(
            [0, null, '', null, '', 'INV9', 'ABC'],
            [(int) $updated->armador_id, $updated->navio_id, $updated->observacaoint, $updated->via, $updated->master_bl, $updated->invoice, $updated->nr_container],
        );
        $this->assertDatabaseHas('rastreamento', ['ref_embarque' => $booking->getKey(), 'assunto' => 'Atualizado: Reserva', 'evento' => 'u']);
        $this->assertSame(1, Embarque::count());
    }

    public function test_syncs_the_contacts_of_the_parties_on_the_screen_and_keeps_a_linked_contact_that_left_the_company_register(): void
    {
        $booking = $this->booking();
        $added = $this->contactOf($this->client, 7);
        $other = $this->row('usuario', ['nome' => 'De outra empresa', 'status' => 1]);
        DB::table('usuario')->where('usuario_id', $this->contact)->update(['status' => 0]);
        DB::table('embarque_contato_notify')->insert(['embarque_id' => $booking->getKey(), 'usuario_id' => $other]);

        $this->update($booking, ['contacts' => ['cliente' => [$this->contact, $added, $other]]]);

        $this->assertEqualsCanonicalizing([$this->contact, $added], app(BookingParties::class)->linkedContacts($booking, 'cliente'));
        $this->assertSame([$other], app(BookingParties::class)->linkedContacts($booking, 'notify'), 'parte fora da tela não muda');
    }

    public function test_updates_adds_and_removes_goods_keeping_the_columns_the_screen_does_not_show(): void
    {
        $good = ['nome' => 'Peças', 'quantidade' => 1, 'tipo_container_id' => $this->equipment, 'quimico' => 0];
        $booking = $this->booking(['goods' => [$good, [...$good, 'nome' => 'Sai']]]);
        [$kept, $removed] = DB::table('mercadoria')->orderBy('mercadoria_id')->pluck('mercadoria_id')->all();
        DB::table('mercadoria')->where('mercadoria_id', $kept)->update(['gross_weight' => 12.5, 'temperatura' => -18, 'quimico' => 1, 'carga_perigosa' => 1]);
        $other = $this->row('tipo_container', ['nome' => "40' HC", 'tipo_embarque' => 'm']);

        // Trocar o equipamento e desmarcar "Químico?" escondem a temperatura e a carga perigosa.
        $this->update($booking, ['goods' => [
            [...$good, 'mercadoria_id' => $kept, 'quantidade' => 3, 'tipo_container_id' => $other],
            [...$good, 'nome' => 'Nova'],
        ]]);

        $this->assertDatabaseMissing('mercadoria', ['mercadoria_id' => $removed]);
        $this->assertDatabaseHas('mercadoria', ['mercadoria_id' => $kept, 'quantidade' => 3, 'gross_weight' => 12.5, 'temperatura' => null, 'carga_perigosa' => null]);
        $this->assertDatabaseHas('mercadoria', ['embarque_id' => $booking->getKey(), 'nome' => 'Nova']);
        $this->assertSame(2, DB::table('mercadoria')->count());
    }

    public function test_hidden_goods_fields_survive_an_edit_that_does_not_change_what_controls_them(): void
    {
        $good = ['nome' => 'Peças', 'quantidade' => 1, 'tipo_container_id' => $this->equipment, 'quimico' => 0];
        $booking = $this->booking(['goods' => [$good]]);
        $id = DB::table('mercadoria')->value('mercadoria_id');
        DB::table('mercadoria')->update(['temperatura' => -18, 'carga_perigosa' => 1]);

        $this->update($booking, ['goods' => [[...$good, 'mercadoria_id' => $id, 'quantidade' => 2]]]);

        $this->assertDatabaseHas('mercadoria', ['mercadoria_id' => $id, 'quantidade' => 2, 'temperatura' => -18, 'carga_perigosa' => 1]);
    }

    public function test_an_air_edit_asks_the_insurance_justification_only_when_the_insurance_or_the_client_changes(): void
    {
        $booking = app(CreateBooking::class)($this->bookingData(['booking' => 'AIMP1-26', 'de' => 'Ana', 'insurance' => 's']), 'i', 'a', $this->author);
        DB::table('pessoa')->where('pessoa_id', $this->client)->update(['seguro_obrigatorio' => 's']);
        $data = [...$this->bookingData(['booking' => 'AIMP1-26', 'de' => 'Ana', 'insurance' => 'n']), 'justificativa_seguro' => null];
        unset($data['goods']);

        try {
            app(UpdateBooking::class)($booking, $data, $this->author);
            $this->fail('Salvou sem justificativa.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('justifique', $e->getMessage());
        }

        app(UpdateBooking::class)($booking, [...$data, 'justificativa_seguro' => 'Apólice própria'], $this->author);
        app(UpdateBooking::class)($booking, $data, $this->author);

        $this->assertSame('n', $booking->fresh()->insurance);
    }

    public function test_goods_of_another_booking_are_never_changed(): void
    {
        $good = ['nome' => 'Peças', 'quantidade' => 1, 'tipo_container_id' => $this->equipment, 'quimico' => 0];
        $booking = $this->booking();
        $foreign = $this->row('mercadoria', ['embarque_id' => 999, 'nome' => 'Alheia']);

        $this->update($booking, ['goods' => [[...$good, 'mercadoria_id' => $foreign]]]);

        $this->assertDatabaseHas('mercadoria', ['mercadoria_id' => $foreign, 'embarque_id' => 999, 'nome' => 'Alheia']);
    }

    public function test_reefer_temperature_is_only_required_when_the_equipment_is_new_or_changed(): void
    {
        $booking = $this->booking();
        $old = $this->row('mercadoria', ['embarque_id' => $booking->getKey(), 'nome' => 'Frango', 'quantidade' => 1, 'tipo_container_id' => 8]);
        $good = ['mercadoria_id' => $old, 'nome' => 'Frango', 'quantidade' => 1, 'tipo_container_id' => 8];

        $this->update($booking, ['goods' => [$good]]);

        $this->expectExceptionMessage('REEFER');
        $this->update($booking, ['goods' => [$good, [...$good, 'mercadoria_id' => null]]]);
    }

    #[DataProvider('notEditable')]
    public function test_refuses_a_booking_that_cannot_be_edited(array $columns, string $credentials): void
    {
        $booking = $this->booking();
        $columns && DB::table('embarque')->where('embarque_id', $booking->getKey())->update($columns);
        DB::table('usuario')->where('usuario_id', $this->author->getKey())->update(['credentials' => $credentials]);
        $this->author->refresh();

        $this->expectExceptionMessage('não pode ser editada');

        $this->update($booking, ['invoice' => 'X']);
    }

    public static function notEditable(): array
    {
        return [
            'cancelada' => [['finalizado' => 'c'], 'booking'],
            'seguro' => [['tipo_embarque' => 's'], 'booking'],
            'outra operação' => [[], 'booking,export'],
        ];
    }

    public function test_the_etd_is_locked_after_the_departure_confirmation(): void
    {
        $booking = $this->booking(['saida_prevista' => '2026-12-01']);
        $this->row('departure_confirmation', ['ref_embarque' => $booking->getKey(), 'created_by' => 1]);

        $this->update($booking, ['saida_prevista' => '2026-12-01', 'invoice' => 'OK']);

        $this->expectExceptionMessage('após a confirmação de embarque');
        $this->update($booking, ['saida_prevista' => '2026-12-02']);
    }

    public function test_an_unchanged_import_number_is_accepted_and_a_typed_one_is_refused(): void
    {
        $booking = $this->booking();
        DB::table('embarque')->where('embarque_id', $booking->getKey())->update(['booking' => 'ANTIGO-1']);

        $this->update($booking, ['booking' => 'ANTIGO-1']);

        $this->expectExceptionMessage('Solicitar número');
        $this->update($booking, ['booking' => 'DIGITADO']);
    }

    public function test_only_a_newly_chosen_party_is_checked_against_the_blacklist(): void
    {
        $booking = $this->booking();
        $blocked = $this->row('pessoa', ['nome' => 'BLOQUEADA', 'ctr_blacklist' => 1]);
        DB::table('pessoa')->where('pessoa_id', $this->client)->update(['ctr_blacklist' => 1]);

        $this->update($booking, ['invoice' => 'X']);

        $this->expectExceptionMessage('blacklist');
        $this->update($booking, ['pessoa_id_importador' => $blocked]);
    }

    public function test_maritime_export_refuses_the_number_of_another_booking_but_not_its_own(): void
    {
        $this->export(['booking' => 'EXP-2']);
        $booking = $this->export();

        $this->updateExport($booking, ['invoice' => 'X']);

        $this->expectExceptionMessage('reserva já foi utilizado');
        $this->updateExport($booking, ['booking' => 'EXP-2']);
    }

    public function test_a_number_already_shared_only_blocks_the_edit_that_stops_consolidating_it(): void
    {
        $this->export(['booking' => 'EXP-1']);
        $booking = $this->export(['consolidado' => 's']);
        $shared = $this->export(['consolidado' => 's']);
        DB::table('embarque')->where('embarque_id', $shared->getKey())->update(['consolidado' => null]);

        $this->assertSame('X', $this->updateExport($shared, ['consolidado' => null, 'invoice' => 'X'])->invoice);
        $this->assertSame('Y', $this->updateExport($booking, ['consolidado' => 's', 'invoice' => 'Y'])->invoice);

        $this->expectExceptionMessage('reserva já foi utilizado');
        $this->updateExport($booking, ['consolidado' => 'n']);
    }

    public function test_an_etd_already_after_the_validity_only_blocks_the_edit_that_changes_one_of_the_dates(): void
    {
        $booking = $this->export(['saida_prevista' => '2027-01-10']);
        DB::table('embarque')->where('embarque_id', $booking->getKey())->update(['saida_prevista' => '2027-02-10']);

        $this->assertSame('X', $this->updateExport($booking, ['saida_prevista' => '2027-02-10', 'invoice' => 'X'])->invoice);

        foreach ([['saida_prevista' => '2027-02-11'], ['saida_prevista' => '2027-02-10', 'data_validade' => '2027-02-01', 'justificativa_validade' => 'Novo frete']] as $dates) {
            try {
                $this->updateExport($booking, $dates);
                $this->fail('Salvou com o ETD depois da validade.');
            } catch (DomainException $e) {
                $this->assertStringContainsString('posterior à Data de Validade', $e->getMessage());
            }
        }
    }

    public function test_changing_the_export_validity_needs_a_justification_that_is_logged_and_mailed_with_the_file(): void
    {
        $booking = $this->export();

        try {
            $this->updateExport($booking, ['data_validade' => '2027-03-31']);
            $this->fail('A validade mudou sem justificativa.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('justificativa', $e->getMessage());
        }

        $this->updateExport($booking, [
            'data_validade' => '2027-03-31',
            'justificativa_validade' => 'Renegociado',
            'arquivo_validade' => UploadedFile::fake()->create('novo frete.pdf', 10),
        ]);

        $this->assertDatabaseHas('rastreamento', ['ref_embarque' => $booking->getKey(), 'assunto' => 'Alteração da Data de Validade da Cotação', 'historico' => 'Renegociado']);
        $this->assertDatabaseHas('emb_embarque_files', ['ref_embarque' => $booking->getKey(), 'ref_file_type' => 45]);
        $file = DB::table('cmn_files')->value('name');
        $this->assertStringEndsWith('_novo_frete.pdf', $file);
        Storage::disk('tracking_uploads')->assertExists("docs_emb/{$file}");
        $this->assertContains('Alteração da Data de Validade da Cotação - EXP-1 - ', $this->sentSubjects()->all());
    }

    public function test_anticipating_the_etd_or_changing_the_ship_of_an_insured_export_warns_about_the_policy(): void
    {
        $ship = $this->row('navio', ['nome' => 'UM']);
        $booking = $this->export(['navio_id' => $ship, 'saida_prevista' => '2026-12-20']);

        $this->updateExport($booking, ['navio_id' => $ship, 'saida_prevista' => '2026-12-10']);
        $this->assertSame([], $this->policyWarnings(), 'sem seguro cobrado não há apólice a ajustar');

        $this->row('fin_expenses_values', ['ref_embarque' => $booking->getKey(), 'ref_expense' => 79, 'selling_rate' => 50]);
        $this->updateExport($booking, ['navio_id' => $ship, 'saida_prevista' => '2026-12-15']);
        $this->assertSame([], $this->policyWarnings(), 'adiar o ETD não avisa');

        $this->updateExport($booking, ['navio_id' => $this->row('navio', ['nome' => 'DOIS']), 'saida_prevista' => '2026-12-05']);
        $this->assertCount(2, $this->policyWarnings());
    }

    public function test_the_direct_bl_collect_notice_goes_only_when_the_booking_starts_to_match(): void
    {
        $booking = $this->export();
        $notices = fn () => $this->sentSubjects()->filter(fn (string $subject) => str_contains($subject, 'BL DIRETO'))->count();

        $this->updateExport($booking, ['bl_direto' => 1, 'tipo_frete' => 'c']);
        $this->updateExport($booking, ['bl_direto' => 1, 'tipo_frete' => 'c']);

        $this->assertSame(1, $notices());
    }

    public function test_contracting_the_insurance_later_creates_the_insurance_booking_once(): void
    {
        $booking = $this->booking(['insurance' => 'n']);

        $this->update($booking, ['insurance' => 's']);
        $this->update($booking, ['insurance' => 's']);

        $this->assertSame(1, Embarque::where('tipo_embarque', 's')->count());
        $this->assertSame(1, (int) $booking->fresh()->ctr_seguro);
    }

    public function test_mandatory_insurance_is_justified_once(): void
    {
        $booking = $this->booking(['insurance' => 's']);
        DB::table('pessoa')->where('pessoa_id', $this->client)->update(['seguro_obrigatorio' => 's']);
        DB::table('embarque')->where('embarque_id', $booking->getKey())->update(['ctr_seguro' => null]);

        try {
            $this->update($booking, ['insurance' => 'n']);
            $this->fail('Salvou sem justificativa.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('justifique', $e->getMessage());
        }

        $this->update($booking, ['insurance' => 'n', 'justificativa_seguro' => 'Apólice própria']);
        $this->update($booking, ['insurance' => 'n']);

        $this->assertSame(1, DB::table('rastreamento')->where('justificativa', 'Apólice própria')->count());
    }

    public function test_the_dmf_broker_follows_only_a_change_of_destination_port(): void
    {
        $chosen = $this->row('despachante', ['nome' => 'ANTIGO']);
        $default = $this->row('despachante', ['nome' => 'PADRAO']);
        $booking = $this->booking();
        DB::table('embarque')->where('embarque_id', $booking->getKey())->update(['despachante_dmf_id' => $chosen]);
        DB::table('porto')->where('porto_id', $this->destination)->update(['despachante_id' => $default]);

        $same = $this->update($booking, ['despachante_dmf_id' => $default]);
        $this->assertSame($chosen, (int) $same->despachante_dmf_id);

        $santos = $this->row('porto', ['nome' => 'SANTOS', 'uncode' => 'BRSSZ', 'ref_pais' => 1, 'tipo' => 'p', 'despachante_id' => $default]);
        $moved = $this->update($booking, ['porto_destino_id' => $santos]);
        $this->assertSame($default, (int) $moved->despachante_dmf_id);
    }

    public function test_the_ce_mercante_date_and_the_history_snapshot_follow_only_real_changes(): void
    {
        $booking = $this->booking(['ce_mercante' => '111']);
        DB::table('embarque')->where('embarque_id', $booking->getKey())->update(['data_ce_mercante' => '2026-01-01 00:00:00']);
        $snapshots = fn () => DB::table('hist_embarque')->where(['embarque_id' => $booking->getKey(), 'noperacao' => 'U'])->count();

        $same = $this->update($booking, ['ce_mercante' => '111', 'invoice' => 'X']);
        $this->assertSame(['2026-01-01 00:00:00', 0], [$same->data_ce_mercante, $snapshots()]);

        $changed = $this->update($booking, ['ce_mercante' => '222', 'saida_prevista' => '2026-12-05']);
        $this->assertNotSame('2026-01-01 00:00:00', $changed->data_ce_mercante);
        $this->assertSame(['2026-12-05', 1], [$changed->etd_inicial, $snapshots()]);

        $postponed = $this->update($booking, ['saida_prevista' => '2026-12-09']);
        $this->assertSame('2026-12-05', $postponed->etd_inicial, 'o ETD inicial não muda');
    }

    public function test_updates_the_vessel_of_every_bl_without_wiping_it_when_there_is_no_ship(): void
    {
        $ship = $this->row('navio', ['nome' => 'MSC ANNA']);
        $booking = $this->booking(['navio_id' => $ship, 'vg' => '1N']);
        $this->row('bl', ['embarque_id' => $booking->getKey(), 'vessel' => 'ANTIGO']);

        $this->update($booking, ['navio_id' => $ship, 'vg' => '2S']);
        $this->assertSame(['MSC ANNA - 2S', 'MSC ANNA - 2S'], DB::table('bl')->pluck('vessel')->all());

        $this->update($booking, ['navio_id' => null]);
        $this->assertSame(['MSC ANNA - 2S', 'MSC ANNA - 2S'], DB::table('bl')->pluck('vessel')->all());
    }

    public function test_a_reviewed_import_only_mails_the_matrix_expenses_it_did_not_launch(): void
    {
        $good = ['nome' => 'Peças', 'quantidade' => 1, 'tipo_container_id' => $this->equipment, 'quimico' => 0];
        $booking = $this->booking(['goods' => [$good]]);
        DB::table('embarque')->where('embarque_id', $booking->getKey())->update(['revisao_despesas' => 's']);
        $expense = $this->row('fin_expenses', ['name' => 'AGENCY', 'operation_type' => 'b']);
        $this->row('matriz_despesa_pessoa', ['created_at' => '2026-01-01', 'created_by' => 1, 'due_from' => '2026-01-01', 'ref_currency' => 2, 'billing' => 'c', 'pessoa_id' => $this->agent, 'ref_expense' => $expense, 'expense_value' => 30]);

        $this->update($booking);

        $this->assertSame(0, DB::table('fin_expenses_values')->count());
        $this->assertContains('Revisão de Reserva: IMP1-26 - DESPESAS NÃO IMPORTADAS', $this->sentSubjects()->all());
        $this->assertStringContainsString('<td>AGENCY</td><td>Cliente/Agente</td>', $this->sentHtml());
    }

    public function test_the_special_instruction_is_replaced_but_never_erased_by_a_blank_field(): void
    {
        $booking = $this->export(['instrucao_especial' => 'Primeira']);

        $this->updateExport($booking, ['instrucao_especial' => 'Segunda']);
        $this->updateExport($booking, ['instrucao_especial' => null]);

        $this->assertSame(['Segunda'], DB::table('instrucao_especial_embarque')->pluck('instrucao')->all());
    }

    private ?int $exportContact = null;

    private function booking(array $overrides = []): Embarque
    {
        return app(CreateBooking::class)($this->bookingData($overrides), 'i', 'm', $this->author);
    }

    private function export(array $overrides = []): Embarque
    {
        return app(CreateBooking::class)($this->exportData($overrides), 'e', 'm', $this->author);
    }

    /** O que a tela de edição manda: os dados da criação com o que o teste muda. */
    private function update(Embarque $booking, array $overrides = []): Embarque
    {
        $data = $this->bookingData(['booking' => $booking->fresh()->booking, ...$overrides]);

        if (! array_key_exists('goods', $overrides)) {
            unset($data['goods']);
        }

        return app(UpdateBooking::class)($booking, $data, $this->author);
    }

    private function updateExport(Embarque $booking, array $overrides): Embarque
    {
        $data = $this->exportData(['booking' => $booking->booking, ...$overrides]);
        unset($data['goods']);

        return app(UpdateBooking::class)($booking, $data, $this->author);
    }

    private function exportData(array $overrides = []): array
    {
        $this->exportContact ??= $this->contactOf($this->client, 3);

        return $this->bookingData([
            'booking' => 'EXP-1',
            'de' => 'Ana',
            'insurance' => null,
            'tipo_fechamento' => 'o',
            'armador_id' => 1,
            'via' => 'Direto',
            'free_time_detention' => 7,
            'data_validade' => '2027-01-31',
            'consolidado' => 'n',
            'porto_origem_id' => $this->destination,
            'porto_destino_id' => $this->origin,
            'contacts' => ['cliente' => [$this->exportContact]],
            ...$overrides,
        ]);
    }

    /** @return list<string> */
    private function policyWarnings(): array
    {
        return $this->sentSubjects()->filter(fn (string $subject) => str_contains($subject, 'Atualização de Apólice'))->values()->all();
    }
}

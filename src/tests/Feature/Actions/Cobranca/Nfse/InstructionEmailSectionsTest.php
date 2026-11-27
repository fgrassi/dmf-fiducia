<?php

namespace Tests\Feature\Actions\Cobranca\Nfse;

use App\Actions\Cobranca\Documents\PreviewInstructionEmail;
use App\Actions\Cobranca\Nfse\InstructionEmailSections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsBoletos;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class InstructionEmailSectionsTest extends TestCase
{
    use BuildsBoletos, BuildsCharges, LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('tracking_uploads');

        foreach ([
            'IC_EMAIL_IMPO' => 'IMPO {{booking}} {{cliente}} | {{horario_comprovante}} | {{despachante_nome}} / {{despachante_endereco}}',
            'IC_EMAIL_IMPO_HORARIO_PADRAO' => 'até 12h',
            'IC_EMAIL_EXCECAO' => 'taxa negociada {{booking}}',
            'IC_EMAIL_BOLETO_AUTO_IMPO' => 'automático {{boleto_status}}',
            'IC_EMAIL_BOLETO_STATUS_PENDENTE' => 'pendente',
            'IC_EMAIL_BOLETO_MANUAL_IMPO' => 'confirme os valores',
            'IC_EMAIL_LATE_PAYMENT_INTRO' => '{{armador}} {{late_payment}}',
            'IC_EMAIL_LATE_PAYMENT_FOOTER' => ' fim',
        ] as $mnemonic => $body) {
            DB::table('fin_email_texto')->insert(['mnemonico' => $mnemonic, 'titulo' => "Título {$mnemonic}", 'corpo' => $body, 'ativo' => 1]);
        }
    }

    public function test_builds_the_import_email_from_the_registered_texts_escaping_booking_data(): void
    {
        DB::table('despachante')->insert(['despachante_id' => 3, 'nome' => 'DESPACHOS & CIA', 'dados_contato' => "Rua A\nItajaí"]);
        DB::table('porto')->insert(['porto_id' => 8, 'nome' => 'Itajaí', 'despachante_id' => 3]);
        DB::table('armador')->insert(['armador_id' => 5, 'nome' => 'MSC', 'late_payment' => '<b>multa</b>']);
        $charge = $this->boletoCharge();
        DB::table('embarque')->update(['porto_destino_id' => 8, 'armador_id' => 5]);

        $sections = InstructionEmailSections::build($charge->fresh(), inException: false);

        $this->assertSame("IMPO BKG10 CLIENTE IMPORTADORA DE UM NOME BEM COMPRIDO LTDA | até 12h | DESPACHOS &amp; CIA / Rua A<br />\nItajaí", $sections['instruction']);
        $this->assertSame(['title' => 'Título IC_EMAIL_BOLETO_MANUAL_IMPO', 'html' => 'confirme os valores'], $sections['boleto']);
        $this->assertSame('MSC <b>multa</b> fim', $sections['late_payment']['html']);
    }

    public function test_exception_and_automatic_boleto_change_the_sections(): void
    {
        $charge = $this->boletoCharge(automatic: true);

        $sections = InstructionEmailSections::build($charge, inException: true);

        $this->assertSame('taxa negociada BKG10', $sections['instruction']);
        $this->assertSame('automático pendente', $sections['boleto']['html']);
        $this->assertNull($sections['late_payment']);
    }

    public function test_preview_renders_the_email_without_sending(): void
    {
        $preview = app(PreviewInstructionEmail::class)($this->boletoCharge());

        $this->assertStringContainsString('BKG10', $preview['subject']);
        $this->assertStringContainsString('confirme os valores', $preview['html']);
        $this->assertCount(0, $this->sentSubjects());
    }
}

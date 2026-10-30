<?php

namespace App\Actions\Cobranca\Documents;

use App\Actions\Cobranca\Listing\ChargeDueDate;
use App\Actions\Cobranca\Recalculation\ChargeRates;
use App\Models\CmnFile;
use App\Models\FinCharges;
use App\Services\Cobranca\ChargeDetails;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use TCPDF;

/**
 * Cobranca::geraPdfSeguro (legado): PDF da Instrução de Cobrança de seguro (fatura, despesas e frete da confirmação de
 * seguro, equipamentos, itens, dados bancários e contatos), gravado em uploads/cobranca e enviado ao cliente.
 * Cliente em exceção (FinClientsException) sem recálculo: o e-mail vai só para cobranca@, avisando que precisa recalcular.
 */
class InsurancePdf
{
    private const FS = 7;

    private const SPACE = 4;

    private const LABEL = 60;

    public function __construct(
        private ChargeDetails $details,
        private ChargeDueDate $dueDate,
        private ChargeRecipients $recipients,
    ) {}

    /** @return string arquivo em uploads/cobranca */
    public function __invoke(FinCharges $charge, bool $recalculated = false): string
    {
        $e = $charge->embarque;
        $name = 'ic'.$e->impor_expor.$charge->id.now()->format('dmyGi');

        Storage::disk('tracking_uploads')->put("cobranca/{$name}.pdf", $this->render($charge)->Output('', 'S'));

        $file = CmnFile::forceCreate(['name' => $name, 'subject' => "Cobrança do Booking {$e->booking}", 'created_at' => now()->toDateString(), 'uid' => 1]);
        $file->forceFill(['uid' => sha1((string) $file->getKey())])->save();
        DB::table('fin_charges_files')->insert(['ref_charge' => $charge->id, 'ref_file' => $file->getKey()]);

        $this->send($charge, "{$name}.pdf", $recalculated);

        return "{$name}.pdf";
    }

    public function render(FinCharges $charge): TCPDF
    {
        $e = $charge->embarque;
        $insurance = DB::table('insurance_confirmation')->where('ref_embarque', $e->embarque_id)->orderBy('id')->first();
        $symbol = fn ($id) => $id ? (string) DB::table('cmn_currencies')->where('id', $id)->value('symbol') : '';
        $person = fn ($id) => $id ? (string) DB::table('pessoa')->where('pessoa_id', $id)->value('nome') : '';

        $pdf = new TCPDF('P', 'mm', 'A4', true);
        $pdf->SetCreator('DMF Logistics');
        $pdf->SetAuthor('DMF Logistics');
        $pdf->SetTitle('DMF Logistics - Cobrança');
        $pdf->SetSubject('Documento de Cobrança ');
        $pdf->SetKeywords('DMF Documento de Cobrança');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->setMargins(20, 10);
        $pdf->SetAutoPageBreak(true, 7);

        $cell = fn (float $w, string $text, string $align = 'L', bool $fill = false, int $ln = 0, $x = '', $y = '') => $pdf->MultiCell($w, 0, $text, 0, $align, $fill, $ln, $x, $y, true);
        $field = function (string $label, string $value, int $w = 45) use ($pdf, $cell) {
            $pdf->SetFont('Helvetica', 'B', self::FS);
            $cell(self::LABEL, $label);
            $pdf->SetFont('Helvetica', '', self::FS);
            $cell($w, $value);
            $pdf->Ln(self::SPACE);
        };
        $header = function (array $columns) use ($pdf, $cell) {
            $pdf->SetFont('Helvetica', 'B', self::FS);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFillColor(93, 89, 80);

            foreach ($columns as [$w, $text]) {
                $cell($w, $text, 'C', true);
            }

            $pdf->Ln(self::SPACE);
            $pdf->SetTextColor(0);
            $pdf->SetFillColor(244, 244, 244);
            $pdf->SetFont('Helvetica', '', self::FS);
        };

        $pdf->Ln(20);
        $pdf->Image(resource_path('images/logo.png'), 15, 10, 30);
        $pdf->SetFont('Helvetica', 'B', 10);
        $cell(100, 'DMF LOGISTICS DO BRASIL LTDA', 'L', false, 0, 60, 10);
        $pdf->Ln(self::SPACE);
        $cell(100, 'Instrução de Cobrança ', 'L', false, 1, 60);

        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->MultiCell(0, 5, 'Data de Vencimento: '.$this->dueDate->forPdf($charge), 0, 'R', false, 1, 10, 15);

        $pdf->Ln(10);
        $header([[165, 'Informações Financeiras']]);
        $pdf->SetFillColor(255, 255, 255);
        $field('Data:', CarbonImmutable::parse($charge->updated_at ?: $charge->created_at)->format('d/m/Y'));
        $field('Reserva:', (string) $e->booking);
        $field('Fatura:', (string) $e->invoice);
        $field('BLs (números):', (string) $e->house_bl, self::LABEL);
        // o legado imprime o decimal do banco como veio ("5496.70")
        $amount = fn (mixed $currency, mixed $value) => $symbol($currency).' '.number_format((float) $value, 2, '.', '');
        $field('Valor da fatura:', $insurance ? $amount($insurance->ref_currency_fatura, $insurance->valor_fatura) : '', self::LABEL);
        $field('Despesas (10%):', $insurance ? $amount($insurance->ref_currency_despesa, $insurance->despesas) : '', self::LABEL);
        $field('Valor do Frete:', $insurance ? $amount($insurance->ref_currency_frete, $insurance->valor_frete) : '', self::LABEL);
        $pdf->Ln(self::SPACE);

        $pdf->SetFont('Helvetica', 'B', self::FS);
        $cell(self::LABEL, 'Origem:');
        $pdf->SetFont('Helvetica', '', self::FS);
        $cell(45, $this->details->port($e->porto_origem_id));
        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', self::FS);
        $cell(self::LABEL, 'Destino:');
        $pdf->SetFont('Helvetica', '', self::FS);
        $cell(45, $this->details->port($e->porto_destino_id));
        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', self::FS);
        $cell(self::LABEL, 'Exportador:');
        $pdf->SetFont('Helvetica', '', self::FS);
        $cell(150, filled($charge->nome_exportador) ? (string) $charge->nome_exportador : $person($e->pessoa_id_cliente), 'L', false, 1);
        $cell(self::LABEL, 'Importador:');
        $cell(150, filled($charge->nome_importador) ? (string) $charge->nome_importador : $person($e->pessoa_id_importador), 'L', false, 1);

        $pdf->Ln(self::SPACE * 2);
        $header([[80, 'Equipamento'], [85, 'Quantidade']]);

        $equipments = DB::table('mercadoria as m')
            ->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'm.tipo_container_id')
            ->where('m.embarque_id', $e->embarque_id)
            ->get(['t.nome', 'm.quantidade']);

        foreach ($equipments as $m) {
            $cell(80, (string) $m->nome, 'C', true);
            $cell(85, (string) $m->quantidade, 'C', true);
            $pdf->Ln(self::SPACE);
        }

        $pdf->Ln(self::SPACE * 2);
        $pdf->SetFont('Helvetica', 'B', 12);
        $cell(165, 'Despesas');
        $pdf->Ln(self::SPACE * 2);
        $header([[102, 'Despesa'], [21, 'Taxa (moeda)'], [21, 'Valor Unitário'], [21, 'Valor do Item']]);

        foreach ($this->details->items($charge) as $item) {
            $cell(102, $item['expense'], 'L', true);
            $cell(21, $item['rate'], 'C', true);
            $cell(21, $item['unit'], 'R', true);
            $cell(21, $item['value'], 'R', true);
            $pdf->Ln(self::SPACE);
        }

        if ((float) $charge->valor_iof) {
            $pdf->MultiCell(150, 0, 'Despesas Finaceiras/IOF', 'B', 'L', false, 0, 10, '', true);
            $pdf->MultiCell(40, 0, 'R$ '.number_format((float) $charge->valor_iof, 2, ',', '.'), 'B', 'R', false, 0, '', '', true);
        }

        $pdf->Ln();
        $pdf->MultiCell(150, 0, 'Total', 'B', 'L', false, 0, 10, '', true);
        $pdf->MultiCell(40, 0, 'R$ '.number_format((float) $charge->total_value - (float) $charge->discount, 2, ',', '.'), 'B', 'R', false, 0, '', '', true);
        $pdf->Ln();

        // cliente com boleto/prazo cadastrado não vê os dados bancários
        $payer = $e->impor_expor === 'i' ? $e->pessoa_id_importador : $e->pessoa_id_cliente;

        if (! DB::table('fin_clients_boleto')->where('ref_client', $payer)->exists()) {
            $pdf->Ln(self::SPACE * 2);
            $pdf->SetFont('Helvetica', 'B', 12);
            $cell(165, 'Dados Bancários');
            $pdf->Ln(self::SPACE * 2);
            $pdf->SetFont('Helvetica', 'B', self::FS);

            $holder = "CNPJ: 07.052.088/0001-34\nFav: DMF Logistics do Brasil Ltda";
            $pdf->MultiCell(47.5, 25, "\nBRADESCO (237)\nAg: 1587\nC/C: 227370-5\n{$holder}\n", 1, 'L', false, 0, 10, '', true);
            $pdf->MultiCell(47.5, 25, "\nSANTANDER (033)\nAg: 3285\nC/C: 13000195-3\n{$holder}\n", 1, 'L', false, 0, '', '', true);
            $pdf->MultiCell(47.5, 25, "\nBANCO DO BRASIL (001)\nAg: 0180-5\nC/C: 38681-2\n{$holder}\nPIX CNPJ: 07.052.088/0001-34", 1, 'L', false, 0, '', '', true);
            $pdf->MultiCell(47.5, 25, "\nSICREDI (748)\nAg: 0156\nC/C: 31930-4\n{$holder}\nPIX EMAIL: pix@dmflog.com.br", 1, 'L', false, 0, '', '', true);
            $pdf->Ln(self::SPACE);
        }

        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', self::FS);
        $cell(self::LABEL, 'Contatos Setor Financeiro DMF:', 'L', false, 1);
        $pdf->SetFont('Helvetica', '', self::FS);

        foreach (['Sr.a Márcia Ziebell', 'Financial and Administrative Manager', 'Email: marcia@dmflog.com.br', 'Telefone Direto: +55 51 3717-8204 '] as $line) {
            $cell(self::LABEL, $line, 'L', false, 1);
        }

        $pdf->Ln();
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->MultiCell(180, 5, 'HEAD OFFICE', 0, 'C', false, 1, '', 250, true);
        $pdf->SetFont('Helvetica', '', 7);

        foreach ([
            'Rua Borges de Medeiros, 274 - 2o. Andar',
            'Santa Cruz do Sul - RS - Brasil  - CEP: 96810-130',
            'Phone: +55 51 3717-8200 / FAX: +55 51 3717-8224',
            'www.dmflog.com.br - dmf@dmflog.com.br',
        ] as $line) {
            $pdf->MultiCell(180, 5, $line, 0, 'C', false, 1, '', '', true);
        }

        return $pdf;
    }

    /** E-mail "Instrução de Cobrança" do seguro (o legado não usa a seleção de e-mails da baixa, mas ela nunca existe na geração). */
    private function send(FinCharges $charge, string $file, bool $recalculated): void
    {
        $e = $charge->embarque;
        $inException = ! $recalculated && DB::table('fin_clients_exception')->where('ref_client', $e->cliente_id)->exists();
        $client = DB::table('pessoa')->where('pessoa_id', ChargeRates::clientId($e))->first(['nome', 'razao_social']);
        $type = (int) $charge->ref_type !== 1 ? ' - '.mb_strtoupper((string) $charge->type?->name) : '';
        $subject = "DMF Logistics: Instrução de Cobrança {$type}: {$e->booking} - ".preg_replace("/\r?\n/m", ' ', (string) $e->invoice)
            .' - '.($client ? ($client->razao_social ?: $client->nome) : '');

        Mail::send('mail.cobranca.insurance', ['booking' => $e->booking, 'invoice' => $e->invoice, 'exception' => $inException], function ($mail) use ($charge, $file, $subject, $inException) {
            $mail->from(ChargeRecipients::COLLECTION_EMAIL, 'DMF Logistics do Brasil - Setor de Cobrança')
                ->to([ChargeRecipients::COLLECTION_EMAIL, ...($inException ? [] : array_keys(($this->recipients)($charge)))])
                ->subject($subject)
                ->attach(Storage::disk('tracking_uploads')->path("cobranca/{$file}"), ['as' => $file]);
        });
    }
}

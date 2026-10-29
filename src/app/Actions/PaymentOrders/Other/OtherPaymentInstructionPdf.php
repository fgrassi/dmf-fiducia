<?php

namespace App\Actions\PaymentOrders\Other;

use App\Actions\PaymentOrders\PaymentInstructionPdf;
use App\Models\InstrucaoPagamentoOutros;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TCPDF;

/** pdfInstrucaoPagamento (legado, Outros): mesmo PDF da instrução do armador, titulado "ORDEM PAGAMENTO EXTRA" e com os dados bancários em texto. */
class OtherPaymentInstructionPdf
{
    public function __construct(private OtherPaymentInstruction $instruction, private PaymentInstructionPdf $pdf) {}

    public function __invoke(InstrucaoPagamentoOutros $instruction): StreamedResponse
    {
        return $this->pdf->download($this->render($instruction), $instruction->code());
    }

    public function render(InstrucaoPagamentoOutros $instruction): TCPDF
    {
        return $this->pdf->draw(($this->instruction)($instruction));
    }
}

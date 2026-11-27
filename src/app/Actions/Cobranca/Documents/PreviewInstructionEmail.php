<?php

namespace App\Actions\Cobranca\Documents;

use App\Actions\Cobranca\Nfse\InstructionEmailSections;
use App\Models\FinCharges;

/** Cobranca::montarHtmlEmailInstrucaoCobranca (legado): o assunto e o corpo do e-mail da IC, sem anexos nem envio. */
class PreviewInstructionEmail
{
    /** @return array{subject:string, html:string} */
    public function __invoke(FinCharges $charge, bool $recalculated = false): array
    {
        return [
            'subject' => InstructionPdf::subject($charge),
            'html' => view('mail.cobranca.instruction', [
                'sections' => InstructionEmailSections::build($charge, InstructionPdf::heldForRecalculation($charge, $recalculated)),
            ])->render(),
        ];
    }
}

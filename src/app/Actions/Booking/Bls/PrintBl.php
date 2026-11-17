<?php

namespace App\Actions\Booking\Bls;

use App\Models\Bl;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ImprimeBL / blPdf (legado): o BL em PDF. Imprimir "original" soma uma impressão no BL (cntImpressao e cntOriginal)
 * e carimba ORIGINAL nas três primeiras; o não negociável só abre o PDF. Visualizar e Draft são prévias, sem contagem.
 */
class PrintBl
{
    public const ORIGINAL = 'original';

    public const NON_NEGOTIABLE = 'bl';

    public function __construct(private BlPdf $pdf) {}

    public function __invoke(Bl $bl, ?string $kind = null, bool $preprinted = false, bool $draft = false, bool $marksIcon = false): StreamedResponse
    {
        $previous = (int) $bl->cntOriginal;

        if ($kind === self::ORIGINAL) {
            $bl->forceFill(['cntImpressao' => (int) $bl->cntImpressao + 1, 'cntOriginal' => $previous + 1])->save();
        }

        $pdf = $this->pdf->render($bl, $preprinted, $draft, $kind === self::ORIGINAL, $previous, $marksIcon);

        return response()->streamDownload(fn () => print ($pdf->Output('', 'S')), 'BL '.preg_replace('/[^\w.-]+/', '_', (string) $bl->bl).'.pdf', ['Content-Type' => 'application/pdf']);
    }
}

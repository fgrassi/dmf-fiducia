<?php

namespace App\Actions\Cobranca\Receipts;

use App\Actions\Cobranca\Documents\ReceiptPdf;
use App\Actions\Cobranca\Nfse\EmissionGroups;
use App\Models\CmnFile;
use App\Models\FinCharges;
use App\Models\FinPaymentReceipt;
use DomainException;
use Illuminate\Support\Facades\Storage;

/**
 * processarRecibosBaixa + Cobranca::geraRecibo (legado): um recibo por grupo de emissão de NFS-e
 * (ou um só, sem grupos), gravados em uploads/recibo e enviados juntos ao cliente.
 */
class GenerateReceipts
{
    public function __construct(
        private EmissionGroups $groups,
        private ReceiptPdf $pdf,
        private SendReceipts $send,
    ) {}

    /** @return array{groups: list<array>, warning: ?string} */
    public function __invoke(FinCharges $charge): array
    {
        $warning = null;

        try {
            $groups = ($this->groups)($charge);
        } catch (DomainException $e) {
            $groups = [];
            $warning = "Baixa concluída, mas falhou ao fatiar recibos/NFSe: {$e->getMessage()}";
        }

        $hasResidual = collect($groups)->contains('residual', true);
        $last = array_key_last($groups);
        $files = $groups === []
            ? [$this->receipt($charge)]
            : collect($groups)->map(fn (array $g, int $n) => $this->receipt(
                $charge,
                $g['item_ids'],
                $g['total'],
                // IOF/juros/multa no residual; sem residual, no último grupo
                $g['residual'] || (! $hasResidual && $n === $last),
            ))->all();

        ($this->send)($charge, $files);

        return ['groups' => $groups, 'warning' => $warning];
    }

    /**
     * substituirRecibo (legado): redesenha o PDF de um recibo existente (mesmo arquivo) com os dados atuais,
     * com todos os itens e o total completo, sem enviar e-mail.
     */
    public function replace(FinCharges $charge, int $receiptId): void
    {
        $receipt = $charge->receipts()->with('file')->findOrFail($receiptId);

        if (! $receipt->file) {
            throw new DomainException('O arquivo do recibo não foi encontrado.');
        }

        $this->receipt($charge, existing: $receipt);
    }

    /** Cria (ou reaproveita) o fin_payment_receipt + cmn_files, desenha o PDF e grava o valor do recibo. @return string arquivo em uploads/recibo */
    private function receipt(FinCharges $charge, ?array $itemIds = null, ?float $total = null, bool $includeExtras = true, ?FinPaymentReceipt $existing = null): string
    {
        $receipt = $existing ?? FinPaymentReceipt::forceCreate(['ref_charge' => $charge->id]);
        $file = $existing?->file ?? CmnFile::forceCreate([
            'name' => 'recibo_dmf'.now()->format('Y').$receipt->getKey(),
            'subject' => "Recibo do Booking {$charge->embarque->booking}",
            'created_at' => now()->toDateString(),
        ]);

        if (! $existing) {
            $file->forceFill(['uid' => sha1((string) $file->getKey())])->save();
        }

        [$pdf, $value] = $this->pdf->render($charge, $receipt->getKey(), $itemIds, $total, $includeExtras);
        $receipt->forceFill(['ref_file' => $file->getKey(), 'valor' => $value])->save();

        Storage::disk('tracking_uploads')->put("recibo/{$file->name}.pdf", $pdf->Output('', 'S'));

        return "{$file->name}.pdf";
    }
}

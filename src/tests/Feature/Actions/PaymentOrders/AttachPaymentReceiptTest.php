<?php

namespace Tests\Feature\Actions\PaymentOrders;

use App\Actions\PaymentOrders\AttachPaymentReceipt;
use App\Actions\PaymentOrders\DeletePaymentReceipt;
use App\Models\EmbEmbarqueFile;
use App\Models\FinPaymentOrder;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class AttachPaymentReceiptTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('tracking_uploads');
        $this->travelTo('2026-10-29 10:00');
        DB::table('embarque')->insert([['embarque_id' => 10, 'booking' => 'BKG10'], ['embarque_id' => 11, 'booking' => 'BKG11']]);
    }

    public function test_attaches_the_receipt_once_per_booking_and_pays_every_selected_order(): void
    {
        $first = $this->order(10);
        $second = $this->order(10);
        $third = $this->order(11);

        app(AttachPaymentReceipt::class)([$first->getKey(), $second->getKey(), $third->getKey()], '2026-10-27', UploadedFile::fake()->create('Comp Pago (1).pdf', 10, 'application/pdf'), 7);

        $file = DB::table('cmn_files')->sole();
        $this->assertSame(['emb_BKG10_Comp_Pago_1.pdf', 'Comprovante de Pagamento do Armador', sha1((string) $file->id), 7, '2026-10-29'], [$file->name, $file->subject, $file->uid, (int) $file->created_by, $file->created_at]);
        Storage::disk('tracking_uploads')->assertExists('docs_emb/emb_BKG10_Comp_Pago_1.pdf');
        $this->assertSame([[10, 54], [11, 54]], DB::table('emb_embarque_files')->orderBy('ref_embarque')->get()->map(fn ($row) => [(int) $row->ref_embarque, (int) $row->ref_file_type])->all());
        $this->assertSame([[1, '2026-10-27'], [1, '2026-10-27'], [1, '2026-10-27']], FinPaymentOrder::orderBy('id')->get()->map(fn ($order) => [(int) $order->status, $order->payment_date->toDateString()])->all());
    }

    /** @param  array{ids: list<int>, date: string, file: ?string, message: string}  $case */
    #[DataProvider('invalidRequests')]
    public function test_rejects_an_invalid_request_without_changing_anything(array $case): void
    {
        $order = $this->order(10);
        $canceled = $this->order(10, status: 2);
        $ids = array_map(fn ($key) => match ($key) {
            'open' => $order->getKey(),
            'canceled' => $canceled->getKey(),
            default => 999,
        }, $case['ids']);
        $file = $case['file'] === null ? null : UploadedFile::fake()->create($case['file'], 10);

        try {
            app(AttachPaymentReceipt::class)($ids, $case['date'], $file, 7);
            $this->fail('A baixa deveria ser recusada.');
        } catch (DomainException $e) {
            $this->assertSame($case['message'], $e->getMessage());
        }

        $this->assertSame([0, 0, 0], [DB::table('cmn_files')->count(), DB::table('emb_embarque_files')->count(), (int) $order->fresh()->status]);
        $this->assertSame([], Storage::disk('tracking_uploads')->allFiles());
    }

    /** @return array<string, array{0: array{ids: list<string>, date: string, file: ?string, message: string}}> */
    public static function invalidRequests(): array
    {
        return [
            'sem ordens' => [['ids' => [], 'date' => '2026-10-27', 'file' => 'a.pdf', 'message' => 'Dados inválidos.']],
            'sem data' => [['ids' => ['open'], 'date' => '', 'file' => 'a.pdf', 'message' => 'Dados inválidos.']],
            'sem arquivo' => [['ids' => ['open'], 'date' => '2026-10-27', 'file' => null, 'message' => 'É necessário anexar o Comprovante de Pagamento.']],
            'extensão' => [['ids' => ['open'], 'date' => '2026-10-27', 'file' => 'a.exe', 'message' => 'Formato de arquivo não permitido. Envie um PDF, JPG ou PNG.']],
            'ordem inexistente' => [['ids' => ['open', 'missing'], 'date' => '2026-10-27', 'file' => 'a.pdf', 'message' => 'Um ou mais pedidos selecionados não foram encontrados.']],
            'ordem cancelada' => [['ids' => ['open', 'canceled'], 'date' => '2026-10-27', 'file' => 'a.pdf', 'message' => 'Não é possível dar baixa em pedidos com Status Pagamento "Cancelada". Revise a seleção.']],
        ];
    }

    public function test_deleting_a_receipt_only_removes_the_link(): void
    {
        $order = $this->order(10);
        app(AttachPaymentReceipt::class)([$order->getKey()], '2026-10-27', UploadedFile::fake()->create('a.pdf', 10), 7);
        $link = EmbEmbarqueFile::sole();

        app(DeletePaymentReceipt::class)($link);

        $this->assertSame([0, 1, 1], [EmbEmbarqueFile::count(), DB::table('cmn_files')->count(), (int) $order->fresh()->status]);
        Storage::disk('tracking_uploads')->assertExists('docs_emb/emb_BKG10_a.pdf');
    }

    public function test_only_payment_receipt_links_can_be_deleted_by_this_action(): void
    {
        $other = EmbEmbarqueFile::forceCreate(['ref_embarque' => 10, 'ref_file' => 1, 'ref_file_type' => 24]);

        $this->expectException(NotFoundHttpException::class);

        app(DeletePaymentReceipt::class)($other);
    }

    private function order(int $booking, int $status = 0): FinPaymentOrder
    {
        return FinPaymentOrder::find(DB::table('fin_payment_orders')->insertGetId(['ref_armador' => 7, 'created_at' => '2026-10-01', 'status' => $status, 'ref_type' => 1, 'ref_embarque' => $booking]));
    }
}

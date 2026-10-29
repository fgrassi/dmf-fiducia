<?php

namespace Tests\Feature\Actions\PaymentOrders;

use App\Actions\PaymentOrders\EditPaymentDate;
use App\Actions\PaymentOrders\EditPaymentStatus;
use App\Models\FinPaymentOrder;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class PaymentOrderActionsTest extends TestCase
{
    use LegacySchema;

    public function test_changes_the_status_and_rejects_an_unknown_one(): void
    {
        $order = $this->order();

        app(EditPaymentStatus::class)($order, 1);

        $this->assertSame(1, (int) $order->fresh()->status);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Status inválido.');

        app(EditPaymentStatus::class)($order, 7);
    }

    public function test_changes_the_payment_date_and_rejects_an_invalid_one(): void
    {
        $order = $this->order();

        app(EditPaymentDate::class)($order, '2026-10-20');

        $this->assertSame('2026-10-20', $order->fresh()->payment_date->toDateString());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Data de pagamento inválida.');

        app(EditPaymentDate::class)($order, 'nunca');
    }

    private function order(): FinPaymentOrder
    {
        return FinPaymentOrder::find(DB::table('fin_payment_orders')->insertGetId([
            'ref_armador' => 1, 'created_at' => '2026-10-01', 'status' => 0, 'ref_type' => 1, 'ref_embarque' => 10,
        ]));
    }
}

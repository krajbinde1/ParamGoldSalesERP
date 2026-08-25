<?php

use App\Models\Order;
use App\Services\Orders\OrderBillingTransportCalculator;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Order::query()
            ->whereNotNull('transport_charge_type')
            ->where('transport_charge_type', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($orders): void {
                foreach ($orders as $order) {
                    $order->loadMissing('items');
                    OrderBillingTransportCalculator::reapplyStoredTransport($order);
                }
            });
    }

    public function down(): void
    {
        // Recalculated historical totals cannot be restored automatically.
    }
};

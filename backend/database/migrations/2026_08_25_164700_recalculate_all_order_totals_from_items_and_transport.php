<?php

use App\Services\Orders\OrderBillingTransportCalculator;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        OrderBillingTransportCalculator::persistCorrectedTotalsForAllOrders();
    }

    public function down(): void
    {
        // Recalculated historical totals cannot be restored automatically.
    }
};

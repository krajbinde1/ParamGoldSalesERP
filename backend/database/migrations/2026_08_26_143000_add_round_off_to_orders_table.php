<?php

use App\Models\Order;
use App\Services\Orders\OrderBillingTransportCalculator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'unrounded_grand_total')) {
                $table->decimal('unrounded_grand_total', 14, 2)
                    ->nullable()
                    ->after('grand_total');
            }

            if (! Schema::hasColumn('orders', 'round_off')) {
                $table->decimal('round_off', 14, 2)
                    ->nullable()
                    ->after('unrounded_grand_total');
            }
        });

        Order::query()
            ->orderBy('id')
            ->chunkById(100, function ($orders): void {
                foreach ($orders as $order) {
                    $exact = $order->unrounded_grand_total !== null
                        ? (float) $order->unrounded_grand_total
                        : (float) $order->grand_total;
                    $rounded = OrderBillingTransportCalculator::persistableRoundedTotals($exact);
                    $order->forceFill($rounded)->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'round_off')) {
                $table->dropColumn('round_off');
            }

            if (Schema::hasColumn('orders', 'unrounded_grand_total')) {
                $table->dropColumn('unrounded_grand_total');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boms', function (Blueprint $table) {
            $table->decimal('batch_quantity', 14, 3)->default(1)->after('output_quantity');
            $table->string('batch_unit', 20)->default('Kg')->after('batch_quantity');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::table('boms')->select('id', 'output_quantity', 'standard_batch_size')->get();
            foreach ($rows as $bom) {
                $fromOutput = (float) ($bom->output_quantity ?? 0);
                $fromStandard = (float) ($bom->standard_batch_size ?? 0);
                $quantity = $fromOutput > 0 ? $fromOutput : ($fromStandard > 0 ? $fromStandard : 1);

                DB::table('boms')->where('id', $bom->id)->update([
                    'batch_quantity' => $quantity,
                    'batch_unit' => 'Kg',
                ]);
            }

            return;
        }

        DB::table('boms')->update([
            'batch_quantity' => DB::raw('CASE
                WHEN COALESCE(output_quantity, 0) > 0 THEN output_quantity
                WHEN COALESCE(standard_batch_size, 0) > 0 THEN standard_batch_size
                ELSE 1
            END'),
            'batch_unit' => 'Kg',
        ]);
    }

    public function down(): void
    {
        Schema::table('boms', function (Blueprint $table) {
            $table->dropColumn(['batch_quantity', 'batch_unit']);
        });
    }
};

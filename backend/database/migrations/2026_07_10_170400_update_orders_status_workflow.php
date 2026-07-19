<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('status', 30)->default('draft')->change();
        });

        DB::table('orders')->where('status', 'Draft')->update(['status' => 'draft']);
        DB::table('orders')->where('status', 'Confirmed')->update(['status' => 'approved']);
        DB::table('orders')->where('status', 'Dispatched')->update(['status' => 'dispatched']);
        DB::table('orders')->where('status', 'Delivered')->update(['status' => 'delivered']);
        DB::table('orders')->where('status', 'Cancelled')->update(['status' => 'cancelled']);
    }

    public function down(): void
    {
        DB::table('orders')->where('status', 'draft')->update(['status' => 'Draft']);
        DB::table('orders')->where('status', 'approved')->update(['status' => 'Confirmed']);
        DB::table('orders')->where('status', 'dispatched')->update(['status' => 'Dispatched']);
        DB::table('orders')->where('status', 'delivered')->update(['status' => 'Delivered']);
        DB::table('orders')->where('status', 'cancelled')->update(['status' => 'Cancelled']);

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', ['Draft', 'Confirmed', 'Dispatched', 'Delivered', 'Cancelled'])->default('Draft')->change();
        });
    }
};

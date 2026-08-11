<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dealers', function (Blueprint $table) {
            $table->id();

            $table->string('dealer_code')->unique();

            $table->string('firm_name');
            $table->string('owner_name')->nullable();

            $table->string('mobile', 20);
            $table->string('alternate_mobile', 20)->nullable();
            $table->string('whatsapp', 20)->nullable();

            $table->string('email')->nullable();

            $table->string('gst_no', 20)->nullable();
            $table->string('fertilizer_license_no')->nullable();
            $table->string('pan_no', 20)->nullable();

            $table->text('address')->nullable();

            $table->string('village')->nullable();
            $table->string('taluka');
            $table->string('district');
            $table->string('state');
            $table->string('pincode', 10)->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->decimal('credit_limit', 12, 2)->default(0);
            $table->decimal('outstanding', 12, 2)->default(0);

            $table->enum('dealer_type', [
                'Distributor',
                'Retailer',
                'Wholesaler',
            ])->default('Retailer');

            $table->boolean('status')->default(true);

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dealers');
    }
};
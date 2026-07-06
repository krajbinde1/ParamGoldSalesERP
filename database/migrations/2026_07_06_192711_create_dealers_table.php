<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        
{
    Schema::create('dealers', function (Blueprint $table) {
        $table->id();

        $table->string('dealer_code')->unique();
        $table->string('firm_name');
        $table->string('owner_name');

        $table->string('mobile',20);
        $table->string('alternate_mobile',20)->nullable();

        $table->string('email')->nullable();

        $table->string('gst_no')->nullable();

        $table->text('address');

        $table->string('state');
        $table->string('district');
        $table->string('taluka');
        $table->string('village')->nullable();
        $table->string('pincode',10);

        $table->decimal('credit_limit',12,2)->default(0);
        $table->decimal('outstanding',12,2)->default(0);

        $table->decimal('latitude',10,7)->nullable();
        $table->decimal('longitude',10,7)->nullable();

        $table->boolean('status')->default(true);

        $table->timestamps();
    });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dealers');
    }
};

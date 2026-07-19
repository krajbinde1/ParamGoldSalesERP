<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code')->unique();
            $table->string('full_name');
            $table->string('mobile', 10)->unique();
            $table->string('whatsapp', 10)->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('department');
            $table->string('designation');
            $table->foreignId('reporting_manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('joining_date');
            $table->decimal('salary', 12, 2)->default(0);
            $table->string('base_location');
            $table->decimal('daily_allowance', 12, 2)->default(0);
            $table->decimal('travel_allowance', 12, 2)->default(0);
            $table->string('aadhaar_number', 12)->unique();
            $table->string('pan_number', 10)->unique();
            $table->string('bank_name');
            $table->string('account_number', 30);
            $table->string('ifsc_code', 11);
            $table->string('profile_photo_path')->nullable();
            $table->boolean('status')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['department', 'designation', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};

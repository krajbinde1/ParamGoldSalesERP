<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dealer_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('firm_name');
            $table->string('owner_name');
            $table->string('mobile', 20);
            $table->string('gst_no', 20)->nullable();
            $table->string('state');
            $table->string('district');
            $table->string('taluka');
            $table->string('village');
            $table->text('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('status', 40)->default('draft');
            $table->boolean('duplicate_warning')->default(false);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('manager_name')->nullable();
            $table->timestamp('manager_approved_at')->nullable();
            $table->text('manager_remark')->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('admin_name')->nullable();
            $table->timestamp('admin_approved_at')->nullable();
            $table->text('admin_remark')->nullable();
            $table->string('last_action')->nullable();
            $table->foreignId('last_action_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('last_action_by_name')->nullable();
            $table->timestamp('last_action_at')->nullable();
            $table->text('last_action_remark')->nullable();
            $table->foreignId('dealer_id')->nullable()->constrained('dealers')->nullOnDelete();
            $table->foreignId('party_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'status'], 'dealer_apps_employee_status_idx');
            $table->index('status', 'dealer_apps_status_idx');
            $table->index('mobile', 'dealer_apps_mobile_idx');
        });

        Schema::create('dealer_application_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_application_id')->constrained('dealer_applications')->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->string('file_path');
            $table->string('original_filename');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedInteger('file_size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['dealer_application_id', 'document_type'], 'dealer_app_docs_type_unique');
        });

        Schema::create('dealer_application_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_application_id')->constrained('dealer_applications')->cascadeOnDelete();
            $table->string('event_type', 40);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->text('remark')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['dealer_application_id', 'id'], 'dealer_app_events_app_idx');
        });

        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_id')->unique()->constrained('dealers')->cascadeOnDelete();
            $table->string('party_name');
            $table->string('dealer_code');
            $table->string('owner_name')->nullable();
            $table->string('mobile', 20)->nullable();
            $table->string('gst_no', 20)->nullable();
            $table->string('state')->nullable();
            $table->string('district')->nullable();
            $table->string('taluka')->nullable();
            $table->string('village')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });

        Schema::table('dealer_applications', function (Blueprint $table) {
            $table->foreign('party_id')->references('id')->on('parties')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dealer_applications', function (Blueprint $table) {
            $table->dropForeign(['party_id']);
        });
        Schema::dropIfExists('dealer_application_events');
        Schema::dropIfExists('dealer_application_documents');
        Schema::dropIfExists('dealer_applications');
        Schema::dropIfExists('parties');
    }
};

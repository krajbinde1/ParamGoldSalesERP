<?php

use App\Support\MaharashtraGeography;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maharashtra_districts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('former_name')->nullable();
            $table->string('state', 64)->default(MaharashtraGeography::STATE_NAME);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->unique('name', 'mh_districts_name_unique');
        });

        Schema::create('maharashtra_talukas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained('maharashtra_districts')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->unique(['district_id', 'name'], 'mh_talukas_district_name_unique');
        });

        Schema::create('crops', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('status')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('name', 'crops_name_unique');
        });

        Schema::create('farmers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('mobile', 10);
            $table->foreignId('district_id')->nullable()->constrained('maharashtra_districts')->nullOnDelete();
            $table->foreignId('taluka_id')->nullable()->constrained('maharashtra_talukas')->nullOnDelete();
            $table->string('village');
            $table->foreignId('created_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('first_contact_date')->nullable();
            $table->date('last_activity_date')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->unique('mobile', 'farmers_mobile_unique');
            $table->index(['district_id', 'taluka_id'], 'farmers_district_taluka_idx');
            $table->index('name', 'farmers_name_idx');
        });

        Schema::table('field_activities', function (Blueprint $table) {
            $table->foreignId('farmer_id')->nullable()->after('employee_id')->constrained('farmers')->nullOnDelete();
            $table->string('farmer_mobile', 10)->nullable()->after('farmer_name');
            $table->foreignId('district_id')->nullable()->after('farmer_mobile')->constrained('maharashtra_districts')->nullOnDelete();
            $table->string('district')->nullable()->after('district_id');
            $table->foreignId('taluka_id')->nullable()->after('taluka')->constrained('maharashtra_talukas')->nullOnDelete();
            $table->foreignId('crop_id')->nullable()->after('taluka_id')->constrained('crops')->nullOnDelete();
            $table->string('activity_type', 40)->nullable()->after('crop_id');
            $table->text('remark')->nullable()->after('activity_type');
        });

        Schema::create('field_activity_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_activity_id')->constrained('field_activities')->cascadeOnDelete();
            $table->foreignId('crop_id')->nullable()->constrained('crops')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('dosage')->nullable();
            $table->text('remark')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('field_activity_id', 'fa_reco_activity_idx');
            $table->index('product_id', 'fa_reco_product_idx');
        });

        $now = now();
        foreach (MaharashtraGeography::districts() as $index => $district) {
            $districtId = DB::table('maharashtra_districts')->insertGetId([
                'name' => $district['name'],
                'former_name' => $district['former_name'],
                'state' => MaharashtraGeography::STATE_NAME,
                'sort_order' => $index + 1,
                'status' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($district['talukas'] as $talukaIndex => $talukaName) {
                DB::table('maharashtra_talukas')->insert([
                    'district_id' => $districtId,
                    'name' => $talukaName,
                    'sort_order' => $talukaIndex + 1,
                    'status' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach (MaharashtraGeography::defaultCrops() as $cropIndex => $cropName) {
            DB::table('crops')->insert([
                'name' => $cropName,
                'status' => true,
                'sort_order' => $cropIndex + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('field_activity_recommendations');

        Schema::table('field_activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('farmer_id');
            $table->dropConstrainedForeignId('district_id');
            $table->dropConstrainedForeignId('taluka_id');
            $table->dropConstrainedForeignId('crop_id');
            $table->dropColumn(['farmer_mobile', 'district', 'activity_type', 'remark']);
        });

        Schema::dropIfExists('farmers');
        Schema::dropIfExists('crops');
        Schema::dropIfExists('maharashtra_talukas');
        Schema::dropIfExists('maharashtra_districts');
    }
};

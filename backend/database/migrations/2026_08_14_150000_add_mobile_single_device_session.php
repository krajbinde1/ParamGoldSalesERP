<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'active_mobile_session_id')) {
                $table->uuid('active_mobile_session_id')->nullable()->after('remember_token');
            }
            if (! Schema::hasColumn('users', 'active_mobile_device_id')) {
                $table->string('active_mobile_device_id', 64)->nullable()->after('active_mobile_session_id');
            }
            if (! Schema::hasColumn('users', 'active_mobile_token_id')) {
                $table->unsignedBigInteger('active_mobile_token_id')->nullable()->after('active_mobile_device_id');
            }
            if (! Schema::hasColumn('users', 'active_mobile_login_at')) {
                $table->timestamp('active_mobile_login_at')->nullable()->after('active_mobile_token_id');
            }
        });

        if (! Schema::hasTable('revoked_mobile_tokens')) {
            Schema::create('revoked_mobile_tokens', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('token_hash', 64);
                $table->string('device_id', 64)->nullable();
                $table->timestamp('revoked_at');
                $table->timestamps();

                $table->unique('token_hash');
                $table->index(['user_id', 'revoked_at']);
            });
        }

        Schema::table('device_tokens', function (Blueprint $table): void {
            if (! Schema::hasColumn('device_tokens', 'installation_id')) {
                $table->string('installation_id', 64)->nullable()->after('device_name');
                $table->index('installation_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table): void {
            if (Schema::hasColumn('device_tokens', 'installation_id')) {
                $table->dropIndex(['installation_id']);
                $table->dropColumn('installation_id');
            }
        });

        Schema::dropIfExists('revoked_mobile_tokens');

        Schema::table('users', function (Blueprint $table): void {
            foreach ([
                'active_mobile_login_at',
                'active_mobile_token_id',
                'active_mobile_device_id',
                'active_mobile_session_id',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table): void {
            if (! Schema::hasColumn('credit_notes', 'client_request_id')) {
                $table->string('client_request_id', 64)->nullable()->after('credit_note_no');
            }
        });

        if (! Schema::hasIndex('credit_notes', 'credit_notes_client_request_id_unique')) {
            Schema::table('credit_notes', function (Blueprint $table): void {
                $table->unique('client_request_id', 'credit_notes_client_request_id_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('credit_notes', 'credit_notes_client_request_id_unique')) {
            Schema::table('credit_notes', function (Blueprint $table): void {
                $table->dropUnique('credit_notes_client_request_id_unique');
            });
        }

        if (Schema::hasColumn('credit_notes', 'client_request_id')) {
            Schema::table('credit_notes', function (Blueprint $table): void {
                $table->dropColumn('client_request_id');
            });
        }
    }
};

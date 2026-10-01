<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'verification_status')) {
            Schema::table('users', function (Blueprint $table) {
                $table->enum('verification_status', [
                    'unverified',
                    'pending',
                    'verified',
                ])->default('unverified')->after('role');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'verification_status')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('verification_status');
            });
        }
    }
};
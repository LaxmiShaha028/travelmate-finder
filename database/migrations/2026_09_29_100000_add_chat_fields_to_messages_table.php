<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('messages', 'sender_id')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->foreignId('sender_id')->nullable()->constrained('users')->cascadeOnDelete();
            });

            if (Schema::hasColumn('messages', 'user_id')) {
                DB::table('messages')
                    ->whereNull('sender_id')
                    ->update(['sender_id' => DB::raw('user_id')]);
            }
        }

        if (Schema::hasColumn('messages', 'user_id')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('messages', 'sender_id')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropForeign(['sender_id']);
                $table->dropColumn('sender_id');
            });
        }
    }
};
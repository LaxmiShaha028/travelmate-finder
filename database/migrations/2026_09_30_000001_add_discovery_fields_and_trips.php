<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_preferences', function (Blueprint $table) {
            $table->date('travel_start')->nullable()->index();
            $table->date('travel_end')->nullable();
            $table->unsignedSmallInteger('duration_days')->nullable();
        });

        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('destination')->index();
            $table->text('description')->nullable();
            $table->date('start_date')->index();
            $table->date('end_date');
            $table->unsignedSmallInteger('duration_days');
            $table->decimal('budget', 10, 2);
            $table->string('travel_style')->nullable();
            $table->unsignedSmallInteger('max_travelers')->default(2);
            $table->string('status')->default('open')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
        Schema::table('travel_preferences', function (Blueprint $table) {
            $table->dropColumn(['travel_start', 'travel_end', 'duration_days']);
        });
    }
};

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
     Schema::create('conversations', function (Blueprint $table) {
    $table->id();
    $table->string('name')->nullable(); // গ্রুপ হলে নাম থাকবে
    $table->enum('type', ['private', 'group'])->default('private');
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};

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
        Schema::create('faculty_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')
                  ->constrained('faculties')
                  ->onDelete('cascade');

            $table->enum('day_of_week', ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"]);
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faculty_availabilities');
    }
};

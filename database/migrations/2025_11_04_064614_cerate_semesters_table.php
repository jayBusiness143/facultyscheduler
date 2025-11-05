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
        // PERPEKTO NA NI. WALA NAY USABUNON.
        Schema::create('semesters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->onDelete('cascade');
            $table->string('year_level');
            $table->string('semester_level');
            $table->integer('status')->default(0)->comment("0 = active, 1 = inactive"); 
            $table->date('start_date')->nullable(); // Dili na kinahanglan ang ->after() pero okay ra naa
            $table->date('end_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // USA RA KA LINYA ANG DAPAT NAA DINHI
        Schema::dropIfExists('semesters');
    }
};

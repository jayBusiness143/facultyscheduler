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
        // FIX 1: Pinalitan ang table name sa plural ('programs') para sumunod sa convention.
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->string('program_name');
            $table->string('abbreviation');
            $table->year('year_from');
            $table->year('year_to');
            $table->integer('status')->default(0)->comment("0 = active, 1 = inactive");
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programs');
    }
};
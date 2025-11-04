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
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semester_id')->constrained('semesters')->onDelete('cascade');
            $table->string('subject_code');
            $table->string('des_title');
            $table->integer('total_units');
            $table->integer('lec_units');
            $table->integer('lab_units');
            $table->integer('total_hrs');
            $table->integer('total_lec_hrs');
            $table->integer('total_lab_hrs');
            $table->string('pre_requisite')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};

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
        Schema::create('create_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('faculty_loading_id');
            $table->integer('year_level'); 
            $table->string('section');
            $table->timestamps();
            $table->foreign('faculty_loading_id')
                  ->references('id')
                  ->on('faculty_loadings')
                  ->onDelete('cascade');
            $table->unique('faculty_loading_id', 'unique_loading_schedule');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('create_schedules');
    }
};

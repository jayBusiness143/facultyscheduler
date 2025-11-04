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
        Schema::create('faculties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->string('designation');
            $table->string('department');
            $table->string('profile_picture')->nullable(); // Optional profile picture
            $table->integer('deload_units')->default(0);
            $table->integer('t_load_units')->default(0);
            $table->integer('overload_units')->default(0);
            $table->integer('status')->default(0)->comment("0 = active, 1 = inactive");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faculties');
    }
};

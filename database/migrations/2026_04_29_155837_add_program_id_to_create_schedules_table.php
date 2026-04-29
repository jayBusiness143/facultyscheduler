<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('create_schedules', function (Blueprint $table) {
            if (!Schema::hasColumn('create_schedules', 'program_id')) {
                $table->unsignedBigInteger('program_id')->nullable()->after('id');
            }
        });

        // Ensure the column can support ON DELETE SET NULL.
        DB::statement('ALTER TABLE create_schedules MODIFY program_id BIGINT UNSIGNED NULL');

        // Backfill old rows by deriving program_id through
        // faculty_loadings -> subjects -> semesters.
        DB::statement('UPDATE create_schedules cs JOIN faculty_loadings fl ON fl.id = cs.faculty_loading_id JOIN subjects s ON s.id = fl.subject_id JOIN semesters sem ON sem.id = s.semester_id SET cs.program_id = sem.program_id WHERE cs.program_id IS NULL OR cs.program_id = 0');

        Schema::table('create_schedules', function (Blueprint $table) {
            $table->foreign('program_id')
                ->references('id')
                ->on('programs')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('create_schedules', function (Blueprint $table) {
            if (Schema::hasColumn('create_schedules', 'program_id')) {
                $table->dropForeign(['program_id']);
                $table->dropColumn('program_id');
            }
        });
    }
};

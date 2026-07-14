<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Submissions: speed up centre-scoped queries, student history, and result polling
        Schema::table('submissions', function (Blueprint $table) {
            $table->index('centre_name',     'idx_submissions_centre');
            $table->index('student_id',      'idx_submissions_student');
            $table->index('exam_config_id',  'idx_submissions_exam');
            $table->index('submitted_at',    'idx_submissions_submitted_at');
            $table->index('submission_id',   'idx_submissions_uuid');
        });

        // Submission answers: speed up per-submission answer lookups
        Schema::table('submission_answers', function (Blueprint $table) {
            $table->index('submission_id', 'idx_sub_answers_submission');
            $table->index('question_id',   'idx_sub_answers_question');
        });

        // Students: speed up centre-scoped queries
        Schema::table('students', function (Blueprint $table) {
            $table->index('centre_name', 'idx_students_centre');
        });

        // Exam configs: speed up active exam lookups
        Schema::table('exam_configs', function (Blueprint $table) {
            $table->index('active', 'idx_exam_configs_active');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex('idx_submissions_centre');
            $table->dropIndex('idx_submissions_student');
            $table->dropIndex('idx_submissions_exam');
            $table->dropIndex('idx_submissions_submitted_at');
            $table->dropIndex('idx_submissions_uuid');
        });

        Schema::table('submission_answers', function (Blueprint $table) {
            $table->dropIndex('idx_sub_answers_submission');
            $table->dropIndex('idx_sub_answers_question');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex('idx_students_centre');
        });

        Schema::table('exam_configs', function (Blueprint $table) {
            $table->dropIndex('idx_exam_configs_active');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_exam_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_config_id')->constrained()->cascadeOnDelete();
            $table->string('exam_code')->nullable();
            $table->string('student_name')->nullable();
            $table->string('exam_title')->nullable();
            $table->string('centre_name')->nullable();
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->unsignedInteger('extra_minutes')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['student_id', 'exam_config_id'], 'live_sessions_student_exam_unique');
            $table->index('last_seen_at');
            $table->index('centre_name');
        });

        Schema::create('exam_answer_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_config_id')->constrained()->cascadeOnDelete();
            $table->json('answers')->nullable();
            $table->json('marked_for_review')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'exam_config_id'], 'drafts_student_exam_unique');
        });

        Schema::create('proctoring_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_config_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->string('message', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['exam_config_id', 'created_at']);
            $table->index(['student_id', 'exam_config_id']);
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->string('client_submission_id', 64)->nullable()->after('submission_id');
            $table->unique(['student_id', 'exam_config_id', 'client_submission_id'], 'submissions_client_idempotent');
            $table->index(['student_id', 'exam_config_id'], 'idx_submissions_student_exam');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropUnique('submissions_client_idempotent');
            $table->dropIndex('idx_submissions_student_exam');
            $table->dropColumn('client_submission_id');
        });

        Schema::dropIfExists('proctoring_events');
        Schema::dropIfExists('exam_answer_drafts');
        Schema::dropIfExists('live_exam_sessions');
    }
};

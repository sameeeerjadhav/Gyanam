<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_exam_sessions', function (Blueprint $table) {
            $table->json('question_ids')->nullable()->after('extra_minutes');
            $table->unsignedInteger('attempt_number')->default(1)->after('question_ids');
        });

        Schema::table('exam_answer_drafts', function (Blueprint $table) {
            $table->unsignedInteger('attempt_number')->default(1)->after('exam_config_id');
        });
    }

    public function down(): void
    {
        Schema::table('live_exam_sessions', function (Blueprint $table) {
            $table->dropColumn(['question_ids', 'attempt_number']);
        });

        Schema::table('exam_answer_drafts', function (Blueprint $table) {
            $table->dropColumn('attempt_number');
        });
    }
};

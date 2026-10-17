<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-attempt option display order: { "questionId": ["c","a","d","b"], ... }
 * Option ids stay stable for scoring; only display order is shuffled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_exam_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('live_exam_sessions', 'option_orders')) {
                $table->json('option_orders')->nullable()->after('question_ids');
            }
        });
    }

    public function down(): void
    {
        Schema::table('live_exam_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('live_exam_sessions', 'option_orders')) {
                $table->dropColumn('option_orders');
            }
        });
    }
};

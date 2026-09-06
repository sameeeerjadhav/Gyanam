<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_exam_sessions', function (Blueprint $table) {
            if (!Schema::hasColumn('live_exam_sessions', 'proctor_photo_path')) {
                $table->string('proctor_photo_path', 255)->nullable()->after('last_seen_at');
            }
            if (!Schema::hasColumn('live_exam_sessions', 'camera_active')) {
                $table->boolean('camera_active')->default(false)->after('proctor_photo_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('live_exam_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('live_exam_sessions', 'camera_active')) {
                $table->dropColumn('camera_active');
            }
            if (Schema::hasColumn('live_exam_sessions', 'proctor_photo_path')) {
                $table->dropColumn('proctor_photo_path');
            }
        });
    }
};

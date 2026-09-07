<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (!Schema::hasColumn('students', 'photo_url')) {
                $table->string('photo_url', 500)->nullable()->after('time_window');
            }
            if (!Schema::hasColumn('students', 'course')) {
                $table->string('course', 255)->nullable()->after('photo_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            if (Schema::hasColumn('students', 'course')) {
                $table->dropColumn('course');
            }
            if (Schema::hasColumn('students', 'photo_url')) {
                $table->dropColumn('photo_url');
            }
        });
    }
};

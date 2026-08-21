<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add attempt control and assignment tracking to exam_student pivot.
     */
    public function up(): void
    {
        Schema::table('exam_student', function (Blueprint $table) {
            // Who assigned this exam (admin/ATC/DLC user id)
            $table->unsignedBigInteger('assigned_by_user_id')->nullable()->after('exam_config_id');
            // How many times this student is allowed to attempt
            $table->unsignedTinyInteger('max_attempts')->default(1)->after('assigned_by_user_id');
            // Timestamp when assignment was made
            $table->timestamp('assigned_at')->nullable()->after('max_attempts');

            $table->foreign('assigned_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exam_student', function (Blueprint $table) {
            $table->dropForeign(['assigned_by_user_id']);
            $table->dropColumn(['assigned_by_user_id', 'max_attempts', 'assigned_at']);
        });
    }
};

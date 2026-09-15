<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_configs', function (Blueprint $table) {
            $table->boolean('is_global_practice')->default(false)->after('active');
            $table->index('is_global_practice', 'idx_exam_configs_global_practice');
        });
    }

    public function down(): void
    {
        Schema::table('exam_configs', function (Blueprint $table) {
            $table->dropIndex('idx_exam_configs_global_practice');
            $table->dropColumn('is_global_practice');
        });
    }
};

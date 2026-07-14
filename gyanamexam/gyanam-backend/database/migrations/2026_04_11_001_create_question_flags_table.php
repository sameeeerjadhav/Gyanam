<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('centre_name')->nullable();          // for scoping
            $table->text('reason');                              // student's reason
            $table->enum('status', ['pending', 'reviewed', 'dismissed'])->default('pending');
            $table->text('admin_note')->nullable();             // admin response
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_flags');
    }
};

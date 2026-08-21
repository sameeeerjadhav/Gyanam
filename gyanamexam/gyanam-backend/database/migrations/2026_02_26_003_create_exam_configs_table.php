<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_configs', function (Blueprint $table) {
            $table->id();
            $table->string('exam_id')->unique(); // e.g. exam_demo_001
            $table->string('title');
            $table->string('subject');
            $table->enum('exam_type', ['demo', 'main', 'practice'])->default('main');
            $table->integer('duration')->default(60); // minutes
            $table->integer('total_questions')->default(20);
            $table->integer('passing_score')->default(60); // percentage
            $table->foreignId('question_bank_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('instructions')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('randomize_questions')->default(true);
            $table->timestamps();
        });

        // Pivot: which students are assigned to which exams
        Schema::create('exam_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_config_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['exam_config_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_student');
        Schema::dropIfExists('exam_configs');
    }
};

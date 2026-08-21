<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->string('submission_id')->unique(); // UUID or generated ID
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_config_id')->constrained()->cascadeOnDelete();
            $table->string('exam_title')->nullable(); // denormalised for quick display
            $table->string('student_name')->nullable();
            $table->string('centre_name')->nullable(); // for scoping
            $table->integer('score')->default(0); // percentage
            $table->integer('correct_answers')->default(0);
            $table->integer('total_questions')->default(0);
            $table->enum('result', ['pass', 'fail'])->nullable();
            $table->integer('duration_taken')->nullable(); // seconds
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('submission_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('selected_answer')->nullable(); // option id
            $table->boolean('is_correct')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_answers');
        Schema::dropIfExists('submissions');
    }
};

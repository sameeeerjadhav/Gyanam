<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_banks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('subject');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Pivot: which centres can see/use this bank
        Schema::create('question_bank_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_bank_id')->constrained()->cascadeOnDelete();
            $table->string('centre_id'); // matches users.centre_id
            $table->timestamps();
            $table->unique(['question_bank_id', 'centre_id']);
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_bank_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->json('options'); // [{id: "a", text: "London"}, ...]
            $table->string('correct_answer'); // option id e.g. "b"
            $table->integer('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
        Schema::dropIfExists('question_bank_assignments');
        Schema::dropIfExists('question_banks');
    }
};

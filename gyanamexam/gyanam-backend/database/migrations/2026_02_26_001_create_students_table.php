<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('identifier')->unique(); // Hall ticket / student ID
            $table->string('name');
            $table->string('centre_name');
            $table->enum('exam_slot', ['SLOT1', 'SLOT2', 'SLOT3'])->default('SLOT1');
            $table->enum('time_window', ['MORNING', 'AFTERNOON', 'EVENING'])->default('MORNING');
            $table->string('password')->nullable(); // optional for future OTP use
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};

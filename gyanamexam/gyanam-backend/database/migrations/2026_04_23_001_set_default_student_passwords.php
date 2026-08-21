<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Set default password ("password") for all existing students
 * who don't have a password set yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        $hashed = Hash::make('password');

        DB::table('students')
            ->whereNull('password')
            ->orWhere('password', '')
            ->update(['password' => $hashed]);
    }

    public function down(): void
    {
        // Reverse: set password back to null
        DB::table('students')->update(['password' => null]);
    }
};

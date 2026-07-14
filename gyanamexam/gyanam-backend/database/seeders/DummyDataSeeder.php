<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Exam\Models\Student;
use Modules\Exam\Models\QuestionBank;
use Modules\Exam\Models\Question;
use Exception;

class DummyDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('questions')->truncate();
        DB::table('question_bank_assignments')->truncate();
        DB::table('question_banks')->truncate();
        DB::table('students')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        DB::beginTransaction();

        $adminUser = DB::table('users')->where('role', 'admin')->first();
        $adminId = $adminUser ? $adminUser->id : 1;
        
        $centres = ['Delhi Centre', 'Mumbai Centre', 'Bangalore Centre', 'Chennai Centre', 'Kolkata Centre'];
        $slots = ['SLOT1', 'SLOT2', 'SLOT3'];
        $windows = ['MORNING', 'AFTERNOON', 'EVENING'];

        // 1. Create 50 Dummy Students
        $studentsData = [];
        for ($i = 1; $i <= 50; $i++) {
            $studentsData[] = [
                'name' => 'Demo Student ' . $i,
                'identifier' => 'STU-2026-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'centre_name' => $centres[array_rand($centres)],
                'exam_slot' => $slots[array_rand($slots)],
                'time_window' => $windows[array_rand($windows)],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('students')->insert($studentsData);
        $this->command->info('✅ Inserted 50 Dummy Students.');

        // 2. Create 10 Question Banks
        $subjects = ['Mathematics', 'Physics', 'Chemistry', 'Biology', 'Computer Science', 'English', 'History', 'Geography', 'Economics', 'General Knowledge'];
        
        foreach ($subjects as $index => $subject) {
            $bankId = DB::table('question_banks')->insertGetId([
                'title' => $subject . ' - Assessment ' . rand(1, 5),
                'subject' => $subject,
                'created_by_user_id' => $adminId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3. Add 15 Questions per Bank
            $questionsData = [];
            for ($q = 1; $q <= 15; $q++) {
                $questionsData[] = [
                    'question_bank_id' => $bankId,
                    'text' => "Sample Question $q for $subject. What is the correct answer?",
                    'options' => json_encode([
                        ['id' => 'a', 'text' => "Option A for Q$q"],
                        ['id' => 'b', 'text' => "Option B for Q$q"],
                        ['id' => 'c', 'text' => "Option C for Q$q"],
                        ['id' => 'd', 'text' => "Option D for Q$q"],
                    ]),
                    'correct_answer' => ['a', 'b', 'c', 'd'][array_rand(['a', 'b', 'c', 'd'])],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('questions')->insert($questionsData);
        }
        
        $this->command->info('✅ Inserted 10 Question Banks with 15 questions each.');
        DB::commit();
    }
}

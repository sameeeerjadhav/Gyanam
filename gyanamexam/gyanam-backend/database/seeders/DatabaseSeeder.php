<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Student;
use App\Models\QuestionBank;
use App\Models\Question;
use App\Models\ExamConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Admin ────────────────────────────────────────────
        User::updateOrCreate(['username' => 'admin'], [
            'name'      => 'System Admin',
            'email'     => 'admin@gyanam.edu',
            'password'  => Hash::make('admin123'),
            'role'      => 'admin',
            'centre_id' => null,
        ]);

        // ── ATC Officers ─────────────────────────────────────
        $atcUsers = [
            ['username' => 'atc',         'password' => 'atc123',    'centre_id' => 'Center A', 'name' => 'ATC Officer (Center A)'],
            ['username' => 'atc_delhi',   'password' => 'delhi123',  'centre_id' => 'Center A', 'name' => 'ATC Officer Delhi'],
            ['username' => 'atc_mumbai',  'password' => 'mumbai123', 'centre_id' => 'Center B', 'name' => 'ATC Officer Mumbai'],
            ['username' => 'atc_kolkata', 'password' => 'kolkata123','centre_id' => 'Center C', 'name' => 'ATC Officer Kolkata'],
        ];
        foreach ($atcUsers as $u) {
            User::updateOrCreate(['username' => $u['username']], [
                'name'      => $u['name'],
                'email'     => $u['username'] . '@gyanam.edu',
                'password'  => Hash::make($u['password']),
                'role'      => 'atc',
                'centre_id' => $u['centre_id'],
            ]);
        }

        // ── DLC Officers ─────────────────────────────────────
        $dlcUsers = [
            ['username' => 'dlc',       'password' => 'dlc123',   'centre_id' => 'Center A', 'name' => 'DLC Officer (Center A)'],
            ['username' => 'dlc_north', 'password' => 'north123', 'centre_id' => 'Center B', 'name' => 'DLC Officer North'],
        ];
        foreach ($dlcUsers as $u) {
            User::updateOrCreate(['username' => $u['username']], [
                'name'      => $u['name'],
                'email'     => $u['username'] . '@gyanam.edu',
                'password'  => Hash::make($u['password']),
                'role'      => 'dlc',
                'centre_id' => $u['centre_id'],
            ]);
        }

        echo "✅ Seeded " . User::count() . " portal users.\n";

        $admin = User::where('role', 'admin')->first();

        // ── Demo Students ────────────────────────────────────
        $student1 = Student::updateOrCreate(['identifier' => 'STUDENT001'], [
            'name'        => 'Demo Student 1',
            'centre_name' => 'Center A',
            'exam_slot'   => 'SLOT1',
            'time_window' => 'MORNING',
        ]);

        $student2 = Student::updateOrCreate(['identifier' => 'TEST123'], [
            'name'        => 'Test Candidate',
            'centre_name' => 'Test Center',
            'exam_slot'   => 'SLOT1',
            'time_window' => 'MORNING',
        ]);

        // ── Mock Question Bank & Questions ───────────────────
        $bank = QuestionBank::updateOrCreate(['title' => 'General Knowledge'], [
            'subject'             => 'GK',
            'created_by_user_id'  => $admin->id,
        ]);

        $questions = [
            ['text' => 'What is the capital of India?', 'options' => [['id'=>1,'text'=>'Mumbai'],['id'=>2,'text'=>'New Delhi'],['id'=>3,'text'=>'Kolkata'],['id'=>4,'text'=>'Chennai']], 'correct' => 2],
            ['text' => 'Which planet is known as the Red Planet?', 'options' => [['id'=>1,'text'=>'Earth'],['id'=>2,'text'=>'Mars'],['id'=>3,'text'=>'Jupiter'],['id'=>4,'text'=>'Saturn']], 'correct' => 2],
            ['text' => 'What is 5 + 5?', 'options' => [['id'=>1,'text'=>'8'],['id'=>2,'text'=>'10'],['id'=>3,'text'=>'12'],['id'=>4,'text'=>'15']], 'correct' => 2],
        ];

        foreach ($questions as $q) {
            Question::updateOrCreate(['question_bank_id' => $bank->id, 'text' => $q['text']], [
                'options'        => $q['options'],
                'correct_answer' => $q['correct'],
            ]);
        }

        // ── Mock Exam Config ─────────────────────────────────
        $exam = ExamConfig::updateOrCreate(['exam_id' => 'GK_BASIC_01'], [
            'title'              => 'Basic General Knowledge',
            'subject'            => 'General Knowledge',
            'duration'           => 30,
            'total_questions'    => 3,
            'passing_score'      => 40,
            'question_bank_id'   => $bank->id,
            'created_by_user_id' => $admin->id,
            'active'             => true,
            'randomize_questions'=> true,
        ]);

        // ── Assign Exam to Students ──────────────────────────
        $student1->exams()->syncWithoutDetaching([$exam->id]);
        $student2->exams()->syncWithoutDetaching([$exam->id]);

        echo "✅ Seeded " . Student::count() . " students and assigned exams.\n";
    }
}

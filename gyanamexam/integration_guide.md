# Integration Guide: Gyanam Exam Portal ↔ External Portal

This document provides technical instructions for integrating the **Gyanam Exam Portal** with an external student management system.

## 🏗️ System Architecture
- **Backend**: Laravel 12 API (Standard REST)
- **Frontend**: Vanilla JavaScript (ES Modules)
- **Real-time**: Laravel Reverb (WebSockets on port 6001)
- **Database**: MySQL/PostgreSQL with indices on `identifier`, `student_id`, and `exam_id`.

---

## 🔑 Key Integration Point: The Student Identifier
The primary link between systems is the `identifier` field in the `students` table.
- **External System**: Should provide its unique User ID (e.g., `STU-2025-001`).
- **Exam System**: Stores this in the `identifier` column. 
- **Mapping**: When the external system requests exam data, it should always pass this `identifier`.

---

## 📡 Useful API Endpoints (from External Portal)

The external portal can communicate with the Exam API using a Service Token.

### 1. Sync / Register Student
If a student enrolls in a course in the main portal, register them here:
- **POST** `/api/students`
- **Body**: `{ "name": "...", "identifier": "EXTERNAL_ID", "centre_name": "...", "exam_slot": "...", "time_window": "..." }`

### 2. Auto-Assign Exam based on Course
When a student enrolls in "Course A", the external portal can trigger an exam assignment:
- **POST** `/api/exam-assignments/bulk`
- **Body**: `{ "student_ids": [INTERNAL_DB_ID], "exam_id": "EXAM_DB_ID", "max_attempts": 1 }`

### 3. Fetch Results for Academic Records
To pull a student's score back into the main portal:
- **GET** `/api/results?student_identifier=EXTERNAL_ID`

---

## 🛠️ Frontend Integration Logic
If you want to embed the Exam Portal or link to it:

### The Student Portal (`index.html`)
The student portal expects the student to log in using their `Student ID` (which should match the `identifier` synced from the external system).

### The "Exam Gate" Pattern
1. Student clicks "Take Exam" in your friend's portal.
2. The friend's portal checks if an assignment exists in the Exam API.
3. If yes, it redirects the student to `index.html` with their ID pre-filled or handled via a shared session/token.

---

## 📂 Project Structure for the Integration Agent
- **`/admin.html`**: The main entry for the admin portal. It uses a modular router.
- **`/src/modules/`**: Contains the logic for different sections. 
    - `StudentsModule.js` is where you should look for student-related frontend logic.
- **`/src/services/APIClient.js`**: The single source of truth for all API communication. Add new integration methods here.
- **`/gyanam-backend/app/Http/Controllers/Api/`**: Laravel controllers handling the business logic.

---

## ⚠️ Important Considerations
1. **CORS**: You must update `gyanam-backend/config/cors.php` to allow the domain of the external portal.
2. **Environment**: Ensure both systems share the same database or use a secure webhook system to keep data in sync.
3. **Transactions**: The `StudentExamController.php` uses DB transactions to ensure results are only saved if the entire submission process succeeds. Maintain this pattern for data integrity.

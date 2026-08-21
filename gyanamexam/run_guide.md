# Running the Gyanam Exam Portal Locally

To test the Gyanam Exam Portal on your machine, you need to run three separate commands. Both the admin and student portals rely on a background Laravel API and a WebSocket server.

---

## 1. Start the Laravel Backend API
This server handles the database logic, authentication, students, questions, and saving exam results.

```bash
cd "gyanam-backend"
php artisan serve --port=8000
```
> **Base URL:** `http://localhost:8000`

---

## 2. Start the Real-Time WebSockets Server (Reverb)
This server pushes real-time updates (like seeing students log in and take their exams) directly to the Live Monitoring page in the admin portal.

```bash
cd "gyanam-backend"
php artisan reverb:start --port=6001 --host=127.0.0.1
```
> **WebSocket URL:** `ws://127.0.0.1:6001`

---

## 3. Start the Frontend Application (Student & Admin)
This launches a simple static HTTP server to serve the HTML/CSS/JS files for both the student and admin interfaces.

```bash
# From the root "Gyanam Exam Portal" folder
npx -y http-server . -p 3000
```

> **Admin Portal:** [http://localhost:3000/admin.html](http://localhost:3000/admin.html)
> **Student Portal:** [http://localhost:3000/index.html](http://localhost:3000/index.html)

---

### Tips for Testing:
1. Make sure you leave all three terminals open and running while you test.
2. Log in to the Admin Portal first (`admin` / `admin123`) to assign an exam to a student.
3. Open a second "Incognito" window to log in as a student using their unique `Student ID` (e.g. `STU-2026-001`).
4. Watch the Admin's "Live Monitoring" page auto-update when the student starts their exam!

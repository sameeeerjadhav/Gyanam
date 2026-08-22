# Gyanam Exam Portal (gyanamexam)

Production exam system: vanilla JS student/admin SPAs + Laravel API (`gyanam-backend`).

**Capacity:** Not safe for ~1000 concurrent students without MySQL + Redis and the hardening in this tree. See [docs/PRODUCTION_CAPACITY.md](docs/PRODUCTION_CAPACITY.md).

## Stack

| Layer | Tech |
|-------|------|
| Student UI | `index.html` → `src/main.js` → `ExamPage.js` |
| Admin UI | `admin.html` |
| API | Laravel + Sanctum (`gyanam-backend`) |
| Live sessions | `live_exam_sessions` table (atomic) |
| Answer drafts | `exam_answer_drafts` + localStorage autosave |
| Portal link | `gyanamindia/includes/exam_integration.php` (Bearer `EXAM_API_TOKEN`) |

## Student exam flow

1. Login → assigned exams  
2. `GET /student/exam/{id}/questions` — shuffle cached, register live session, return draft + `remaining_seconds`  
3. Answers autosaved locally + `POST .../answers` (debounced)  
4. Heartbeat every **45s** (no WebSocket spam)  
5. `POST .../submit` — server deadline, attempt lock, idempotent `client_submission_id`

## Local run

See [run_guide.md](run_guide.md). After pull:

```bash
cd gyanam-backend
cp .env.example .env   # set MySQL + CACHE_STORE=redis for load tests
php artisan migrate
php artisan serve
```

Serve the static frontend separately (Python/`npx http-server`).

## Load test

```bash
k6 run -e BASE_URL=http://127.0.0.1:8000/api/v1 \
  -e STUDENT_ID=TEST001 -e STUDENT_PASS=password -e EXAM_ID=1 \
  load-tests/exam-load.k6.js
```

## Legacy (do not use)

`ExamEngine.js`, `SecurityMonitor.js`, `StateManager` exam persistence, and README claims of “mock auth / autosave every 5s” are **obsolete**. Production path is `ExamPage.js` only.

## Admin

Live monitoring polls every 10s (cached ~3s server-side). Extend time uses `examConfigId`. Results API supports `?page=&per_page=&q=`.

# Chaos / readiness checklist (Phase 5)

Run after migrations and with MySQL + Redis configured.

## Automated

```bash
k6 run -e BASE_URL=... -e STUDENT_ID=... -e STUDENT_PASS=... -e EXAM_ID=... \
  gyanamexam/load-tests/exam-load.k6.js
```

Pass if thresholds in the script succeed (error rate &lt; 1%, heartbeat p95 &lt; 500ms).

## Manual chaos

| # | Steps | Expected |
|---|--------|----------|
| 1 | Start exam, answer 5 Qs, hard refresh | Answers restore from localStorage/server draft |
| 2 | Double-click Submit | One submission; second returns same `submission_id` (idempotent) |
| 3 | Wait past duration + 30s grace, submit | 422 time expired |
| 4 | Disconnect network, answer more Qs, reconnect | Local draft kept; autosave resumes |
| 5 | Admin +Time on live session | Student timer increases on next heartbeat |
| 6 | Two browsers same student, both submit | Attempt lock — only one success if max_attempts=1 |

Record results in your release notes before any 1000-student window.

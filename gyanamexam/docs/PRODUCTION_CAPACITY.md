# Gyanamexam — production capacity checklist

## Gate before a ~1000-student simultaneous exam

Do **not** schedule a 1000-student slot until all items below pass.

### Required `.env` (production)

| Variable | Required value | Why |
|----------|----------------|-----|
| `DB_CONNECTION` | `mysql` | SQLite cannot handle concurrent writers |
| `CACHE_STORE` | `redis` (preferred) or `database` only for tiny exams | Heartbeats + question cache; Redis for 1000 |
| `QUEUE_CONNECTION` | `database` or `redis` | Unused on submit path today; keep healthy |
| `BROADCAST_CONNECTION` | `log` or `reverb` | Heartbeats no longer broadcast; start/submit only |
| `APP_DEBUG` | `false` | |

Redis: set `REDIS_HOST`, `REDIS_PORT`, and ensure `phpredis` or predis is available.

### PHP / hosting

- PHP-FPM workers ≥ concurrent peak ÷ 2 (aim for 50–100 workers for 1000 users with 30–60s heartbeats).
- MySQL `max_connections` well above FPM workers.
- Adequate disk IOPS for submit stampede (1 submission + N answer rows each).

### Baseline load test

From repo root (requires [k6](https://k6.io)):

```bash
k6 run -e BASE_URL=https://your-exam-host/gyanam-backend/public/index.php/api/v1 \
  -e STUDENT_ID=TEST001 -e STUDENT_PASS=password -e EXAM_ID=1 \
  gyanamexam/load-tests/exam-load.k6.js
```

Ramp stages are in the script (100 → 500 → 1000 VUs). Pass criteria:

- Error rate &lt; 1%
- Heartbeat p95 &lt; 500ms
- Submit p95 &lt; 2s under staggered end (not all at second 0)

### Chaos checks (manual)

1. Start exam → answer 5 questions → hard refresh → answers restored (local + server draft).
2. Double-click Submit → exactly one graded submission / attempt consumed.
3. Wait past duration + grace → submit rejected with clear message.
4. Kill network mid-exam → localStorage still holds answers; reconnect autosaves.

### Live sessions

Live monitor uses the `live_exam_sessions` table (atomic upserts), not an unlocked cache array.

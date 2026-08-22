# Post-deploy smoke test (5 minutes)

After Hostinger redeploys commit `637bb50` (or later):

## 1. Confirm deploy

SSH:

```bash
cd /home/u182510996/domains/labxco.in/public_html/gyanamexam
git log -1 --oneline
# expect 637bb50 or newer with "Fix remaining exam portal..."
```

## 2. Cache / Redis (capacity)

```bash
cd /home/u182510996/domains/labxco.in/public_html/gyanamexam/gyanam-backend
grep -E '^(CACHE_STORE|DB_CONNECTION|REDIS_|APP_DEBUG)=' .env
```

**Ideal for ~1000 students:**

```
DB_CONNECTION=mysql
CACHE_STORE=redis
APP_DEBUG=false
```

Check if Redis is reachable:

```bash
php -r "try { \$r=new Redis(); \$r->connect(getenv('REDIS_HOST')?:'127.0.0.1',(int)(getenv('REDIS_PORT')?:6379),1); echo 'REDIS OK\n'; } catch(Throwable \$e){ echo 'NO REDIS: '.\$e->getMessage().\"\n\"; }"
```

Or from Laravel:

```bash
php artisan tinker --execute="echo config('cache.default');"
```

- If Redis works: set `CACHE_STORE=redis` in `.env`, then:
  ```bash
  php artisan config:clear
  php artisan cache:clear
  ```
- If **no Redis** on shared Hostinger: keep `CACHE_STORE=database` and **do not** run a 1000-student simultaneous exam until you move to VPS/Redis.

## 3. Student smoke (browser)

1. Open student portal under `/gyanamexam/`
2. Login → start assigned exam
3. Answer 2–3 questions → hard refresh → answers still there
4. Wait for “Saved” autosave status
5. Submit → result page loads
6. (Optional) Double-click submit once → same result, no duplicate attempt

## 4. Admin smoke

1. Live monitoring shows the student while exam is open
2. Results page loads with search/pagination

## Done when

- [ ] Latest commit deployed  
- [ ] Migrations all `Ran` (already done)  
- [ ] Smoke steps 3–4 pass  
- [ ] Redis decided (on = OK for scale plan; off = small exams only)

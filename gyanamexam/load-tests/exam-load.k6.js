/**
 * k6 load test — student exam path (login → questions → heartbeats → submit)
 *
 * Usage:
 *   k6 run -e BASE_URL=http://127.0.0.1:8000/api/v1 \
 *     -e STUDENT_ID=TEST001 -e STUDENT_PASS=password -e EXAM_ID=1 \
 *     gyanamexam/load-tests/exam-load.k6.js
 *
 * For a real 1000-VU run, provision that many student credentials and use
 * a CSV scenario, or reuse one assigned student only for infra smoke tests.
 */
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8000/api/v1';
const STUDENT_ID = __ENV.STUDENT_ID || 'TEST001';
const STUDENT_PASS = __ENV.STUDENT_PASS || 'password';
const EXAM_ID = __ENV.EXAM_ID || '1';
const THINK = Number(__ENV.THINK_SECONDS || 2);
const HEARTBEATS = Number(__ENV.HEARTBEATS || 3); // short loop for CI; raise for soak

const errRate = new Rate('errors');
const hbTrend = new Trend('heartbeat_ms');
const submitTrend = new Trend('submit_ms');

export const options = {
  stages: [
    { duration: '1m', target: 100 },
    { duration: '2m', target: 100 },
    { duration: '2m', target: 500 },
    { duration: '2m', target: 500 },
    { duration: '3m', target: 1000 },
    { duration: '3m', target: 1000 },
    { duration: '2m', target: 0 },
  ],
  thresholds: {
    errors: ['rate<0.01'],
    http_req_failed: ['rate<0.01'],
    heartbeat_ms: ['p(95)<500'],
    submit_ms: ['p(95)<2000'],
  },
};

function authHeaders(token) {
  return {
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
  };
}

export default function () {
  const loginRes = http.post(
    `${BASE}/student/login`,
    JSON.stringify({
      identifier: STUDENT_ID,
      password: STUDENT_PASS,
    }),
    { headers: { 'Content-Type': 'application/json', Accept: 'application/json' } }
  );

  const loggedIn = check(loginRes, {
    'login 200': (r) => r.status === 200,
    'has token': (r) => !!(r.json('token') || r.json('access_token')),
  });
  errRate.add(!loggedIn);
  if (!loggedIn) {
    sleep(1);
    return;
  }

  const token = loginRes.json('token') || loginRes.json('access_token');
  const opts = authHeaders(token);

  const qRes = http.get(`${BASE}/student/exam/${EXAM_ID}/questions`, opts);
  const gotQ = check(qRes, { 'questions 200': (r) => r.status === 200 });
  errRate.add(!gotQ);
  if (!gotQ) {
    sleep(1);
    return;
  }

  const questions = qRes.json('questions') || [];
  sleep(THINK);

  for (let i = 0; i < HEARTBEATS; i++) {
    const t0 = Date.now();
    const hb = http.post(`${BASE}/student/exam/${EXAM_ID}/heartbeat`, null, opts);
    hbTrend.add(Date.now() - t0);
    const ok = check(hb, { 'heartbeat 200': (r) => r.status === 200 });
    errRate.add(!ok);

    // Sparse draft save (simulates autosave)
    if (questions.length && i === 0) {
      const draft = {
        answers: { [String(questions[0].id)]: 'A' },
      };
      http.post(`${BASE}/student/exam/${EXAM_ID}/answers`, JSON.stringify(draft), opts);
    }
    sleep(Math.max(1, THINK));
  }

  const answers = questions.map((q, idx) => ({
    question_id: q.id,
    answer: idx % 4 === 0 ? 'A' : 'B',
  }));

  const t1 = Date.now();
  const sub = http.post(
    `${BASE}/student/exam/${EXAM_ID}/submit`,
    JSON.stringify({
      answers,
      client_submission_id: `k6-${__VU}-${__ITER}-${Date.now()}`,
    }),
    opts
  );
  submitTrend.add(Date.now() - t1);
  // 403 may mean attempts exhausted when reusing one student — count as soft fail only if 5xx
  const submitOk = check(sub, {
    'submit ok or attempt limit': (r) => r.status === 200 || r.status === 403 || r.status === 422,
  });
  errRate.add(sub.status >= 500 || !submitOk);
  sleep(THINK);
}

/**
 * Stampede helpers — spread ~100 concurrent clients so shared hosting
 * is not hit by login/heartbeat/submit in the same second.
 */

/** Stable 0..1 hash from a string (student id, etc.) */
export function stableUnit(seed) {
  const s = String(seed || Math.random());
  let h = 2166136261;
  for (let i = 0; i < s.length; i++) {
    h ^= s.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  return ((h >>> 0) % 10000) / 10000;
}

export function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, Math.max(0, ms)));
}

/**
 * Deterministic delay (ms) in [0, maxMs] from seed, plus small random jitter.
 */
export function stampedeDelayMs(seed, maxMs = 20000, extraRandomMs = 1500) {
  const base = Math.floor(stableUnit(seed) * Math.max(0, maxMs));
  const jitter = Math.floor(Math.random() * Math.max(0, extraRandomMs));
  return base + jitter;
}

/**
 * Retry a request on 429 / 5xx / network errors with backoff + jitter.
 */
export async function withBackoff(fn, {
  retries = 4,
  baseMs = 800,
  maxMs = 8000,
  label = 'request',
} = {}) {
  let lastErr;
  for (let attempt = 0; attempt <= retries; attempt++) {
    try {
      return await fn();
    } catch (err) {
      lastErr = err;
      const status = err?.status || err?.response?.status;
      const retryable = !status || status === 429 || status >= 500;
      if (!retryable || attempt === retries) throw err;
      const wait = Math.min(maxMs, baseMs * (2 ** attempt)) + Math.floor(Math.random() * 400);
      console.warn(`[stampede] ${label} failed (try ${attempt + 1}), retry in ${wait}ms`, status || err?.message);
      await sleep(wait);
    }
  }
  throw lastErr;
}

export default { stableUnit, sleep, stampedeDelayMs, withBackoff };

<?php
/**
 * Background training-video upload window.
 * Stays open while admin browses other modules; posts progress/toasts via localStorage.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin(['Admin']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Uploading video… — Gyanam</title>
<style>
  :root { font-family: Sora, system-ui, sans-serif; }
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh;
    background: linear-gradient(160deg, #0f172a 0%, #1e293b 55%, #312e81 100%);
    color: #f8fafc; display: flex; align-items: center; justify-content: center; padding: 1rem;
  }
  .card {
    width: 100%; max-width: 420px; background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.14); border-radius: 18px; padding: 1.35rem 1.4rem;
    backdrop-filter: blur(10px); box-shadow: 0 20px 50px rgba(0,0,0,.35);
  }
  h1 { margin: 0 0 .35rem; font-size: 1.05rem; font-weight: 800; letter-spacing: -.01em; }
  .sub { margin: 0 0 1.1rem; font-size: .78rem; color: #cbd5e1; line-height: 1.45; font-weight: 500; }
  .title { font-size: .9rem; font-weight: 700; margin-bottom: .85rem; color: #e2e8f0; word-break: break-word; }
  .bar {
    height: 10px; border-radius: 99px; background: rgba(255,255,255,.12); overflow: hidden; margin-bottom: .55rem;
  }
  .fill {
    height: 100%; width: 0%; border-radius: 99px;
    background: linear-gradient(90deg, #6366f1, #22d3ee); transition: width .2s ease;
  }
  .meta { display: flex; justify-content: space-between; font-size: .75rem; font-weight: 700; color: #94a3b8; }
  .ok { color: #4ade80; }
  .err { color: #fb7185; }
  .hint { margin-top: 1rem; font-size: .72rem; color: #94a3b8; line-height: 1.4; }
  button {
    margin-top: .9rem; width: 100%; border: 0; border-radius: 10px; padding: .65rem 1rem;
    font-weight: 800; font-size: .85rem; cursor: pointer; background: #fff; color: #0f172a;
  }
  button:disabled { opacity: .5; cursor: default; }
</style>
</head>
<body>
<div class="card">
  <h1 id="heading">Preparing upload…</h1>
  <p class="sub">Keep this window open. You can use other Admin pages — a toast will appear there when this finishes.</p>
  <div class="title" id="vidTitle">—</div>
  <div class="bar"><div class="fill" id="fill"></div></div>
  <div class="meta"><span id="status">Waiting…</span><span id="pct">0%</span></div>
  <p class="hint" id="hint">Do not close this window until the upload completes.</p>
  <button type="button" id="closeBtn" disabled onclick="window.close()">Close</button>
</div>
<script>
(function () {
  const KEY_STATUS = 'gyanam_upload_status';
  const KEY_TOAST = 'gyanam_admin_toast';
  const CHANNEL = 'gyanam-admin';
  let busy = false;
  let bc = null;
  try { bc = new BroadcastChannel(CHANNEL); } catch (e) {}

  function publishStatus(data) {
    try { localStorage.setItem(KEY_STATUS, JSON.stringify(Object.assign({ ts: Date.now() }, data))); } catch (e) {}
    try { bc && bc.postMessage({ type: 'upload_status', data: data }); } catch (e) {}
  }
  function publishToast(message, success) {
    const payload = { message: message, success: !!success, ts: Date.now() };
    try { localStorage.setItem(KEY_TOAST, JSON.stringify(payload)); } catch (e) {}
    try { bc && bc.postMessage({ type: 'toast', data: payload }); } catch (e) {}
    try { localStorage.removeItem(KEY_STATUS); } catch (e) {}
    try { bc && bc.postMessage({ type: 'upload_status', data: { active: false } }); } catch (e) {}
  }

  const el = {
    heading: document.getElementById('heading'),
    title: document.getElementById('vidTitle'),
    fill: document.getElementById('fill'),
    status: document.getElementById('status'),
    pct: document.getElementById('pct'),
    hint: document.getElementById('hint'),
    closeBtn: document.getElementById('closeBtn'),
  };

  function setProgress(pct, statusText) {
    const p = Math.max(0, Math.min(100, pct | 0));
    el.fill.style.width = p + '%';
    el.pct.textContent = p + '%';
    if (statusText) el.status.textContent = statusText;
    publishStatus({ active: true, pct: p, title: el.title.textContent, status: statusText || '' });
  }

  window.addEventListener('beforeunload', function (e) {
    if (!busy) return;
    e.preventDefault();
    e.returnValue = '';
  });

  function startUpload(payload) {
    if (busy) return;
    busy = true;
    try {
      if (window.opener && !window.opener.closed) {
        window.opener.postMessage({ type: 'gyanam_upload_started' }, '*');
      }
    } catch (e) {}
    el.closeBtn.disabled = true;
    el.heading.textContent = 'Uploading video…';
    el.title.textContent = payload.title || 'Training video';
    el.hint.textContent = 'Upload runs in this window. Browse other modules freely.';
    setProgress(0, 'Starting…');

    const fd = new FormData();
    fd.append('ajax', '1');
    fd.append('action', 'add_video');
    fd.append('title', payload.title || '');
    fd.append('video_type', payload.video_type || 'upload');
    fd.append('video_url', payload.video_url || '');
    fd.append('description', payload.description || '');
    fd.append('access_start', payload.access_start || '');
    fd.append('access_end', payload.access_end || '');
    (payload.assign_atcs || []).forEach(function (id) { fd.append('assign_atcs[]', id); });
    if (payload.file) fd.append('video_file', payload.file, payload.file.name || 'video.mp4');

    const xhr = new XMLHttpRequest();
    xhr.timeout = 3600000;
    xhr.open('POST', 'training_videos.php');

    xhr.upload.addEventListener('progress', function (e) {
      if (!e.lengthComputable) return;
      const pct = Math.round(e.loaded / e.total * 100);
      const mb = (e.loaded / 1048576).toFixed(1);
      const totalMb = (e.total / 1048576).toFixed(1);
      setProgress(pct, pct >= 100 ? 'Processing on server…' : (mb + ' / ' + totalMb + ' MB'));
      if (pct >= 100) el.heading.textContent = 'Processing…';
    });

    xhr.onload = function () {
      busy = false;
      el.closeBtn.disabled = false;
      let ok = false, message = 'Upload failed';
      if (xhr.status === 413) {
        message = 'Server rejected file (413). Increase Hostinger upload limits.';
      } else {
        try {
          const r = JSON.parse(xhr.responseText);
          ok = !!r.success;
          message = r.message || message;
        } catch (err) {
          message = 'Upload failed after transfer — check PHP post_max_size / .user.ini.';
        }
      }
      setProgress(ok ? 100 : 0, ok ? 'Done' : 'Failed');
      el.heading.textContent = ok ? 'Upload complete' : 'Upload failed';
      el.status.className = ok ? 'ok' : 'err';
      el.status.textContent = message;
      el.hint.textContent = ok
        ? 'A toast was sent to your other Admin tabs. You can close this window.'
        : 'Fix the issue and try again from Training Videos.';
      publishToast(message, ok);
      if (ok) {
        try { if (window.opener && !window.opener.closed) window.opener.postMessage({ type: 'gyanam_upload_done', success: true }, '*'); } catch (e) {}
        setTimeout(function () { try { window.close(); } catch (e) {} }, 2500);
      }
    };
    xhr.onerror = function () {
      busy = false;
      el.closeBtn.disabled = false;
      el.heading.textContent = 'Network error';
      el.status.className = 'err';
      el.status.textContent = 'Network error during upload';
      publishToast('Network error during video upload', false);
    };
    xhr.ontimeout = function () {
      busy = false;
      el.closeBtn.disabled = false;
      el.heading.textContent = 'Timed out';
      el.status.className = 'err';
      el.status.textContent = 'Upload timed out';
      publishToast('Video upload timed out', false);
    };
    xhr.send(fd);
  }

  window.addEventListener('message', function (ev) {
    if (!ev.data || ev.data.type !== 'gyanam_start_training_upload') return;
    // Same-origin preferred
    startUpload(ev.data.payload || {});
  });

  // Ready handshake for opener
  try {
    if (window.opener && !window.opener.closed) {
      window.opener.postMessage({ type: 'gyanam_upload_bg_ready' }, '*');
    }
  } catch (e) {}

  el.heading.textContent = 'Waiting for file…';
  el.status.textContent = 'Ready';
})();
</script>
</body>
</html>

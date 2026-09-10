/**
 * ProctorCamerasSection — shared Admin / ATC live proctor grid.
 * Shows identity photos + optional peer-to-peer live video (not recorded).
 */
import { CONFIG } from '../config.js';

const ICE_SERVERS = [{ urls: 'stun:stun.l.google.com:19302' }, { urls: 'stun:stun1.l.google.com:19302' }];

export function renderProctorCamerasHTML(sessions) {
  const withCam = sessions.filter(s => s.hasPhoto || s.cameraActive);
  return `
    <div class="card proctor-cams-card" style="margin-bottom:1.25rem">
      <div class="card-header">
        <h3>📷 Proctor Cameras</h3>
        <span class="badge badge-blue">${withCam.length} with camera/photo</span>
      </div>
      <div class="card-body" style="padding-top:0.75rem">
        <p class="proctor-cams-note">
          Identity photo is stored only for the active session. Live video is peer-to-peer — <strong>not recorded</strong> and not streamed through the server.
        </p>
        ${sessions.length === 0 ? `
          <div style="color:var(--text-muted);font-size:0.875rem;padding:0.5rem 0">No active sessions.</div>
        ` : `
          <div class="proctor-cams-grid" id="proctor-cams-grid">
            ${sessions.map(s => `
              <div class="proctor-cam-card" data-student="${s.studentId}" data-exam="${s.examConfigId || s.examId}">
                <div class="proctor-cam-photo-wrap">
                  ${s.hasPhoto
                    ? `<img class="proctor-cam-photo" alt="" data-photo-url="${s.photoUrl || ''}" />`
                    : `<div class="proctor-cam-placeholder">${(s.studentName || '?').charAt(0)}</div>`}
                  ${s.cameraActive ? '<span class="proctor-cam-live-tag">Camera on</span>' : ''}
                </div>
                <div class="proctor-cam-meta">
                  <div class="proctor-cam-name">${s.studentName || '—'}</div>
                  <div class="proctor-cam-sub">${s.examTitle || s.examId || '—'} · ${s.centreName || '—'}</div>
                </div>
                <div class="proctor-cam-actions">
                  <button type="button" class="btn btn-outline btn-sm proctor-watch-btn"
                    data-student="${s.studentId}" data-exam="${s.examConfigId || s.examId}"
                    data-name="${(s.studentName || 'Student').replace(/"/g, '&quot;')}"
                    ${s.cameraActive ? '' : 'disabled title="Student camera not active"'}>
                    ${s.cameraActive ? 'Watch live' : 'No live cam'}
                  </button>
                </div>
              </div>`).join('')}
          </div>`}
      </div>
    </div>`;
}

export async function bindProctorCameras(ApiClient, root = document) {
  const token = ApiClient.getToken();
  const base = (CONFIG.API_BASE_URL || '').replace(/\/$/, '');

  // Load photos with auth header into blob URLs
  root.querySelectorAll('img.proctor-cam-photo[data-photo-url]').forEach(async (img) => {
    const path = img.getAttribute('data-photo-url');
    if (!path) return;
    try {
      const url = path.startsWith('http') ? path : `${base}${path.startsWith('/') ? '' : '/'}${path}`;
      const res = await fetch(url, { headers: { Authorization: `Bearer ${token}` } });
      if (!res.ok) throw new Error('photo fetch failed');
      const blob = await res.blob();
      img.src = URL.createObjectURL(blob);
    } catch (_) {
      img.replaceWith(Object.assign(document.createElement('div'), {
        className: 'proctor-cam-placeholder',
        textContent: '?',
      }));
    }
  });

  root.querySelectorAll('.proctor-watch-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      if (btn.disabled) return;
      openLiveWatchModal(ApiClient, {
        studentId: btn.dataset.student,
        examId: btn.dataset.exam,
        name: btn.dataset.name || 'Student',
      });
    });
  });
}

async function openLiveWatchModal(ApiClient, { studentId, examId, name }) {
  document.getElementById('proctor-live-overlay')?.remove();

  const overlay = document.createElement('div');
  overlay.id = 'proctor-live-overlay';
  overlay.className = 'proctor-live-overlay';
  overlay.innerHTML = `
    <div class="proctor-live-dialog">
      <div class="proctor-live-head">
        <div>
          <div class="proctor-live-title">Live proctor view</div>
          <div class="proctor-live-sub">${name} · peer-to-peer · not recorded</div>
        </div>
        <button type="button" class="btn btn-outline btn-sm" id="proctor-live-close">Close</button>
      </div>
      <video id="proctor-live-video" class="proctor-live-video" autoplay playsinline muted></video>
      <div class="proctor-live-status" id="proctor-live-status">Connecting…</div>
    </div>`;
  document.body.appendChild(overlay);

  let pc = null;
  let pollTimer = null;
  let appliedIce = new Set();
  let stopped = false;

  const setStatus = (t) => {
    const el = document.getElementById('proctor-live-status');
    if (el) el.textContent = t;
  };

  const cleanup = async () => {
    stopped = true;
    if (pollTimer) clearInterval(pollTimer);
    try { pc?.close(); } catch (_) {}
    pc = null;
    try {
      await ApiClient.postStaffProctorSignal(studentId, examId, { action: 'clear' });
    } catch (_) {}
    overlay.remove();
  };

  overlay.querySelector('#proctor-live-close')?.addEventListener('click', cleanup);
  overlay.addEventListener('click', (e) => { if (e.target === overlay) cleanup(); });

  try {
    await ApiClient.postStaffProctorSignal(studentId, examId, { action: 'watch' });
    pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });
    pc.ontrack = (ev) => {
      const video = document.getElementById('proctor-live-video');
      if (video && ev.streams[0]) {
        video.srcObject = ev.streams[0];
        setStatus('Live · not recorded');
      }
    };
    pc.onicecandidate = (ev) => {
      if (!ev.candidate) return;
      ApiClient.postStaffProctorSignal(studentId, examId, {
        action: 'ice',
        candidate: ev.candidate.toJSON(),
      }).catch(() => {});
    };

    const poll = async () => {
      if (stopped) return;
      try {
        const res = await ApiClient.getStaffProctorSignal(studentId, examId);
        const signals = res?.signals || {};
        if (signals.offer && pc && !pc.remoteDescription) {
          await pc.setRemoteDescription(signals.offer);
          const answer = await pc.createAnswer();
          await pc.setLocalDescription(answer);
          await ApiClient.postStaffProctorSignal(studentId, examId, {
            action: 'answer',
            sdp: pc.localDescription.toJSON(),
          });
          setStatus('Negotiating…');
        }
        for (const c of (signals.ice_student || [])) {
          const key = JSON.stringify(c);
          if (appliedIce.has(key)) continue;
          appliedIce.add(key);
          try { await pc.addIceCandidate(c); } catch (_) {}
        }
        if (!res?.camera_active) setStatus('Student camera offline');
      } catch (e) {
        setStatus('Connection issue — retrying…');
      }
    };

    await poll();
    pollTimer = setInterval(poll, 5000);
  } catch (e) {
    setStatus('Failed to start live view: ' + e.message);
  }
}

export default { renderProctorCamerasHTML, bindProctorCameras };

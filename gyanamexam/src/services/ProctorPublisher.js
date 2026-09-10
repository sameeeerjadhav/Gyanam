/**
 * ProctorPublisher — student-side WebRTC publisher.
 * Media stays peer-to-peer; only SDP/ICE JSON goes through the API (tiny).
 * Nothing is recorded.
 */
const ICE_SERVERS = [{ urls: 'stun:stun.l.google.com:19302' }, { urls: 'stun:stun1.l.google.com:19302' }];

export class ProctorPublisher {
  constructor(ApiClient, examId, stream) {
    this.ApiClient = ApiClient;
    this.examId = examId;
    this.stream = stream;
    this.pc = null;
    this._timer = null;
    this._stopped = false;
    this._makingOffer = false;
    this._appliedIce = new Set();
  }

  start() {
    this._stopped = false;
    this.ApiClient.postProctorSignal(this.examId, { action: 'status', camera_active: true }).catch(() => {});
    this._tick();
    // Slow poll when idle; speeds up only while a viewer is watching (see _scheduleNext)
    this._scheduleNext(8000);
  }

  stop() {
    this._stopped = true;
    if (this._timer) clearTimeout(this._timer);
    this._timer = null;
    try { this.pc?.close(); } catch (_) {}
    this.pc = null;
    this.ApiClient.postProctorSignal(this.examId, { action: 'clear', camera_active: false }).catch(() => {});
  }

  _scheduleNext(ms) {
    if (this._timer) clearTimeout(this._timer);
    if (this._stopped) return;
    this._timer = setTimeout(() => this._tick(), ms);
  }

  async _tick() {
    if (this._stopped || !this.stream) return;
    let nextMs = 12000; // no viewer — light load on shared hosting
    try {
      const res = await this.ApiClient.getProctorSignal(this.examId);
      const signals = res?.signals || {};
      if (!signals.viewer_watching) {
        // No viewer — tear down PC to save resources
        if (this.pc) {
          try { this.pc.close(); } catch (_) {}
          this.pc = null;
          this._appliedIce = new Set();
        }
        this._scheduleNext(nextMs);
        return;
      }

      nextMs = 5000; // viewer connected — still far slower than old 2.5s

      if (!this.pc) {
        await this._createOffer();
        this._scheduleNext(nextMs);
        return;
      }

      if (signals.answer && this.pc.signalingState === 'have-local-offer') {
        await this.pc.setRemoteDescription(signals.answer);
      }

      for (const c of (signals.ice_viewer || [])) {
        const key = JSON.stringify(c);
        if (this._appliedIce.has(key)) continue;
        this._appliedIce.add(key);
        try { await this.pc.addIceCandidate(c); } catch (_) {}
      }
    } catch (e) {
      // silent — exam continues without live view
    }
    this._scheduleNext(nextMs);
  }

  async _createOffer() {
    if (this._makingOffer || this._stopped) return;
    this._makingOffer = true;
    try {
      this.pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });
      this.stream.getTracks().forEach(t => this.pc.addTrack(t, this.stream));

      this.pc.onicecandidate = (ev) => {
        if (!ev.candidate) return;
        this.ApiClient.postProctorSignal(this.examId, {
          action: 'ice',
          role: 'student',
          candidate: ev.candidate.toJSON(),
        }).catch(() => {});
      };

      const offer = await this.pc.createOffer({ offerToReceiveAudio: false, offerToReceiveVideo: false });
      await this.pc.setLocalDescription(offer);
      await this.ApiClient.postProctorSignal(this.examId, {
        action: 'offer',
        sdp: this.pc.localDescription.toJSON(),
        camera_active: true,
      });
    } catch (e) {
      console.warn('ProctorPublisher offer failed', e);
      try { this.pc?.close(); } catch (_) {}
      this.pc = null;
    } finally {
      this._makingOffer = false;
    }
  }
}

export default ProctorPublisher;

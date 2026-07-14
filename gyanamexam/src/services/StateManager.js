/**
 * StateManager - Manages exam state with optional persistence
 */
export class StateManager {
  constructor() {
    this._state = {};
    this._storage = null;
  }

  initialize(initialState, storage = null) {
    this._state = { ...initialState };
    this._storage = storage;
  }

  getState() {
    return this._state;
  }

  update(updater) {
    updater(this._state);
  }

  persist(force = false) {
    if (this._storage && this._state.examId) {
      try {
        this._storage.save(`exam_state_${this._state.examId}`, this._state);
      } catch (e) {
        console.warn('StateManager: persist failed', e);
      }
    }
  }

  restore(examId) {
    if (!this._storage) return null;
    try {
      return this._storage.load(`exam_state_${examId}`);
    } catch {
      return null;
    }
  }

  clear(examId) {
    if (this._storage) {
      try { this._storage.remove(`exam_state_${examId}`); } catch {}
    }
    this._state = {};
  }
}

export default StateManager;

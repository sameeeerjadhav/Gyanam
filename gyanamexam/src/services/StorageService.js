/**
 * StorageService - Wraps localStorage for exam state persistence
 */
export class StorageService {
  save(key, value) {
    try {
      localStorage.setItem(key, JSON.stringify(value));
    } catch (e) {
      console.warn('StorageService: save failed', e);
    }
  }

  load(key) {
    try {
      const raw = localStorage.getItem(key);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }

  remove(key) {
    try {
      localStorage.removeItem(key);
    } catch {}
  }
}

export default StorageService;

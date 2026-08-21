/**
 * AuthenticationModule.js
 * Singleton authentication service for the Gyanam exam portal.
 * Wraps ApiClient login/logout and manages session state in localStorage.
 */

import ApiClient from './APIClient.js';

// Ensure student portal always uses its own isolated storage keys
ApiClient.setScope('student');

const SESSION_KEY = 'gyanam_student_session';

export class AuthenticationModule {
  constructor() {
    this._session = this._loadSession();
  }

  /**
   * Authenticate a student or admin user.
   * Detects type based on credentials provided.
   * @param {Object} credentials - { identifier } for students OR { username, password } for admin
   */
  async authenticate(credentials) {
    try {
      let data;

      if (credentials.identifier) {
        // Student login — requires identifier (registration ID) + password
        data = await ApiClient.studentLogin({
          identifier: credentials.identifier,
          password: credentials.password || '',
        });
      } else if (credentials.username) {
        // Admin / ATC / DLC portal login
        data = await ApiClient.login(credentials.username, credentials.password);
      } else {
        throw new Error('Please provide either a Student ID or Username.');
      }

      const session = {
        token: data.token,
        user: data.user,
        loginTime: Date.now(),
      };

      this._session = session;
      localStorage.setItem(SESSION_KEY, JSON.stringify(session));

      return { success: true, user: data.user };
    } catch (error) {
      const msg = this._extractError(error);
      throw new Error(msg);
    }
  }

  /** Check if a valid session exists */
  isAuthenticated() {
    return !!(this._session && this._session.token && ApiClient.getToken());
  }

  /** Get current session { token, user } */
  getCurrentSession() {
    return this._session;
  }

  /** Logout — clear tokens and session */
  async logout() {
    try {
      await ApiClient.logout();
    } catch (_) { }
    this._session = null;
    localStorage.removeItem(SESSION_KEY);
  }

  /** @private */
  _loadSession() {
    try {
      const raw = localStorage.getItem(SESSION_KEY);
      if (!raw) return null;
      const session = JSON.parse(raw);
      if (session?.token) ApiClient.setToken(session.token);
      return session;
    } catch (_) {
      return null;
    }
  }

  /** @private Extract human-readable error */
  _extractError(error) {
    if (error?.message) return error.message;
    return 'Authentication failed. Please check your credentials.';
  }
}

// Singleton instance
let _instance = null;
export function getAuthModule() {
  if (!_instance) _instance = new AuthenticationModule();
  return _instance;
}

export default AuthenticationModule;

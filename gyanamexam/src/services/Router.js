/**
 * Router Module - Client-side routing with History API
 * 
 * Fixed:
 * - Passes extracted URL params to route handlers
 * - Renders 404 page instead of silently redirecting
 */

export class Router {
  constructor() {
    this.routes = new Map();
    this.currentPath = window.location.pathname;
    this.isInitialized = false;
    this.handlePopState = this.handlePopState.bind(this);
    // Detect base path (useful if hosted in a subfolder like /gyanam/)
    this.basePath = this.detectBasePath();
  }

  detectBasePath() {
    const path = window.location.pathname;
    if (path.includes('/admin.html')) return path.split('/admin.html')[0];
    if (path.includes('/index.html')) return path.split('/index.html')[0];
    // fallback to root or current dir
    return '';
  }

  initialize() {
    if (this.isInitialized) return;
    window.addEventListener('popstate', this.handlePopState);
    this.isInitialized = true;
    this.handleRoute(this.currentPath);
  }

  /**
   * Register a route handler
   * @param {string} path - Route path
   * @param {Function} handler - Receives parsed params object
   */
  register(path, handler) {
    if (typeof path !== 'string' || !path.startsWith('/')) throw new Error('Route path must start with "/"');
    if (typeof handler !== 'function') throw new Error('Route handler must be a function');
    this.routes.set(path, handler);
  }

  navigate(path, state = null) {
    if (typeof path !== 'string' || !path.startsWith('/')) throw new Error('Navigation path must start with "/"');
    const fullPath = this.basePath + path;
    if (fullPath === this.currentPath) return;
    window.history.pushState(state, '', fullPath);
    this.currentPath = fullPath;
    this.handleRoute(fullPath, state);
  }

  getCurrentRoute() { return this.currentPath; }

  handlePopState(event) {
    const path = window.location.pathname;
    this.currentPath = path;
    this.handleRoute(path, event.state);
  }

  handleRoute(path, state = null) {
    const { matchedPath, params } = this.matchRoute(path);

    if (matchedPath && this.routes.has(matchedPath)) {
      const handler = this.routes.get(matchedPath);
      try {
        // FIX: pass params to handler
        handler(params, state);
      } catch (error) {
        console.error(`Error handling route ${path}:`, error);
        this.handleRouteError(error, path);
      }
    } else {
      this.handleNotFound(path);
    }
  }

  matchRoute(path) {
    if (this.routes.has(path)) return { matchedPath: path, params: {} };

    for (const [routePath] of this.routes) {
      // Check absolute match first
      if (routePath === path) return { matchedPath: routePath, params: {} };

      // Check match relative to basePath
      const relativePath = path.startsWith(this.basePath) ? path.slice(this.basePath.length) : path;
      if (routePath === relativePath) return { matchedPath: routePath, params: {} };

      const params = this.extractParams(routePath, relativePath);
      if (params !== null) return { matchedPath: routePath, params };
    }

    return { matchedPath: null, params: {} };
  }

  extractParams(pattern, path) {
    const patternParts = pattern.split('/').filter(Boolean);
    const pathParts = path.split('/').filter(Boolean);
    if (patternParts.length !== pathParts.length) return null;

    const params = {};
    for (let i = 0; i < patternParts.length; i++) {
      if (patternParts[i].startsWith(':')) {
        params[patternParts[i].slice(1)] = decodeURIComponent(pathParts[i]);
      } else if (patternParts[i] !== pathParts[i]) {
        return null;
      }
    }
    return params;
  }

  handleNotFound(path) {
    console.warn(`No route found for: ${path}`);
    // FIX: Show a real 404 page instead of silently redirecting
    const appContainer = document.getElementById('app');
    if (appContainer) {
      appContainer.innerHTML = `
        <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f8fafc;font-family:'Inter',sans-serif;">
          <div style="text-align:center;color:#0f172a;padding:2rem;">
            <div style="font-size:8rem;font-weight:900;color:#1d4ed8;line-height:1;margin-bottom:1rem;letter-spacing:-0.05em;opacity:0.1;position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);z-index:0;pointer-events:none;">404</div>
            <div style="position:relative;z-index:1;">
              <h2 style="font-size:2rem;font-weight:800;margin-bottom:0.75rem;letter-spacing:-0.02em;">Oops! Page Not Found</h2>
              <p style="color:#64748b;margin-bottom:2.5rem;font-size:1.125rem;font-weight:500;">The page <code style="background:#f1f5f9;padding:0.2em 0.5em;border-radius:4px;color:#1d4ed8;font-weight:700;">${path}</code> doesn't exist.</p>
              <a href="/login" onclick="event.preventDefault();window.history.pushState(null,'','/login');window.dispatchEvent(new PopStateEvent('popstate'));"
                 style="background:#1d4ed8;color:white;padding:0.875rem 2.5rem;border-radius:0.75rem;text-decoration:none;font-weight:700;display:inline-block;transition:all 0.2s;box-shadow:0 4px 12px rgba(29,78,216,0.15);"
                 onmouseover="this.style.background='#1e40af'"
                 onmouseout="this.style.background='#1d4ed8'">
                &larr; Go to Login
              </a>
            </div>
          </div>
        </div>
      `;
    } else if (this.routes.has('/login')) {
      this.navigate('/login');
    }
  }

  handleRouteError(error, path) {
    console.error(`Route error at ${path}:`, error);
  }

  destroy() {
    if (this.isInitialized) {
      window.removeEventListener('popstate', this.handlePopState);
      this.isInitialized = false;
    }
  }
}

export default new Router();

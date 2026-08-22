/**
 * config.js
 * Centralized configuration for the Gyanam Exam Portal.
 * Automatically detects environment based on hostname / deploy path.
 */

const isLocal = window.location.hostname === 'localhost' ||
    window.location.hostname === '127.0.0.1' ||
    window.location.hostname.startsWith('192.168.');

function resolveApiBaseUrl() {
    // Optional override: <script>window.__GYANAM_API_BASE__='https://…/api/v1'</script>
    if (typeof window !== 'undefined' && window.__GYANAM_API_BASE__) {
        return String(window.__GYANAM_API_BASE__).replace(/\/$/, '');
    }

    if (isLocal) {
        return 'http://127.0.0.1:8000/api/v1';
    }

    const origin = window.location.origin;
    const path = window.location.pathname || '';

    // Hostinger layout: …/public_html/gyanamexam/{index.html,gyanam-backend}
    if (path.includes('/gyanamexam')) {
        return `${origin}/gyanamexam/gyanam-backend/public/index.php/api/v1`;
    }

    // Fallback: backend next to site root
    return `${origin}/gyanam-backend/public/index.php/api/v1`;
}

export const CONFIG = {
    API_BASE_URL: resolveApiBaseUrl(),

    WS_HOST: window.location.hostname,
    WS_PORT: 6001,
    WS_KEY: 'gyanam-secret-key',

    // Hostinger shared hosting often blocks custom ports
    USE_WEBSOCKETS: isLocal,

    TOKEN_KEY: 'gyanam_token',
    USER_KEY: 'gyanam_user'
};

export default CONFIG;

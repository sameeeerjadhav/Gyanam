/**
 * config.js
 * Centralized configuration for the Gyanam Exam Portal.
 * Automatically detects environment based on hostname.
 */

const isLocal = window.location.hostname === 'localhost' ||
    window.location.hostname === '127.0.0.1' ||
    window.location.hostname.startsWith('192.168.');

export const CONFIG = {
    // API Base URL
    // Locally, we point to the artisan serve port. 
    // In production, we assume the backend is hosted at the same domain/IP.
    API_BASE_URL: isLocal
        ? 'http://127.0.0.1:8000/api/v1'
        : `${window.location.origin}/gyanam-backend/public/index.php/api/v1`,

    // WebSocket Configuration (Reverb)
    WS_HOST: window.location.hostname,
    WS_PORT: 6001,
    WS_KEY: 'gyanam-secret-key',

    // Toggle WebSocket usage (Hostinger shared hosting often blocks custom ports)
    USE_WEBSOCKETS: isLocal, // Default to local only, can be manually toggled if VPS is used

    // Auth configuration
    TOKEN_KEY: 'gyanam_token',
    USER_KEY: 'gyanam_user'
};

export default CONFIG;

<?php
/**
 * App settings (key/value) — used for SMTP mail config from Admin Profile.
 */

function ensureAppSettingsSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
                setting_value TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {
        error_log('[AppSettings] ' . $e->getMessage());
    }
}

/**
 * @return array<string,string>
 */
function getAppSettings(PDO $pdo, array $keys = []): array
{
    ensureAppSettingsSchema($pdo);
    try {
        if ($keys === []) {
            $rows = $pdo->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
            return is_array($rows) ? array_map(static fn($v) => (string)($v ?? ''), $rows) : [];
        }
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $st = $pdo->prepare("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ($ph)");
        $st->execute(array_values($keys));
        $out = array_fill_keys($keys, '');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['setting_key']] = (string)($row['setting_value'] ?? '');
        }
        return $out;
    } catch (Exception $e) {
        return array_fill_keys($keys, '');
    }
}

function setAppSetting(PDO $pdo, string $key, ?string $value): void
{
    ensureAppSettingsSchema($pdo);
    $st = $pdo->prepare("
        INSERT INTO app_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $st->execute([$key, $value]);
}

/**
 * @param array<string,?string> $pairs
 */
function setAppSettings(PDO $pdo, array $pairs): void
{
    foreach ($pairs as $key => $value) {
        setAppSetting($pdo, (string)$key, $value === null ? null : (string)$value);
    }
}

/**
 * Mail settings for Admin Profile form / mailer.
 *
 * @return array{
 *   mail_enabled:string,mail_host:string,mail_port:string,mail_encryption:string,
 *   mail_username:string,mail_password:string,mail_from_email:string,
 *   mail_from_name:string,mail_reply_to:string
 * }
 */
function getMailSettings(PDO $pdo): array
{
    $defaults = [
        'mail_enabled'     => '1',
        'mail_host'        => 'smtp.hostinger.com',
        'mail_port'        => '465',
        'mail_encryption'  => 'ssl',
        'mail_username'    => '',
        'mail_password'    => '',
        'mail_from_email'  => '',
        'mail_from_name'   => 'Gyanam India Educational Services',
        'mail_reply_to'    => '',
    ];
    $saved = getAppSettings($pdo, array_keys($defaults));
    foreach ($defaults as $k => $v) {
        if (($saved[$k] ?? '') === '') {
            $saved[$k] = $v;
        }
    }
    return $saved;
}

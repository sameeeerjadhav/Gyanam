<?php
/**
 * Lightweight SMTP mailer (no Composer dependency).
 * Prefers Admin Profile mail settings (DB), then config/mail.php fallback.
 */

require_once __DIR__ . '/app_settings.php';

/**
 * @return array{
 *   enabled:bool,host:string,port:int,encryption:string,
 *   username:string,password:string,from_email:string,from_name:string,reply_to:string
 * }|null
 */
function resolveMailRuntimeConfig(?PDO $pdo = null): ?array
{
    static $cached = false;
    static $cfg = null;
    if ($cached) {
        return $cfg;
    }
    $cached = true;

    // 1) Admin Profile / DB settings
    try {
        if (!$pdo instanceof PDO && function_exists('getDBConnection')) {
            $pdo = getDBConnection();
        }
        if ($pdo instanceof PDO) {
            $s = getMailSettings($pdo);
            $user = trim((string)($s['mail_username'] ?? ''));
            $pass = (string)($s['mail_password'] ?? '');
            $from = trim((string)($s['mail_from_email'] ?? ''));
            if ($from === '') {
                $from = $user;
            }
            $enabled = ((string)($s['mail_enabled'] ?? '1')) !== '0';
            if ($enabled && $user !== '' && $pass !== '' && $from !== '') {
                $cfg = [
                    'enabled'     => true,
                    'host'        => trim((string)($s['mail_host'] ?? 'smtp.hostinger.com')) ?: 'smtp.hostinger.com',
                    'port'        => (int)($s['mail_port'] ?? 465) ?: 465,
                    'encryption'  => strtolower(trim((string)($s['mail_encryption'] ?? 'ssl'))) ?: 'ssl',
                    'username'    => $user,
                    'password'    => $pass,
                    'from_email'  => $from,
                    'from_name'   => trim((string)($s['mail_from_name'] ?? 'Gyanam India Educational Services')) ?: 'Gyanam India Educational Services',
                    'reply_to'    => trim((string)($s['mail_reply_to'] ?? '')) ?: $from,
                ];
                return $cfg;
            }
        }
    } catch (Throwable $e) {
        // fall through to file config
    }

    // 2) Optional file config fallback
    $file = __DIR__ . '/../config/mail.php';
    if (is_file($file)) {
        require_once $file;
        if (defined('MAIL_ENABLED') && MAIL_ENABLED
            && defined('MAIL_HOST') && defined('MAIL_USERNAME') && defined('MAIL_PASSWORD')
            && defined('MAIL_FROM_EMAIL')
            && MAIL_USERNAME !== '' && MAIL_PASSWORD !== '' && MAIL_PASSWORD !== 'CHANGE_ME'
            && MAIL_FROM_EMAIL !== '') {
            $cfg = [
                'enabled'     => true,
                'host'        => (string)MAIL_HOST,
                'port'        => (int)(defined('MAIL_PORT') ? MAIL_PORT : 465),
                'encryption'  => strtolower((string)(defined('MAIL_ENCRYPTION') ? MAIL_ENCRYPTION : 'ssl')),
                'username'    => (string)MAIL_USERNAME,
                'password'    => (string)MAIL_PASSWORD,
                'from_email'  => (string)MAIL_FROM_EMAIL,
                'from_name'   => defined('MAIL_FROM_NAME') ? (string)MAIL_FROM_NAME : 'Gyanam India',
                'reply_to'    => (defined('MAIL_REPLY_TO') && MAIL_REPLY_TO) ? (string)MAIL_REPLY_TO : (string)MAIL_FROM_EMAIL,
            ];
            return $cfg;
        }
    }

    $cfg = null;
    return null;
}

function loadMailConfig(): bool
{
    return resolveMailRuntimeConfig() !== null;
}

/**
 * @return array{success:bool,message:string}
 */
function sendAppMail(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): array
{
    $toEmail = trim($toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid recipient email address'];
    }

    $cfg = resolveMailRuntimeConfig();
    if ($cfg === null) {
        return ['success' => false, 'message' => 'Mail is not configured. Set SMTP details in Admin → My Profile → Email (SMTP) Settings.'];
    }

    if ($textBody === '') {
        $textBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ["\n", "\n", "\n", "\n\n"], $htmlBody)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    $fromEmail = $cfg['from_email'];
    $fromName  = $cfg['from_name'];
    $replyTo   = $cfg['reply_to'];
    $host      = $cfg['host'];
    $port      = $cfg['port'];
    $enc       = $cfg['encryption'];
    $user      = $cfg['username'];
    $pass      = $cfg['password'];

    try {
        $transport = ($enc === 'ssl') ? 'ssl://' . $host : $host;
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            $transport . ':' . $port,
            $errno,
            $errstr,
            25,
            STREAM_CLIENT_CONNECT
        );
        if (!$fp) {
            return ['success' => false, 'message' => 'SMTP connect failed: ' . ($errstr ?: (string)$errno)];
        }
        stream_set_timeout($fp, 25);

        $read = static function () use ($fp): string {
            $data = '';
            while (!feof($fp)) {
                $line = fgets($fp, 515);
                if ($line === false) {
                    break;
                }
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = static function (string $command, string $expectPrefix) use ($fp, $read): void {
            fwrite($fp, $command . "\r\n");
            $resp = $read();
            if (strpos($resp, $expectPrefix) !== 0) {
                throw new RuntimeException(trim($resp) !== '' ? trim($resp) : ('SMTP rejected: ' . $command));
            }
        };

        $read(); // banner
        $cmd('EHLO portal.gyanamindia.com', '250');

        if ($enc === 'tls') {
            $cmd('STARTTLS', '220');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed');
            }
            $cmd('EHLO portal.gyanamindia.com', '250');
        }

        $cmd('AUTH LOGIN', '334');
        $cmd(base64_encode($user), '334');
        $cmd(base64_encode($pass), '235');
        $cmd('MAIL FROM:<' . $fromEmail . '>', '250');
        $cmd('RCPT TO:<' . $toEmail . '>', '250');
        $cmd('DATA', '354');

        $boundary = 'b_' . bin2hex(random_bytes(8));
        $headers = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . smtpEncodeAddress($fromName, $fromEmail);
        $headers[] = 'To: ' . smtpEncodeAddress($toName, $toEmail);
        $headers[] = 'Reply-To: ' . $replyTo;
        $headers[] = 'Subject: ' . smtpEncodeHeader($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $headers[] = 'X-Mailer: GyanamPortal';

        $body  = '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($textBody)) . "\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
        $body .= '--' . $boundary . "--\r\n";

        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $payload = preg_replace('/^\./m', '..', $payload) ?? $payload;
        fwrite($fp, $payload . "\r\n.\r\n");
        $dataResp = $read();
        if (strpos($dataResp, '250') !== 0) {
            throw new RuntimeException(trim($dataResp) ?: 'SMTP DATA failed');
        }
        fwrite($fp, "QUIT\r\n");
        fclose($fp);

        return ['success' => true, 'message' => 'Email sent to ' . $toEmail];
    } catch (Throwable $e) {
        if (isset($fp) && is_resource($fp)) {
            fclose($fp);
        }
        error_log('[Mailer] ' . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function smtpEncodeAddress(string $name, string $email): string
{
    $name = trim($name);
    if ($name === '') {
        return '<' . $email . '>';
    }
    return smtpEncodeHeader($name) . ' <' . $email . '>';
}

function smtpEncodeHeader(string $value): string
{
    if (preg_match('/[^\x20-\x7E]/', $value)) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
    return $value;
}

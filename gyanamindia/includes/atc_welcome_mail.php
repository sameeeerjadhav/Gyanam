<?php
/**
 * ATC onboarding welcome email (GIIT / Gyanam branded by center type).
 */
require_once __DIR__ . '/mailer.php';

function ensureAtcWelcomeMailSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM atc_centers')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('welcome_email_sent_at', $cols, true)) {
            $pdo->exec("ALTER TABLE atc_centers ADD COLUMN welcome_email_sent_at DATETIME NULL DEFAULT NULL AFTER email");
        }
        if (!in_array('welcome_email_status', $cols, true)) {
            $pdo->exec("ALTER TABLE atc_centers ADD COLUMN welcome_email_status VARCHAR(40) NULL DEFAULT NULL AFTER welcome_email_sent_at");
        }
    } catch (Exception $e) {
        error_log('[AtcWelcomeMail] schema: ' . $e->getMessage());
    }
}

/**
 * Brand pack for welcome letter based on center type.
 *
 * @return array{org:string,short:string,unit:string,role:string,kit:string,tagline:string,subject:string}
 */
function atcWelcomeBrandPack(?string $centerType): array
{
    $types = function_exists('courseTypesForCenter') ? courseTypesForCenter($centerType) : [];
    $hasIt = in_array('IT', $types, true);

    if ($hasIt) {
        return [
            'org'      => 'Gyanam Institute of Information Technology (GIIT)',
            'short'    => 'GIIT',
            'unit'     => 'A Unit of Gyanam India Educational Services',
            'role'     => 'GIIT Authorized Training Centre (ATC)',
            'kit'      => 'GIIT Centre Kit',
            'tagline'  => "Together, Let's Build a Stronger Future in IT Education!",
            'subject'  => 'Welcome to the GIIT Family — Authorized Training Centre',
            'supports' => [
                'Quality IT education and training programs',
                'Enhanced institutional recognition and credibility',
                'New learning and career opportunities for students',
                'Academic and operational support for your centre',
            ],
        ];
    }

    $label = 'Quality education';
    if (in_array('Abacus', $types, true) && in_array('Vedic Maths', $types, true)) {
        $label = 'Abacus & Vedic Maths education';
    } elseif (in_array('Abacus', $types, true)) {
        $label = 'Abacus education';
    } elseif (in_array('Vedic Maths', $types, true)) {
        $label = 'Vedic Maths education';
    }

    return [
        'org'      => 'Gyanam India Educational Services',
        'short'    => 'Gyanam',
        'unit'     => '',
        'role'     => 'Authorized Training Centre (ATC)',
        'kit'      => 'Centre Kit',
        'tagline'  => "Together, Let's Build Brighter Futures Through Quality Education!",
        'subject'  => 'Welcome to the Gyanam Family — Authorized Training Centre',
        'supports' => [
            $label . ' and training programs',
            'Enhanced institutional recognition and credibility',
            'New learning and career opportunities for students',
            'Academic and operational support for your centre',
        ],
    ];
}

function atcWelcomeGreetingName(array $atc): string
{
    $person = trim((string)($atc['contact_person'] ?? ''));
    if ($person !== '') {
        return $person;
    }
    return 'Sir/Madam';
}

function atcWelcomeLocationLine(array $atc): string
{
    $parts = array_filter([
        trim((string)($atc['city'] ?? '')),
        trim((string)($atc['district'] ?? '')),
        trim((string)($atc['state'] ?? '')),
    ], static fn($x) => $x !== '');
    return implode(', ', $parts);
}

/**
 * Build personalized welcome content.
 *
 * @return array{subject:string,html:string,text:string}
 */
function buildAtcWelcomeEmailContent(array $atc): array
{
    $brand   = atcWelcomeBrandPack($atc['center_type'] ?? '');
    $greet   = atcWelcomeGreetingName($atc);
    $name    = trim((string)($atc['name'] ?? 'your centre'));
    $code    = trim((string)($atc['atc_code'] ?? ''));
    $loc     = atcWelcomeLocationLine($atc);
    $type    = trim((string)($atc['center_type'] ?? ''));

    $centreBits = [$name];
    if ($code !== '') {
        $centreBits[] = 'ATC Code: ' . $code;
    }
    if ($loc !== '') {
        $centreBits[] = $loc;
    }
    if ($type !== '') {
        $centreBits[] = 'Center Type: ' . $type;
    }
    $centreLine = implode(' · ', $centreBits);

    $supportLisHtml = '';
    $supportLisText = '';
    foreach ($brand['supports'] as $item) {
        $supportLisHtml .= '<li style="margin:0 0 8px;color:#334155;font-size:15px;line-height:1.55">' . htmlspecialchars($item) . '</li>';
        $supportLisText .= '• ' . $item . "\n";
    }

    $unitHtml = $brand['unit'] !== ''
        ? '<em style="color:#64748b">' . htmlspecialchars($brand['unit']) . '</em>'
        : '';
    $unitText = $brand['unit'] !== '' ? $brand['unit'] : '';

    $html = '
<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Arial,sans-serif">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f1f5f9;padding:24px 12px">
<tr><td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0">
  <tr><td style="background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:22px 28px;color:#fff">
    <div style="font-size:13px;opacity:.9;font-weight:600;letter-spacing:.04em;text-transform:uppercase">Welcome Letter</div>
    <div style="font-size:20px;font-weight:800;margin-top:6px">' . htmlspecialchars($brand['org']) . '</div>
  </td></tr>
  <tr><td style="padding:28px">
    <p style="margin:0 0 14px;font-size:15px;color:#0f172a">Dear ' . htmlspecialchars($greet) . ',</p>
    <p style="margin:0 0 14px;font-size:15px;color:#334155;line-height:1.65">
      Greetings from <strong>' . htmlspecialchars($brand['org']) . '</strong>!
    </p>
    <p style="margin:0 0 14px;font-size:15px;color:#334155;line-height:1.65">
      We are delighted to welcome <strong>' . htmlspecialchars($name) . '</strong>
      ' . ($loc !== '' ? ' (' . htmlspecialchars($loc) . ')' : '') . '
      to the <strong>' . htmlspecialchars($brand['short']) . ' Family</strong> and congratulate you on receiving
      the official recognition to operate as a <strong>' . htmlspecialchars($brand['role']) . '</strong>.
    </p>
    <div style="margin:0 0 16px;padding:12px 14px;border-radius:12px;background:#eff6ff;border:1px solid #bfdbfe;font-size:13px;color:#1e3a8a;line-height:1.5">
      <strong>Centre details</strong><br>' . htmlspecialchars($centreLine) . '
    </div>
    <p style="margin:0 0 14px;font-size:15px;color:#334155;line-height:1.65">
      We sincerely thank you for placing your trust in ' . htmlspecialchars($brand['short']) . '
      and choosing to be a part of our growing network of institutions committed to delivering quality education.
    </p>
    <p style="margin:0 0 8px;font-size:15px;color:#334155;line-height:1.65">
      Through ' . htmlspecialchars($brand['short']) . ', we look forward to supporting your centre with:
    </p>
    <ul style="margin:0 0 16px;padding-left:20px">' . $supportLisHtml . '</ul>
    <p style="margin:0 0 14px;font-size:15px;color:#334155;line-height:1.65">
      We are confident that this association will create valuable opportunities for both your institution and the students you serve.
    </p>
    <p style="margin:0 0 14px;font-size:15px;color:#334155;line-height:1.65">
      Your <strong>' . htmlspecialchars($brand['kit']) . '</strong> will be dispatched to your centre by post within the next few days.
    </p>
    <p style="margin:0 0 14px;font-size:15px;color:#334155;line-height:1.65">
      We look forward to a successful and long-term association with you.
    </p>
    <p style="margin:0 0 18px;font-size:15px;color:#1d4ed8;font-weight:700;line-height:1.6;text-align:center">
      🌟 ' . htmlspecialchars($brand['tagline']) . ' 🌟
    </p>
    <p style="margin:0 0 18px;font-size:15px;color:#334155;line-height:1.65">
      Once again, congratulations and a very warm welcome to the <strong>' . htmlspecialchars($brand['short']) . ' Family</strong>.
    </p>
    <p style="margin:0;font-size:15px;color:#0f172a;line-height:1.6">
      Warm Regards,<br>
      <strong>' . htmlspecialchars($brand['org']) . '</strong>' .
      ($unitHtml !== '' ? '<br>' . $unitHtml : '') . '
    </p>
  </td></tr>
  <tr><td style="padding:14px 28px 20px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;line-height:1.5">
    This is an official onboarding email from Gyanam India Educational Services.<br>
    Contact: contact@gyanamindia.com · +91 98600 03525
  </td></tr>
</table>
</td></tr></table>
</body></html>';

    $text = "Dear {$greet},\n\n"
        . "Greetings from {$brand['org']}!\n\n"
        . "We are delighted to welcome {$name}"
        . ($loc !== '' ? " ({$loc})" : '')
        . " to the {$brand['short']} Family and congratulate you on receiving the official recognition to operate as a {$brand['role']}.\n\n"
        . "Centre details: {$centreLine}\n\n"
        . "We sincerely thank you for placing your trust in {$brand['short']} and choosing to be a part of our growing network of institutions committed to delivering quality education.\n\n"
        . "Through {$brand['short']}, we look forward to supporting your centre with:\n"
        . $supportLisText . "\n"
        . "We are confident that this association will create valuable opportunities for both your institution and the students you serve.\n\n"
        . "Your {$brand['kit']} will be dispatched to your centre by post within the next few days.\n\n"
        . "We look forward to a successful and long-term association with you.\n\n"
        . "🌟 {$brand['tagline']} 🌟\n\n"
        . "Once again, congratulations and a very warm welcome to the {$brand['short']} Family.\n\n"
        . "Warm Regards,\n{$brand['org']}"
        . ($unitText !== '' ? "\n{$unitText}" : '')
        . "\n";

    return [
        'subject' => $brand['subject'] . ($name !== '' ? (' — ' . $name) : ''),
        'html'    => $html,
        'text'    => $text,
    ];
}

/**
 * Send ATC welcome/onboarding email and persist status.
 *
 * @return array{success:bool,message:string,skipped?:bool}
 */
function sendAtcWelcomeEmail(PDO $pdo, int $atcId): array
{
    ensureAtcWelcomeMailSchema($pdo);
    if ($atcId <= 0) {
        return ['success' => false, 'message' => 'Invalid ATC id'];
    }

    $st = $pdo->prepare('SELECT * FROM atc_centers WHERE id = ? LIMIT 1');
    $st->execute([$atcId]);
    $atc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$atc) {
        return ['success' => false, 'message' => 'ATC not found'];
    }

    $email = trim((string)($atc['email'] ?? ''));
    if ($email === '') {
        $msg = 'ATC has no email address on file';
        try {
            $pdo->prepare("UPDATE atc_centers SET welcome_email_status = ? WHERE id = ?")
                ->execute(['no_email', $atcId]);
        } catch (Exception $e) {}
        return ['success' => false, 'message' => $msg, 'skipped' => true];
    }

    $content = buildAtcWelcomeEmailContent($atc);
    $toName = trim((string)($atc['contact_person'] ?? '')) ?: trim((string)($atc['name'] ?? 'ATC'));
    $result = sendAppMail($email, $toName, $content['subject'], $content['html'], $content['text']);

    try {
        if (!empty($result['success'])) {
            $pdo->prepare("UPDATE atc_centers SET welcome_email_sent_at = NOW(), welcome_email_status = 'sent' WHERE id = ?")
                ->execute([$atcId]);
        } else {
            $pdo->prepare("UPDATE atc_centers SET welcome_email_status = ? WHERE id = ?")
                ->execute([substr('failed: ' . ($result['message'] ?? 'error'), 0, 40), $atcId]);
        }
    } catch (Exception $e) {}

    return $result;
}

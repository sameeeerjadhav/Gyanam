<?php
/**
 * Shared layout for public legal / policy pages (Razorpay website verification).
 *
 * Usage:
 *   $pageTitle = '...';
 *   $pageHeading = '...';
 *   $pageUpdated = '8 September 2026';
 *   ob_start();
 *   // HTML body
 *   $pageBody = ob_get_clean();
 *   require __DIR__ . '/public_legal_layout.php';
 */

if (!isset($pageTitle) || !isset($pageBody)) {
    http_response_code(500);
    exit('Policy page misconfigured.');
}

$pageHeading = $pageHeading ?? $pageTitle;
$pageUpdated = $pageUpdated ?? date('j F Y');
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
if ($base === '' || $base === '.') {
    $base = '';
}

$nav = [
    ['href' => 'about-us.php', 'label' => 'About Us'],
    ['href' => 'pricing.php', 'label' => 'Pricing'],
    ['href' => 'privacy-policy.php', 'label' => 'Privacy Policy'],
    ['href' => 'terms-and-conditions.php', 'label' => 'Terms'],
    ['href' => 'refund-policy.php', 'label' => 'Refunds'],
    ['href' => 'contact-us.php', 'label' => 'Contact'],
];
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="index,follow">
    <title><?= htmlspecialchars($pageTitle) ?> — Gyanam India Educational Services</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f3f5fb;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
            --brand: #3730a3;
            --brand2: #4361ee;
            --soft: #eef2ff;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Sora', system-ui, sans-serif;
            background:
                radial-gradient(900px 420px at 10% -10%, rgba(67,97,238,.18), transparent 60%),
                radial-gradient(700px 380px at 100% 0%, rgba(55,48,163,.12), transparent 55%),
                var(--bg);
            color: var(--text);
            line-height: 1.65;
        }
        a { color: var(--brand2); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .top {
            background: linear-gradient(135deg, #312e81, #4361ee);
            color: #fff;
            padding: 1rem 1.25rem;
        }
        .top-inner, .wrap, .foot-inner {
            max-width: 920px;
            margin: 0 auto;
        }
        .brand {
            display: flex; align-items: center; gap: .85rem;
            color: #fff; text-decoration: none;
        }
        .brand:hover { text-decoration: none; }
        .brand img { width: 42px; height: 42px; object-fit: contain; background: #fff; border-radius: 10px; padding: 4px; }
        .brand strong { display: block; font-size: 1rem; font-weight: 800; letter-spacing: -.02em; }
        .brand span { display: block; font-size: .75rem; opacity: .85; }
        .nav {
            display: flex; flex-wrap: wrap; gap: .45rem .7rem;
            margin-top: .9rem;
        }
        .nav a {
            color: #e0e7ff;
            font-size: .78rem;
            font-weight: 700;
            padding: .35rem .65rem;
            border-radius: 999px;
            background: rgba(255,255,255,.08);
            border: 1px solid rgba(255,255,255,.12);
            text-decoration: none;
        }
        .nav a.active, .nav a:hover {
            background: #fff;
            color: var(--brand);
            text-decoration: none;
        }
        .wrap { padding: 1.5rem 1.25rem 2.5rem; }
        .card {
            background: var(--card);
            border: 1.5px solid var(--line);
            border-radius: 18px;
            padding: 1.5rem 1.4rem 1.75rem;
            box-shadow: 0 10px 30px rgba(15,23,42,.05);
        }
        h1 {
            margin: 0 0 .35rem;
            font-size: 1.55rem;
            letter-spacing: -.03em;
            font-weight: 800;
        }
        .updated {
            margin: 0 0 1.25rem;
            color: var(--muted);
            font-size: .8rem;
            font-weight: 600;
        }
        h2 {
            margin: 1.35rem 0 .45rem;
            font-size: 1.05rem;
            font-weight: 800;
            color: #1e1b4b;
        }
        p, li { color: #334155; font-size: .92rem; }
        ul { padding-left: 1.15rem; }
        li { margin: .35rem 0; }
        .note {
            background: var(--soft);
            border: 1px solid #c7d2fe;
            border-radius: 12px;
            padding: .9rem 1rem;
            margin: 1rem 0;
            font-size: .88rem;
            color: #312e81;
        }
        .cta {
            display: inline-flex; align-items: center; gap: .4rem;
            margin-top: 1rem;
            padding: .65rem 1rem;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--brand2), var(--brand));
            color: #fff !important;
            font-weight: 700;
            font-size: .85rem;
            text-decoration: none !important;
        }
        .foot {
            border-top: 1px solid var(--line);
            background: #fff;
            padding: 1.1rem 1.25rem 1.4rem;
            color: var(--muted);
            font-size: .78rem;
        }
        .foot-links { display:flex; flex-wrap:wrap; gap:.55rem .9rem; margin-bottom:.55rem; }
        .foot-links a { color: var(--muted); font-weight: 700; }
        @media (max-width: 640px) {
            h1 { font-size: 1.3rem; }
            .card { padding: 1.15rem 1rem 1.35rem; }
        }
    </style>
</head>
<body>
    <header class="top">
        <div class="top-inner">
            <a class="brand" href="<?= htmlspecialchars(($base ? $base . '/' : '') . 'index.php') ?>">
                <img src="<?= htmlspecialchars(($base ? $base . '/' : '') . 'assets/logo.png') ?>" alt="Gyanam India">
                <div>
                    <strong>Gyanam India Educational Services</strong>
                    <span>Official portal — gyanamindia.labxco.in</span>
                </div>
            </a>
            <nav class="nav" aria-label="Policy pages">
                <?php foreach ($nav as $item): ?>
                    <a href="<?= htmlspecialchars($item['href']) ?>" class="<?= $current === $item['href'] ? 'active' : '' ?>">
                        <?= htmlspecialchars($item['label']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
    </header>

    <main class="wrap">
        <article class="card">
            <h1><?= htmlspecialchars($pageHeading) ?></h1>
            <p class="updated">Last updated: <?= htmlspecialchars($pageUpdated) ?></p>
            <?= $pageBody ?>
            <a class="cta" href="index.php">← Back to Login Portal</a>
        </article>
    </main>

    <footer class="foot">
        <div class="foot-inner">
            <div class="foot-links">
                <?php foreach ($nav as $item): ?>
                    <a href="<?= htmlspecialchars($item['href']) ?>"><?= htmlspecialchars($item['label']) ?></a>
                <?php endforeach; ?>
            </div>
            <div>© <?= date('Y') ?> Gyanam India Educational Services · D16, 18, 20, Golani Market, Jalgaon, Maharashtra 425001</div>
            <div>Email: <a href="mailto:contact@gyanamindia.com">contact@gyanamindia.com</a> · Phone: <a href="tel:+919860003525">+91 98600 03525</a></div>
        </div>
    </footer>
</body>
</html>

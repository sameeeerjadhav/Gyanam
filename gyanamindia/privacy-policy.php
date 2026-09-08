<?php
$pageTitle = 'Privacy Policy';
$pageHeading = 'Privacy Policy';
$pageUpdated = '8 September 2026';
ob_start();
?>
<p>Gyanam India Educational Services (“Gyanam”, “we”, “us”) operates the online portal at <strong>https://gyanamindia.labxco.in</strong>. This Privacy Policy explains how we collect, use, and protect information when you use our portal and related payment services.</p>

<div class="note">Online share / fee payments on this portal are processed securely through Razorpay. Payment card or UPI credentials are handled by Razorpay and are not stored on our servers.</div>

<h2>1. Information we collect</h2>
<ul>
    <li>Account details for authorised users (Head Office, DLC, ATC, Training) such as name, username, mobile, and email.</li>
    <li>Student / centre records entered by authorised centres (admission, course, fee, exam, and certificate data).</li>
    <li>Payment transaction references (order ID, payment ID, amount, status) for reconciliation.</li>
    <li>Technical logs such as login time, IP address, and browser type for security.</li>
</ul>

<h2>2. How we use information</h2>
<ul>
    <li>To provide education franchise / centre management services.</li>
    <li>To process and reconcile payments of HO / DLC shares and related fees.</li>
    <li>To issue receipts, marksheets, certificates, and operational reports.</li>
    <li>To prevent fraud, misuse, and unauthorised access.</li>
    <li>To comply with legal and regulatory requirements.</li>
</ul>

<h2>3. Sharing of information</h2>
<p>We do not sell personal data. We may share limited data with:</p>
<ul>
    <li><strong>Razorpay</strong> — to process online payments and refunds.</li>
    <li><strong>Authorised centres / offices</strong> — only data needed for their role.</li>
    <li><strong>Law enforcement / regulators</strong> — when legally required.</li>
</ul>

<h2>4. Data security</h2>
<p>We use role-based access, encrypted transport (HTTPS), and operational controls. Users must keep login credentials confidential.</p>

<h2>5. Data retention</h2>
<p>Academic, fee, and payment records are retained as required for business, audit, and legal purposes.</p>

<h2>6. Your rights</h2>
<p>Authorised users may request correction of inaccurate account or centre data by contacting Head Office. Students should contact their ATC centre first for admission-related corrections.</p>

<h2>7. Contact for privacy queries</h2>
<p>
    Gyanam India Educational Services<br>
    D16, 18, 20, Golani Market, Jalgaon, Maharashtra 425001<br>
    Email: <a href="mailto:contact@gyanamindia.com">contact@gyanamindia.com</a><br>
    Phone: <a href="tel:+919860003525">+91 98600 03525</a> / <a href="tel:+919370982117">+91 93709 82117</a>
</p>
<?php
$pageBody = ob_get_clean();
require __DIR__ . '/includes/public_legal_layout.php';

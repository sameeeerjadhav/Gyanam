<?php
$pageTitle = 'Contact Us';
$pageHeading = 'Contact Us';
$pageUpdated = '8 September 2026';
ob_start();
?>
<p>Reach Gyanam India Educational Services for portal support, payment queries, franchise information, and Head Office assistance.</p>

<h2>Head Office</h2>
<p>
    <strong>Gyanam India Educational Services</strong><br>
    D16, 18, 20, Golani Market,<br>
    Jalgaon, Maharashtra 425001, India
</p>

<h2>Phone</h2>
<ul>
    <li><a href="tel:+919860003525">+91 98600 03525</a></li>
    <li><a href="tel:+919370982117">+91 93709 82117</a></li>
</ul>

<h2>Email</h2>
<ul>
    <li>General / official: <a href="mailto:contact@gyanamindia.com">contact@gyanamindia.com</a></li>
</ul>

<h2>Portal</h2>
<ul>
    <li>Login portal: <a href="https://gyanamindia.labxco.in">https://gyanamindia.labxco.in</a></li>
    <li>Public website: <a href="https://www.gyanamindia.com" target="_blank" rel="noopener">https://www.gyanamindia.com</a></li>
</ul>

<div class="note">
    Payment support: if an amount was deducted but not reflected on the portal, email us with Razorpay Payment ID within 7 days. Refunds, when approved, are processed to the original payment method and may take 5–7 business days to appear in your bank/UPI account.
</div>

<h2>Business hours</h2>
<p>Monday to Saturday, 10:00 AM – 6:00 PM IST (excluding public holidays).</p>
<?php
$pageBody = ob_get_clean();
require __DIR__ . '/includes/public_legal_layout.php';

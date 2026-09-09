<?php
$pageTitle = 'About Us';
$pageHeading = 'About Us';
$pageUpdated = '8 September 2026';
ob_start();
?>
<p><strong>Gyanam India Educational Services</strong> is an education organisation focused on brain-development and skill programs such as Abacus, Vedic Maths, Phonics, and IT learning through a network of authorised centres across India.</p>

<h2>What we do</h2>
<ul>
    <li>Franchise / centre-led education programs for children and learners.</li>
    <li>Training and quality support for certified educators.</li>
    <li>Study materials, workshops, competitions, and academic support.</li>
    <li>Digital portal operations for admissions, fees/shares, exams, dispatches, and certificates.</li>
</ul>

<h2>This website</h2>
<p><strong>https://portal.gyanamindia.com</strong> is our official operations and payments portal used by Head Office, DLC offices, and ATC centres. Public marketing information is also available at <a href="https://www.gyanamindia.com" target="_blank" rel="noopener">www.gyanamindia.com</a>.</p>

<div class="note">Online payments on this portal are made by authorised logged-in centre users (not open public checkout). Payments are processed by Razorpay.</div>

<h2>Registered / Head Office address</h2>
<p>
    Gyanam India Educational Services<br>
    D16, 18, 20, Golani Market, Jalgaon, Maharashtra 425001<br>
    Email: <a href="mailto:contact@gyanamindia.com">contact@gyanamindia.com</a><br>
    Phone: <a href="tel:+919860003525">+91 98600 03525</a> / <a href="tel:+919370982117">+91 93709 82117</a>
</p>
<?php
$pageBody = ob_get_clean();
require __DIR__ . '/includes/public_legal_layout.php';

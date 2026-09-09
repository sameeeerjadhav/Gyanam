<?php
$pageTitle = 'Terms and Conditions';
$pageHeading = 'Terms and Conditions';
$pageUpdated = '8 September 2026';
ob_start();
?>
<p>These Terms and Conditions govern use of the Gyanam India Educational Services portal at <strong>https://portal.gyanamindia.com</strong> and related services, including online payments.</p>

<h2>1. About the portal</h2>
<p>This website is an official business portal for authorised Head Office, DLC Office, ATC Centre, and Training users of Gyanam India Educational Services. It is used for centre operations, student admissions, fee/share payments, exams coordination, certificates, and related administration.</p>

<h2>2. Eligibility and access</h2>
<ul>
    <li>Access is restricted to users issued credentials by Gyanam Head Office / authorised offices.</li>
    <li>Users must not share login credentials.</li>
    <li>Gyanam may suspend access in case of misuse, non-payment, or policy violation.</li>
</ul>

<h2>3. Services</h2>
<ul>
    <li>Course / centre management tools.</li>
    <li>Student admission and fee tracking.</li>
    <li>Online payment of applicable HO / DLC shares via Razorpay.</li>
    <li>Dispatch, certificate, exam, and reporting workflows as enabled for each role.</li>
</ul>

<h2>4. Payments</h2>
<ul>
    <li>Online payments are collected through Razorpay Checkout on this domain.</li>
    <li>Payment requires a valid portal login of an authorised centre user.</li>
    <li>Successful payment status is confirmed after gateway verification.</li>
    <li>Users must verify amount and student selection before paying.</li>
</ul>

<h2>5. User responsibilities</h2>
<ul>
    <li>Enter accurate student and payment information.</li>
    <li>Retain receipts / transaction IDs for records.</li>
    <li>Report unauthorised access or failed-but-debited payments promptly.</li>
</ul>

<h2>6. Intellectual property</h2>
<p>Portal content, branding, course materials design, and software are owned by or licensed to Gyanam India Educational Services and may not be copied or redistributed without permission.</p>

<h2>7. Limitation of liability</h2>
<p>While we take reasonable care to keep the portal available and secure, Gyanam is not liable for delays caused by internet outages, payment gateway downtime, bank processing delays, or incorrect data entered by users.</p>

<h2>8. Governing law</h2>
<p>These terms are governed by the laws of India. Disputes are subject to the jurisdiction of courts in Jalgaon, Maharashtra, unless otherwise required by law.</p>

<h2>9. Contact</h2>
<p>
    Email: <a href="mailto:contact@gyanamindia.com">contact@gyanamindia.com</a><br>
    Phone: <a href="tel:+919860003525">+91 98600 03525</a> / <a href="tel:+919370982117">+91 93709 82117</a><br>
    Address: D16, 18, 20, Golani Market, Jalgaon, Maharashtra 425001
</p>
<?php
$pageBody = ob_get_clean();
require __DIR__ . '/includes/public_legal_layout.php';

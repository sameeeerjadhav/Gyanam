<?php
$pageTitle = 'Pricing';
$pageHeading = 'Pricing Details';
$pageUpdated = '8 September 2026';
ob_start();
?>
<p>Gyanam India Educational Services offers education programs through authorised ATC centres. Pricing depends on course, material option, and centre configuration.</p>

<h2>1. Student course fees</h2>
<ul>
    <li>Final student fees are set / displayed by the authorised ATC centre for each course (with or without material, where applicable).</li>
    <li>Students pay course fees to their ATC centre as per the centre’s admission process.</li>
</ul>

<h2>2. HO / DLC share payments (portal)</h2>
<ul>
    <li>Authorised centres use this portal to pay applicable Head Office / DLC shares for admitted students.</li>
    <li>Share amounts are configured per course by Head Office and shown inside the logged-in portal before payment.</li>
    <li>Online share payments are collected via Razorpay Checkout on <strong>https://portal.gyanamindia.com</strong>.</li>
</ul>

<h2>3. Example of online portal payment</h2>
<p>Share payments are typically small operational amounts (for example a few hundred rupees per student, depending on course configuration). The exact payable total is always shown on the payment screen before confirmation.</p>

<div class="note">There is no open public shopping cart. Only authorised logged-in users can initiate payments for their centre’s pending shares.</div>

<h2>4. Taxes and receipts</h2>
<p>Applicable taxes, if any, are included or shown as per Gyanam’s billing practice. Digital / printable receipts are available in the portal after successful payment.</p>

<h2>5. Need a quotation?</h2>
<p>For franchise / course pricing enquiries, contact Head Office:</p>
<p>
    Email: <a href="mailto:contact@gyanamindia.com">contact@gyanamindia.com</a><br>
    Phone: <a href="tel:+919860003525">+91 98600 03525</a> / <a href="tel:+919370982117">+91 93709 82117</a>
</p>
<?php
$pageBody = ob_get_clean();
require __DIR__ . '/includes/public_legal_layout.php';

<?php
$pageTitle = 'Cancellation and Refund Policy';
$pageHeading = 'Cancellation & Refund Policy';
$pageUpdated = '8 September 2026';
ob_start();
?>
<p>This Cancellation and Refund Policy applies to online payments made on <strong>https://gyanamindia.labxco.in</strong> through Razorpay for Gyanam India Educational Services.</p>

<div class="note">Course / admission fees charged by ATC centres to students are governed by the centre’s admission agreement. This page mainly covers online portal payments (such as HO / DLC share payments) processed via Razorpay.</div>

<h2>1. Nature of online payments</h2>
<ul>
    <li>Online payments on this portal are typically <strong>business-to-business / franchise operational payments</strong> (for example HO share / DLC share) made by authorised centres.</li>
    <li>These payments are generally <strong>non-refundable</strong> once successfully captured, except in the cases listed below.</li>
</ul>

<h2>2. When refunds may be issued</h2>
<p>Gyanam may initiate a refund in these situations:</p>
<ul>
    <li><strong>Duplicate payment</strong> for the same obligation due to a technical retry.</li>
    <li><strong>Payment deducted but not confirmed</strong> on the portal after gateway / bank failure (amount captured without completing the intended transaction).</li>
    <li><strong>Incorrect amount charged</strong> due to a verified system error.</li>
    <li>Any other case approved in writing by Gyanam Head Office.</li>
</ul>

<h2>3. Non-refundable cases</h2>
<ul>
    <li>Successfully completed share / fee payments against valid student obligations.</li>
    <li>User error in selecting students / amounts where service obligation remains due.</li>
    <li>Change of mind after successful payment confirmation.</li>
    <li>Student course fee refunds requested from ATC after admission (handled by the ATC under its admission terms; usually non-refundable).</li>
</ul>

<h2>4. How to request a refund</h2>
<p>Email <a href="mailto:contact@gyanamindia.com">contact@gyanamindia.com</a> within <strong>7 days</strong> of the transaction with:</p>
<ul>
    <li>Payment ID / Order ID from Razorpay</li>
    <li>Date, amount, and centre name</li>
    <li>Reason for request and supporting screenshot</li>
</ul>

<h2>5. Refund timeline</h2>
<ul>
    <li>Approved refunds are initiated via Razorpay to the original payment method.</li>
    <li>After initiation, banks / UPI providers typically take <strong>5–7 business days</strong> to credit the customer account.</li>
    <li>Refund reference (RRN) may appear after the bank processes the credit.</li>
</ul>

<h2>6. Cancellations</h2>
<p>Online checkout can be cancelled before successful payment. Once payment is captured and confirmed, cancellation follows the refund rules above.</p>

<h2>7. Contact</h2>
<p>
    Gyanam India Educational Services<br>
    D16, 18, 20, Golani Market, Jalgaon, Maharashtra 425001<br>
    Email: <a href="mailto:contact@gyanamindia.com">contact@gyanamindia.com</a><br>
    Phone: <a href="tel:+919860003525">+91 98600 03525</a> / <a href="tel:+919370982117">+91 93709 82117</a>
</p>
<?php
$pageBody = ob_get_clean();
require __DIR__ . '/includes/public_legal_layout.php';

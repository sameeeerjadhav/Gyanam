<?php
/**
 * Razorpay Webhook — Share Payments
 *
 * Dashboard URL to configure:
 *   https://gyanamindia.labxco.in/webhooks/razorpay.php
 *
 * Events to enable:
 *   - payment.captured
 *   - order.paid
 *   - payment.failed
 *
 * Set RAZORPAY_WEBHOOK_SECRET in config/razorpay.php
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/razorpay.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

$rawBody = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

$webhookSecret = defined('RAZORPAY_WEBHOOK_SECRET') ? (string)RAZORPAY_WEBHOOK_SECRET : '';
if ($webhookSecret === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Webhook secret not configured']);
    exit;
}

$expected = hash_hmac('sha256', $rawBody, $webhookSecret);
if (!hash_equals($expected, (string)$signature)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid signature']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$event = (string)($payload['event'] ?? '');
$pdo = getDBConnection();
ensureSharePaymentSchema($pdo);

try {
    if ($event === 'payment.captured' || $event === 'order.paid') {
        $paymentEntity = $payload['payload']['payment']['entity']
            ?? $payload['payload']['order']['entity']
            ?? null;

        // order.paid may nest payment under payment.entity
        if ($event === 'order.paid' && empty($paymentEntity['id'])) {
            $paymentEntity = $payload['payload']['payment']['entity'] ?? $paymentEntity;
        }

        $orderId = (string)(
            $paymentEntity['order_id']
            ?? ($payload['payload']['order']['entity']['id'] ?? '')
        );
        $paymentIdRzp = (string)($paymentEntity['id'] ?? '');
        $notesPaymentId = intval($paymentEntity['notes']['payment_id'] ?? 0);

        $row = null;
        if ($orderId !== '') {
            $st = $pdo->prepare("SELECT * FROM share_payments WHERE razorpay_order_id = ? LIMIT 1");
            $st->execute([$orderId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$row && $notesPaymentId > 0) {
            $st = $pdo->prepare("SELECT * FROM share_payments WHERE id = ? LIMIT 1");
            $st->execute([$notesPaymentId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (!$row) {
            // Acknowledge — may be a non-share payment
            echo json_encode(['ok' => true, 'ignored' => true, 'reason' => 'payment_not_found']);
            exit;
        }

        $result = completeSharePayment(
            $pdo,
            (int)$row['id'],
            $paymentIdRzp !== '' ? $paymentIdRzp : null,
            $orderId !== '' ? $orderId : null,
            null
        );
        echo json_encode(['ok' => (bool)$result['success'], 'result' => $result]);
        exit;
    }

    if ($event === 'payment.failed') {
        $paymentEntity = $payload['payload']['payment']['entity'] ?? [];
        $orderId = (string)($paymentEntity['order_id'] ?? '');
        $notesPaymentId = intval($paymentEntity['notes']['payment_id'] ?? 0);
        $reason = (string)($paymentEntity['error_description'] ?? $paymentEntity['error_code'] ?? 'Payment failed');

        $localId = 0;
        if ($orderId !== '') {
            $st = $pdo->prepare("SELECT id FROM share_payments WHERE razorpay_order_id = ? AND status = 'Pending' LIMIT 1");
            $st->execute([$orderId]);
            $localId = (int)($st->fetchColumn() ?: 0);
        }
        if ($localId <= 0 && $notesPaymentId > 0) {
            $localId = $notesPaymentId;
        }
        if ($localId > 0) {
            markSharePaymentStatus($pdo, $localId, 'Failed', null, mb_substr($reason, 0, 250));
        }
        echo json_encode(['ok' => true, 'event' => $event]);
        exit;
    }

    // Other events — acknowledge so Razorpay doesn't retry forever
    echo json_encode(['ok' => true, 'ignored' => true, 'event' => $event]);
} catch (Throwable $e) {
    error_log('[RazorpayWebhook] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}

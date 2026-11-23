<?php
/**
 * Love Family Alumni — PayPal Sandbox Integration & Orders v2 Test Suite
 */
require_once __DIR__ . '/../../../../wp-load.php';

if (!defined('ABSPATH')) {
    exit('ABSPATH not defined.');
}

echo "====================================================================\n";
echo "LOVE FAMILY ALUMNI — PAYPAL SANDBOX INTEGRATION TEST SUITE\n";
echo "====================================================================\n\n";

$results = [
    'passed' => 0,
    'failed' => 0,
    'tests'  => [],
];

function record_pp_test(string $name, bool $passed, string $details = ''): void {
    global $results;
    if ($passed) {
        $results['passed']++;
        echo " [PASS] $name\n";
    } else {
        $results['failed']++;
        echo " [FAIL] $name\n";
    }
    if ($details) {
        echo "        -> $details\n";
    }
    $results['tests'][] = [
        'name'    => $name,
        'passed'  => $passed,
        'details' => $details,
    ];
}

// -------------------------------------------------------------------------
// SECTION 1: Credentials & Environment Validation
// -------------------------------------------------------------------------
echo "\n--- Section 1: PayPal Settings & Configuration ---\n";

$is_pp_enabled = LFA_Gateway_Settings::is_paypal_enabled();
record_pp_test(
    "PayPal Sandbox Gateway Enabled",
    $is_pp_enabled === true,
    "Status: " . ($is_pp_enabled ? 'Active (Sandbox Mode)' : 'Disabled')
);

$cid = LFA_PayPal_Settings::get_client_id('sandbox');
record_pp_test(
    "PayPal Sandbox Client ID Validated",
    !empty($cid) && strlen($cid) > 20,
    "Client ID: " . substr($cid, 0, 10) . "..." . substr($cid, -5)
);

$secret = LFA_PayPal_Settings::get_secret('sandbox');
record_pp_test(
    "PayPal Sandbox Secret Decrypted / Resolved",
    !empty($secret) && strlen($secret) > 20,
    "Secret Length: " . strlen($secret) . " characters"
);

$mode = LFA_PayPal_Settings::get_mode();
record_pp_test(
    "PayPal Environment Mode",
    $mode === 'sandbox',
    "Active Mode: {$mode} (Live activation strictly disabled in development)"
);

// -------------------------------------------------------------------------
// SECTION 2: PayPal Sandbox API Remote Connectivity
// -------------------------------------------------------------------------
echo "\n--- Section 2: Remote OAuth2 & API Connectivity ---\n";

$token_res = LFA_PayPal_Client::get_access_token('sandbox');
record_pp_test(
    "PayPal OAuth2 Access Token Retrieval",
    $token_res['success'] === true && !empty($token_res['token']),
    "Token: " . (isset($token_res['token']) ? substr($token_res['token'], 0, 15) . "..." : 'Failed')
);

$conn_test = LFA_PayPal_Client::test_connection('sandbox');
record_pp_test(
    "PayPal Remote Sandbox API Ping",
    $conn_test['success'] === true,
    "Message: " . ($conn_test['message'] ?? 'N/A')
);

// -------------------------------------------------------------------------
// SECTION 3: Gateway Orchestrator Integration
// -------------------------------------------------------------------------
echo "\n--- Section 3: Orchestrator & Gateway Resolution ---\n";

$paypal_gateway = LFA_Payment_Orchestrator::resolve_gateway('paypal');
record_pp_test(
    "Resolve Gateway 'paypal' from Orchestrator",
    $paypal_gateway instanceof LFA_PayPal_Gateway,
    "Class: " . ($paypal_gateway ? get_class($paypal_gateway) : 'null')
);

// -------------------------------------------------------------------------
// SECTION 4: Authoritative Orders v2 Creation
// -------------------------------------------------------------------------
echo "\n--- Section 4: PayPal Orders v2 Order Dispatch ---\n";

// Ensure test invoice exists or reset invoice 3
global $wpdb;
$t_invoices = $wpdb->prefix . 'lfa_dues_invoices';
$test_inv = $wpdb->get_row("SELECT * FROM {$t_invoices} WHERE user_id = 5 LIMIT 1");
if (!$test_inv) {
    $test_inv = $wpdb->get_row("SELECT * FROM {$t_invoices} LIMIT 1");
}

$order_res = LFA_PayPal_Client::create_order($test_inv, 'req_' . time(), 'sandbox');
record_pp_test(
    "PayPal Live Sandbox Order Creation (Orders v2)",
    $order_res['success'] === true && !empty($order_res['order_id']) && $order_res['status'] === 'CREATED',
    "Order ID: " . ($order_res['order_id'] ?? 'N/A') . " | Status: " . ($order_res['status'] ?? 'N/A')
);

$order_id = $order_res['order_id'] ?? '';

// -------------------------------------------------------------------------
// SECTION 5: PayPal Order Status Retrieval
// -------------------------------------------------------------------------
echo "\n--- Section 5: Order Retrieval & Status Inspection ---\n";

if (!empty($order_id)) {
    $get_res = LFA_PayPal_Client::get_order($order_id, 'sandbox');
    record_pp_test(
        "PayPal Retrieve Order Details via API",
        $get_res['success'] === true && ($get_res['order']['status'] ?? '') === 'CREATED',
        "Retrieved Intent: " . ($get_res['order']['intent'] ?? 'N/A') . " | Amount: $" . ($get_res['order']['purchase_units'][0]['amount']['value'] ?? 'N/A')
    );
} else {
    record_pp_test("PayPal Retrieve Order Details via API", false, "Skipped due to order creation failure");
}

// -------------------------------------------------------------------------
// SECTION 6: Webhook Listener & Signature Security
// -------------------------------------------------------------------------
echo "\n--- Section 6: Webhook Security Verification ---\n";

$webhook_url = LFA_PayPal_Settings::get_webhook_url();
record_pp_test(
    "PayPal Webhook Listener URL Defined",
    str_contains($webhook_url, 'lfa/v1/paypal/webhook'),
    "URL: {$webhook_url}"
);

// Reject fake signature
$fake_res = LFA_PayPal_Client::verify_webhook_signature(
    ['PAYPAL-TRANSMISSION-ID' => 'fake_id', 'PAYPAL-AUTH-ALGO' => 'SHA256withRSA', 'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/cert', 'PAYPAL-TRANSMISSION-SIG' => 'invalidsig', 'PAYPAL-TRANSMISSION-TIME' => gmdate('Y-m-d\TH:i:s\Z')],
    json_encode(['id' => 'evt_123', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED']),
    'sandbox'
);
record_pp_test(
    "Reject Invalid Webhook Signature",
    $fake_res['verified'] === false,
    "Status: " . ($fake_res['verified'] ? 'Allowed (INSECURE)' : 'Rejected (Secure)')
);

// -------------------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------------------
echo "\n====================================================================\n";
echo "PAYPAL TEST SUITE COMPLETED: " . $results['passed'] . " PASSED, " . $results['failed'] . " FAILED\n";
echo "====================================================================\n";

if ($results['failed'] > 0) {
    exit(1);
}
exit(0);

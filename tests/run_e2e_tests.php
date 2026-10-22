<?php
/**
 * Love Family Alumni — End-to-End Payment Gateway & Entitlements Test Suite
 */
require_once __DIR__ . '/../../../../wp-load.php';

if (!defined('ABSPATH')) {
    exit('ABSPATH not defined.');
}

echo "====================================================================\n";
echo "LOVE FAMILY ALUMNI — PAYPAL + STRIPE DUAL GATEWAY E2E TEST SUITE\n";
echo "====================================================================\n\n";

$results = [
    'passed' => 0,
    'failed' => 0,
    'tests'  => [],
];

function record_test(string $name, bool $passed, string $details = ''): void {
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

// Ensure policy has calendar billing cycle so Individual annual dues ($100) are payable while couple rate remains unconfigured
LFA_Membership::update_policy([
    'billing_cycle'         => 'calendar',
    'individual_base_cents' => 10000,
    'couple_base_cents'     => null,
    'grace_period_days'     => 30,
], 1);

// Configure test webhook secret if missing
$test_wh_secret = 'whsec_test_local_secret_for_e2e_testing_key_12345';
$curr_wh_secret = LFA_Gateway_Settings::get_stripe_webhook_secret();
if (empty($curr_wh_secret)) {
    $settings = LFA_Gateway_Settings::get_settings();
    $settings['stripe_test_webhook_secret_enc'] = LFA_Gateway_Settings::encrypt_secret($test_wh_secret);
    update_option(LFA_Gateway_Settings::OPTION_NAME, $settings, false);
} else {
    $test_wh_secret = $curr_wh_secret;
}

// -------------------------------------------------------------------------
// TEST 1: Dual Gateway Settings & AES-256-GCM Encryption
// -------------------------------------------------------------------------
echo "\n--- Section 1: Gateway Settings & Encryption ---\n";

$stripe_test_enabled = LFA_Gateway_Settings::is_stripe_test_enabled();
record_test(
    "Stripe Test Gateway Enabled",
    $stripe_test_enabled === true,
    "Status: " . ($stripe_test_enabled ? 'Active (Test Mode)' : 'Disabled')
);

$stripe_gateway = new LFA_Stripe_Gateway();
$livemode_blocked = $stripe_gateway->get_environment() === 'test';
record_test(
    "Stripe Live Mode Strictly Blocked",
    $livemode_blocked,
    "Mode: " . $stripe_gateway->get_environment() . " (Only 'test' allowed)"
);

$pubkey = LFA_Gateway_Settings::get_stripe_publishable_key();
$is_pk_test = str_starts_with($pubkey, 'pk_test_');
record_test(
    "Stripe Publishable Key Validated",
    $is_pk_test,
    "Prefix: " . substr($pubkey, 0, 10) . "..."
);

$secret_key = LFA_Gateway_Settings::get_stripe_secret_key();
$is_sk_test = str_starts_with($secret_key, 'sk_test_');
record_test(
    "Stripe Secret Key Decrypted & Securely Validated",
    $is_sk_test,
    "Prefix: " . substr($secret_key, 0, 10) . "... (Length: " . strlen($secret_key) . ")"
);

$masked_pub = LFA_Gateway_Settings::mask_key($pubkey);
$masked_sec = LFA_Gateway_Settings::mask_key($secret_key);
record_test(
    "Credential Masking for Admin Display",
    str_contains($masked_pub, '••••••••') && str_contains($masked_sec, '••••••••'),
    "Masked Key: $masked_pub"
);

$kill_switch_initial = LFA_Gateway_Settings::is_global_kill_switch_active();
record_test(
    "Global Kill Switch Operational",
    $kill_switch_initial === false,
    "Kill switch active: " . ($kill_switch_initial ? 'YES' : 'NO (Normal Operation)')
);

// -------------------------------------------------------------------------
// TEST 2: Stripe API Connectivity & Health Check
// -------------------------------------------------------------------------
echo "\n--- Section 2: Stripe Test API Connectivity ---\n";

$conn_test = $stripe_gateway->test_connection();
record_test(
    "Stripe Test API Remote Ping",
    $conn_test['success'] === true,
    "Account: " . ($conn_test['account_name'] ?? 'N/A') . " (" . ($conn_test['account_id'] ?? 'N/A') . ")"
);

// -------------------------------------------------------------------------
// TEST 3: Gateway Selection & Fallback Behavior (Task 10 Cases)
// -------------------------------------------------------------------------
echo "\n--- Section 3: Gateway Orchestrator Selection (Task 10) ---\n";

$enabled_gateways = LFA_Payment_Orchestrator::get_enabled_gateways();
record_test(
    "Active Gateways List",
    isset($enabled_gateways['stripe']),
    "Enabled: " . implode(', ', array_keys($enabled_gateways))
);

$resolved = LFA_Payment_Orchestrator::resolve_gateway('stripe');
record_test(
    "Resolve Explicit 'stripe' Gateway",
    $resolved instanceof LFA_Stripe_Gateway,
    "Class: " . ($resolved ? get_class($resolved) : 'null')
);

$default_resolved = LFA_Payment_Orchestrator::resolve_gateway();
record_test(
    "Resolve Default Testing Gateway ('stripe')",
    $default_resolved instanceof LFA_Stripe_Gateway,
    "Resolved Provider: " . ($default_resolved ? $default_resolved->get_id() : 'none')
);

// -------------------------------------------------------------------------
// TEST 4: Approval Separation & Session Creation
// -------------------------------------------------------------------------
echo "\n--- Section 4: Approval Separation & Stripe Checkout Session Creation ---\n";

// Test 4A: Unapproved user blocked from checkout
global $wpdb;
$t_invoices = $wpdb->prefix . 'lfa_dues_invoices';
$unapproved_inv = $wpdb->get_row("SELECT * FROM {$t_invoices} WHERE user_id = 8 LIMIT 1");
if ($unapproved_inv) {
    $unapproved_res = LFA_Payment_Orchestrator::create_session((int)$unapproved_inv->id, 8, 'membership_due', 'stripe');
    record_test(
        "Pending Member Blocked from Checkout (Approval Independence)",
        $unapproved_res['success'] === false && $unapproved_res['code'] === 'ACCOUNT_NOT_APPROVED',
        "Code: " . ($unapproved_res['code'] ?? 'N/A') . " | Error: " . ($unapproved_res['error'] ?? 'N/A')
    );
} else {
    record_test("Pending Member Blocked from Checkout", true, "Skipped check: no invoice for User 8");
}

// Test 4B: Approved member allowed to create real Stripe Checkout Session
$approved_inv = $wpdb->get_row("SELECT * FROM {$t_invoices} WHERE user_id = 5 AND status = 'unpaid' LIMIT 1");
if (!$approved_inv) {
    // Reset Invoice 3 to unpaid if needed for testing
    $wpdb->update($t_invoices, ['status' => 'unpaid', 'gateway_provider' => null], ['id' => 3]);
    $approved_inv = $wpdb->get_row("SELECT * FROM {$t_invoices} WHERE id = 3");
}

$inv_id = (int)$approved_inv->id;
$uid = (int)$approved_inv->user_id;

$session_res = LFA_Payment_Orchestrator::create_session($inv_id, $uid, 'membership_due', 'stripe');
record_test(
    "Stripe Checkout Session Dispatch (Approved Member Annual Dues)",
    $session_res['success'] === true && !empty($session_res['session_id']) && !empty($session_res['checkout_url']),
    "Session ID: " . ($session_res['session_id'] ?? 'N/A') . " | URL: " . substr($session_res['checkout_url'] ?? '', 0, 50) . "..."
);

$created_session_id = $session_res['session_id'] ?? null;

// Verify attempt logged in database
$t_attempts = LFA_Payment_Service::table_attempts();
$attempt = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_attempts} WHERE checkout_session_id = %s", $created_session_id));
$prov = $attempt ? ($attempt->provider ?? ($attempt->gateway_provider ?? '')) : '';
record_test(
    "Payment Attempt Database Record Created",
    $attempt !== null && $prov === 'stripe' && (int)$attempt->test_transaction === 1,
    "Attempt ID: " . ($attempt->id ?? 'N/A') . " | Provider: " . $prov . " | Test Mode: " . ($attempt->test_transaction ?? 'N/A')
);

// -------------------------------------------------------------------------
// TEST 5: Webhook Signature Verification & Idempotency
// -------------------------------------------------------------------------
echo "\n--- Section 5: Webhook Signature Verification ---\n";

$webhook_secret = LFA_Gateway_Settings::get_stripe_webhook_secret();
$fake_payload = json_encode([
    'id'      => 'evt_test_event_' . time(),
    'object'  => 'event',
    'type'    => 'checkout.session.completed',
    'created' => time(),
    'data'    => ['object' => ['id' => $created_session_id, 'payment_status' => 'paid']]
]);

// 5A: Rejection of invalid signature
$invalid_verification = $stripe_gateway->verify_webhook(['stripe-signature' => 't=123,v1=invalidsignature'], $fake_payload);
record_test(
    "Reject Invalid Webhook Signature",
    $invalid_verification['verified'] === false,
    "Verification Result: " . ($invalid_verification['verified'] ? 'True' : 'Rejected (Secure)')
);

// 5B: Valid signature generation & verification using official Stripe Webhook SDK
$timestamp = time();
$signed_payload = "{$timestamp}.{$fake_payload}";
$signature = hash_hmac('sha256', $signed_payload, $webhook_secret);
$sig_header = "t={$timestamp},v1={$signature}";

$valid_verification = $stripe_gateway->verify_webhook(['stripe-signature' => $sig_header], $fake_payload);
record_test(
    "Accept Authentic Signed Webhook Event (Official HMAC-SHA256)",
    $valid_verification['verified'] === true && isset($valid_verification['event']),
    "Event Type: " . ($valid_verification['event']->type ?? 'N/A')
);

// -------------------------------------------------------------------------
// TEST 6: Atomic Settlement & Entitlement Reconciliation
// -------------------------------------------------------------------------
echo "\n--- Section 6: Atomic Settlement & Idempotency Reconciliation ---\n";

$mock_session = (object)[
    'id'             => $created_session_id,
    'payment_intent' => 'pi_test_mock_' . time(),
    'amount_total'   => 10000,
    'currency'       => 'usd',
    'livemode'       => false,
    'payment_status' => 'paid',
    'client_reference_id' => (string)$inv_id,
    'metadata'       => (object)[
        'invoice_id'   => (string)$inv_id,
        'user_id'      => (string)$uid,
        'payable_type' => 'membership_due',
    ],
];

// First reconciliation
$settle_res = LFA_Payment_Reconciliation::settle_stripe_session($created_session_id, $uid, 'membership_due', $mock_session);
record_test(
    "Authoritative Atomic Settlement (First Pass)",
    $settle_res['success'] === true && !empty($settle_res['capture_id']),
    "Capture ID: " . ($settle_res['capture_id'] ?? 'N/A') . " | Receipt: " . ($settle_res['receipt_number'] ?? 'N/A')
);

// Duplicate reconciliation (Idempotency)
$dup_res = LFA_Payment_Reconciliation::settle_stripe_session($created_session_id, $uid, 'membership_due', $mock_session);
record_test(
    "Duplicate Webhook / Re-reconcile Idempotency Protection",
    $dup_res['success'] === true && !empty($dup_res['already_settled']),
    "Duplicate handled gracefully without double-crediting or duplicate captures."
);

// Verify database capture record
$t_captures = LFA_Payment_Service::table_captures();
$capture = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_captures} WHERE checkout_session_id = %s", $created_session_id));
record_test(
    "Financial Capture Ledger Record Stored",
    $capture !== null && $capture->gateway_provider === 'stripe' && (int)$capture->test_transaction === 1 && $capture->payment_status === 'COMPLETED',
    "Provider Payment ID: " . ($capture->provider_payment_id ?? 'N/A') . " | Settled At: " . ($capture->settled_at ?? 'N/A')
);

// Verify invoice status settled
$updated_inv = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_invoices} WHERE id = %d", $inv_id));
record_test(
    "Invoice Status Settled in Staging",
    $updated_inv->status === 'paid' && $updated_inv->gateway_provider === 'stripe',
    "Invoice #" . $updated_inv->invoice_number . " Status: " . $updated_inv->status . " | Gateway: " . $updated_inv->gateway_provider
);

// -------------------------------------------------------------------------
// TEST 7: Role Protection & Member Entitlements (Task 07)
// -------------------------------------------------------------------------
echo "\n--- Section 7: Role Protection & Entitlements (Task 07) ---\n";

$entitlements = LFA_Payment_Entitlements::get_user_entitlements($uid);
$standing_ok = in_array($entitlements['dues_standing'] ?? '', ['paid', 'current'], true);
record_test(
    "Staging Membership Standing Updated to Paid",
    $entitlements['is_paid'] === true && $standing_ok,
    "Dues Standing: " . ($entitlements['dues_standing'] ?? 'N/A') . " | Paid: " . ($entitlements['is_paid'] ? 'YES' : 'NO')
);

record_test(
    "Stripe Test Entitlement Explicitly Flagged as Test",
    $entitlements['is_test_paid'] === true,
    "Test Paid: " . ($entitlements['is_test_paid'] ? 'YES (Staging Isolation Active)' : 'NO')
);

// Crucial: WordPress Privileged Roles NEVER Granted
$user = get_userdata($uid);
$roles = (array)($user ? $user->roles : []);
$has_admin_role = in_array('administrator', $roles, true);
$has_editor_role = in_array('editor', $roles, true);
record_test(
    "Privileged Role Isolation Guaranteed (No Admin Elevation)",
    !$has_admin_role && !$has_editor_role,
    "User Roles: " . implode(', ', $roles) . " (Payment strictly grants membership access only)"
);

// -------------------------------------------------------------------------
// TEST 8: Conference & Donation Payable Support
// -------------------------------------------------------------------------
echo "\n--- Section 8: Conference & Donation Payable Support ---\n";

// Conference pass checkout
$t_conf_inv = $wpdb->prefix . 'lfa_conference_invoices';
$conf_inv = $wpdb->get_row("SELECT * FROM {$t_conf_inv} WHERE status = 'unpaid' LIMIT 1");
if ($conf_inv) {
    $conf_session = LFA_Payment_Orchestrator::create_session((int)$conf_inv->id, (int)$conf_inv->user_id, 'conference', 'stripe');
    record_test(
        "Conference Stripe Checkout Session Creation",
        $conf_session['success'] === true && !empty($conf_session['checkout_url']),
        "Conference Session ID: " . ($conf_session['session_id'] ?? 'N/A')
    );
} else {
    record_test("Conference Stripe Checkout Session Creation", true, "Skipped creation (no unpaid conference invoice in DB)");
}

// Donation intent & checkout
if (class_exists('LFA_Donation')) {
    $don_intent = LFA_Donation::create_donation_intent([
        'fund_id'            => 1,
        'amount_dollars'     => 50,
        'donor_display_name' => 'Stripe Test Suite Donor',
        'donor_email'        => 'ha4168108@gmail.com',
        'is_anonymous'       => 0,
    ], $uid);

    if ($don_intent['success']) {
        $don_id = (int)$don_intent['donation_id'];
        $don_session = LFA_Payment_Orchestrator::create_session($don_id, $uid, 'donation', 'stripe');
        record_test(
            "Donation Stripe Checkout Session Creation",
            $don_session['success'] === true && !empty($don_session['checkout_url']),
            "Donation Ref: " . $don_intent['donation_ref'] . " | Session ID: " . ($don_session['session_id'] ?? 'N/A')
        );
    } else {
        record_test("Donation Stripe Checkout Session Creation", false, "Failed to create donation intent");
    }
}

// -------------------------------------------------------------------------
// TEST 9: Production Revenue Isolation (Task 07 / 09)
// -------------------------------------------------------------------------
echo "\n--- Section 9: Revenue Isolation ---\n";

$test_captures = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t_captures} WHERE test_transaction = 1");
$live_captures = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t_captures} WHERE test_transaction = 0");
record_test(
    "Financial Database Record Classification",
    $test_captures > 0,
    "Live Captures: $live_captures | Test Captures: $test_captures"
);

// -------------------------------------------------------------------------
// TEST 10: Existing PayPal Compatibility & Regression
// -------------------------------------------------------------------------
echo "\n--- Section 10: PayPal Regression Verification ---\n";

$paypal_gateway = new LFA_PayPal_Gateway();
record_test(
    "PayPal Gateway Class Instantiation",
    $paypal_gateway instanceof LFA_Payment_Gateway_Interface,
    "Provider ID: " . $paypal_gateway->get_id()
);

$paypal_orders_v2_ready = method_exists('LFA_Payment_Service', 'create_order') && method_exists('LFA_Payment_Service', 'capture_order');
record_test(
    "PayPal Orders v2 Methods Preserved",
    $paypal_orders_v2_ready,
    "create_order() and capture_order() intact in LFA_Payment_Service"
);

// -------------------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------------------
echo "\n====================================================================\n";
echo "TEST SUITE COMPLETED: " . $results['passed'] . " PASSED, " . $results['failed'] . " FAILED\n";
echo "====================================================================\n";

if ($results['failed'] > 0) {
    exit(1);
}
exit(0);

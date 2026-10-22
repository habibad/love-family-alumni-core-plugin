<?php
require_once __DIR__ . '/../../../../wp-load.php';
global $wpdb;

$user_id = 10;
$user = get_user_by('id', $user_id);
echo "User: " . ($user ? $user->user_login : 'null') . "\n";
$mems = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . LFA_Membership::table_memberships() . " WHERE user_id = %d", $user_id));
print_r($mems);

$meta = get_user_meta($user_id);
echo "Meta:\n";
foreach ($meta as $k => $v) {
    if (strpos($k, 'lfa_') === 0) {
        echo " - {$k}: " . print_r($v[0], true) . "\n";
    }
}

$invs = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . LFA_Membership::table_invoices() . " WHERE user_id = %d", $user_id));
echo "Invoices:\n";
print_r($invs);

$caps = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . LFA_Payment_Service::table_captures() . " WHERE invoice_id IN (SELECT id FROM " . LFA_Membership::table_invoices() . " WHERE user_id = %d)", $user_id));
echo "Captures:\n";
print_r($caps);

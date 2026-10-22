<?php
require_once __DIR__ . '/../../../../wp-load.php';
global $wpdb;

echo "=== CHECKING FOR COUPLE MEMBERSHIPS ===\n";
$t_mem = LFA_Membership::table_memberships();
$couple_mems = $wpdb->get_results("SELECT * FROM {$t_mem} WHERE membership_type = 'couple'");
echo "Total couple memberships found in table: " . count($couple_mems) . "\n";
foreach ($couple_mems as $m) {
    $u = get_user_by('id', $m->user_id);
    $login = $u ? $u->user_login : 'unknown';
    echo "ID: {$m->id} | User: {$m->user_id} ({$login}) | Partner: {$m->partner_name} ({$m->partner_email}) | Status: {$m->status} | Created: {$m->created_at}\n";
}

echo "\n=== CHECKING USER META FOR COUPLE ===\n";
$meta_couples = $wpdb->get_results("SELECT * FROM {$wpdb->usermeta} WHERE meta_key = 'lfa_membership_type' AND meta_value = 'couple'");
echo "Total user_meta with couple: " . count($meta_couples) . "\n";
foreach ($meta_couples as $meta) {
    $u = get_user_by('id', $meta->user_id);
    $login = $u ? $u->user_login : 'unknown';
    echo "User: {$meta->user_id} ({$login}) | Meta ID: {$meta->umeta_id}\n";
}

echo "\n=== CHECKING DUES INVOICES FOR COUPLE USERS ===\n";
$t_inv = LFA_Membership::table_invoices();
if (!empty($couple_mems)) {
    foreach ($couple_mems as $cm) {
        $invs = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t_inv} WHERE user_id = %d", $cm->user_id));
        echo "Invoices for user {$cm->user_id}: " . count($invs) . "\n";
        foreach ($invs as $inv) {
            echo " - Inv: {$inv->invoice_number} | Amount: {$inv->amount_cents} | Status: {$inv->status}\n";
        }
    }
}

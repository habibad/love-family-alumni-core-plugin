<?php
require_once __DIR__ . '/../../../../wp-load.php';
global $wpdb;
$t = LFA_Membership::table_memberships();
$rows = $wpdb->get_results("SELECT id, user_id, membership_type, status FROM {$t}");
foreach ($rows as $r) {
    echo "ID: {$r->id} | User ID: {$r->user_id} | Type: {$r->membership_type} | Status: {$r->status}\n";
}

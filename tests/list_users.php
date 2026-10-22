<?php
require_once __DIR__ . '/../../../../wp-load.php';

$users = get_users();
foreach ($users as $u) {
    echo sprintf(
        "ID: %-3d | Login: %-20s | Roles: %-30s | Approved: %-5s | Dues: %-10s | MemType: %-10s\n",
        $u->ID,
        $u->user_login,
        implode(',', $u->roles),
        get_user_meta($u->ID, 'lfa_account_status', true) ?: 'none',
        get_user_meta($u->ID, 'lfa_dues_status', true) ?: 'none',
        get_user_meta($u->ID, 'lfa_membership_type', true) ?: 'none'
    );
}

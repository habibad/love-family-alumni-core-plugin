<?php
require_once __DIR__ . '/../../../../wp-load.php';
global $wpdb;

echo "=== USERS ===\n";
$users = get_users();
foreach ($users as $u) {
    $meta_type = get_user_meta($u->ID, 'lfa_membership_type', true);
    $meta_status = get_user_meta($u->ID, 'lfa_account_status', true);
    $dues_status = get_user_meta($u->ID, 'lfa_dues_status', true);
    $roles = implode(', ', $u->roles);
    echo "User ID: {$u->ID} | Login: {$u->user_login} | Roles: [{$roles}] | Account Status: {$meta_status} | Meta Type: {$meta_type} | Dues Meta: {$dues_status}\n";
}

echo "\n=== LFA MEMBERSHIPS TABLE ===\n";
$t_mem = LFA_Membership::table_memberships();
$mems = $wpdb->get_results("SELECT * FROM {$t_mem}");
foreach ($mems as $m) {
    echo "Mem ID: {$m->id} | User ID: {$m->user_id} | Type: {$m->membership_type} | Status: {$m->status} | Approved At: {$m->account_approved_at} | Partner: {$m->partner_name} ({$m->partner_email}) | Policy: {$m->policy_version}\n";
}

echo "\n=== LFA PERIODS TABLE ===\n";
$t_per = LFA_Membership::table_periods();
$pers = $wpdb->get_results("SELECT * FROM {$t_per}");
foreach ($pers as $p) {
    echo "Period ID: {$p->id} | Mem ID: {$p->membership_id} | Key: {$p->period_key} | Starts: {$p->starts_at} | Ends: {$p->ends_at} | Standing: {$p->standing}\n";
}

echo "\n=== LFA DUES INVOICES TABLE ===\n";
$t_inv = LFA_Membership::table_invoices();
$invs = $wpdb->get_results("SELECT * FROM {$t_inv}");
foreach ($invs as $i) {
    echo "Inv ID: {$i->id} | Number: {$i->invoice_number} | Mem ID: {$i->membership_id} | User ID: {$i->user_id} | Amount: \${$i->amount_cents} cents | Status: {$i->status} | Paid At: {$i->paid_at}\n";
}

echo "\n=== LFA CAPTURES TABLE ===\n";
$t_cap = LFA_Payment_Service::table_captures();
$caps = $wpdb->get_results("SELECT * FROM {$t_cap}");
foreach ($caps as $c) {
    $mode = $c->mode ?? '';
    $prov = $c->gateway_provider ?? '';
    $test = $c->test_transaction ?? '';
    echo "Cap ID: {$c->id} | Inv ID: {$c->invoice_id} | Type: {$c->payable_type} | Provider: {$prov} | Mode: {$mode} | Test: {$test} | Capture ID: {$c->paypal_capture_id} | Amount: {$c->amount_cents} | Status: {$c->capture_status} | Captured At: {$c->captured_at}\n";
}

echo "\n=== LFA CONFERENCES TABLE ===\n";
$t_conf = LFA_Conference::table_conferences();
$confs = $wpdb->get_results("SELECT * FROM {$t_conf}");
foreach ($confs as $c) {
    echo "Conf ID: {$c->id} | Slug: {$c->slug} | Title: {$c->title} | Published: {$c->published_status} | Reg Status: {$c->registration_status} | Guest Allowed: {$c->guest_registration_enabled}\n";
}

echo "\n=== LFA CONFERENCES TICKET TYPES ===\n";
$t_tick = LFA_Conference::table_ticket_types();
$ticks = $wpdb->get_results("SELECT * FROM {$t_tick}");
foreach ($ticks as $t) {
    echo "Ticket ID: {$t->id} | Conf ID: {$t->conference_id} | Name: {$t->name} | Amount: \${$t->amount_cents} cents | Eligibility: {$t->eligibility} | Active: {$t->active}\n";
}

echo "\n=== LFA CONFERENCES REGISTRATIONS ===\n";
$t_reg = LFA_Conference::table_registrations();
$regs = $wpdb->get_results("SELECT * FROM {$t_reg}");
foreach ($regs as $r) {
    echo "Reg ID: {$r->id} | Num: {$r->registration_number} | Conf ID: {$r->conference_id} | Owner User: {$r->owner_user_id} | Ticket: {$r->ticket_name_snapshot} | Status: {$r->status} | Created: {$r->created_at}\n";
}

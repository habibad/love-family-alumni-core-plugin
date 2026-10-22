<?php
require_once __DIR__ . '/../../../../wp-load.php';
global $wpdb;

echo "=== PHASE 02: COUPLE MEMBERSHIP SAFE MIGRATION ===\n";

// 1. Check User 10
$user_id = 10;
$curr_type = get_user_meta($user_id, 'lfa_membership_type', true);
$p_name = get_user_meta($user_id, 'lfa_partner_name', true);
$p_email = get_user_meta($user_id, 'lfa_partner_email', true);

echo "User 10 current type: {$curr_type}, Partner: {$p_name} ({$p_email})\n";

// Log migration into LFA audit table
LFA_Membership::log_audit(1, $user_id, 'couple_to_individual_migrated', 'user_meta', $user_id, [
    'previous_type' => $curr_type,
    'new_type'      => 'individual',
    'partner_name'  => $p_name,
    'partner_email' => $p_email,
    'reason'        => 'Phase 02 policy update: single $100 annual membership package',
]);

// Update user meta
update_user_meta($user_id, 'lfa_membership_type', 'individual');
echo "Updated User 10 lfa_membership_type to 'individual'. Partner details preserved in audit log.\n";

// 2. Ensure LFA_Membership record exists for User 10
$mem = LFA_Membership::get_or_create_membership($user_id);
if ($mem) {
    $t_mem = LFA_Membership::table_memberships();
    $wpdb->update($t_mem, [
        'membership_type' => 'individual',
        'updated_at'      => current_time('mysql', true),
    ], ['id' => $mem->id]);
    echo "Synchronized membership table record for User 10 (ID: {$mem->id}) to individual.\n";
}

// 3. Update Policy Option
$policy = LFA_Membership::get_policy();
$policy['individual_base_cents'] = 10000;
unset($policy['couple_base_cents']);
$policy['couple_base_cents'] = null;
$policy['policy_version'] = 'v2.0-single-membership';
update_option(LFA_Membership::OPTION_POLICY, $policy, false);
echo "Updated dues policy: individual_base_cents = 10000, couple_base_cents = null, version = v2.0-single-membership\n";

// 4. Inactive Couples Ticket in Conference
$t_tick = LFA_Conference::table_ticket_types();
$wpdb->update($t_tick, [
    'active'      => 0,
    'description' => 'DEPRECATED per single-membership client policy.',
    'updated_at'  => current_time('mysql', true),
], ['id' => 2]);
echo "Conference Ticket ID 2 marked permanently inactive/deprecated.\n";

echo "=== MIGRATION COMPLETE ===\n";

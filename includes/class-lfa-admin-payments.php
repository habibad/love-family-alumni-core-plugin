<?php
/**
 * Love Family Alumni — Admin Payment Gateways & Transaction Management Controller
 *
 * Implements WordPress Admin screens for:
 * 1. Dual Gateway Configuration (PayPal Sandbox/Live + Stripe Test Mode)
 * 2. Independent Provider Enable/Disable & Global Kill Switch
 * 3. Connection Diagnostics & Webhook Health Status
 * 4. Multi-Gateway Payment Ledger with Environment & Payable Filtering
 * 5. Safe Payment Reconciliation
 * 6. Member Permission Diagnostics (Access Standing & Role Audit)
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Admin_Payments {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_admin_menus'], 25);
        add_action('admin_post_lfa_save_gateway_settings', [__CLASS__, 'handle_save_gateway_settings']);
        add_action('admin_post_lfa_test_gateway_connection', [__CLASS__, 'handle_test_gateway_connection']);
        add_action('admin_post_lfa_save_paypal_settings', [__CLASS__, 'handle_save_gateway_settings']); // Legacy alias
        add_action('admin_post_lfa_test_paypal_connection', [__CLASS__, 'handle_test_gateway_connection']); // Legacy alias
        add_action('admin_post_lfa_reconcile_attempt', [__CLASS__, 'handle_reconcile_attempt']);
    }

    public static function register_admin_menus(): void {
        $parent = 'users.php'; // Alongside Alumni management

        add_submenu_page(
            $parent,
            'Payment Gateways',
            'Payment Gateways',
            LFA_Gateway_Settings::CAPABILITY,
            'lfa-payment-gateways',
            [__CLASS__, 'render_gateways_page']
        );

        // Keep legacy alias for bookmarks
        add_submenu_page(
            $parent,
            'PayPal Settings',
            'PayPal Settings (Legacy)',
            LFA_Gateway_Settings::CAPABILITY,
            'lfa-paypal-settings',
            [__CLASS__, 'render_gateways_page']
        );

        add_submenu_page(
            $parent,
            'Payment Transactions',
            'Payment Ledger',
            'lfa_view_payment_transactions',
            'lfa-payment-transactions',
            [__CLASS__, 'render_transactions_page']
        );

        add_submenu_page(
            $parent,
            'Payment Reconciliation',
            'Reconciliation',
            'lfa_reconcile_payments',
            'lfa-payment-reconciliation',
            [__CLASS__, 'render_reconciliation_page']
        );

        add_submenu_page(
            $parent,
            'Member Permission Diagnostics',
            'Member Diagnostics',
            'edit_users',
            'lfa-member-diagnostics',
            [__CLASS__, 'render_diagnostics_page']
        );
    }

    /**
     * Handle Save Dual Gateway Settings
     */
    public static function handle_save_gateway_settings(): void {
        if (!current_user_can(LFA_Gateway_Settings::CAPABILITY) && !current_user_can('manage_options')) {
            wp_die('Unauthorized: Insufficient permissions to manage gateway settings.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer('lfa_save_gateways_action', 'lfa_gateways_nonce');

        $input = [
            // Global
            'global_payments_kill_switch' => !empty($_POST['global_payments_kill_switch']) ? 1 : 0,
            'default_testing_gateway'     => sanitize_key($_POST['default_testing_gateway'] ?? 'stripe'),

            // PayPal
            'paypal_enabled'              => !empty($_POST['paypal_enabled']) ? 1 : 0,
            'paypal_mode'                 => sanitize_text_field($_POST['paypal_mode'] ?? 'sandbox'),
            'paypal_live_confirmed'       => !empty($_POST['paypal_live_confirmed']) ? 1 : 0,
            'paypal_sandbox_client_id'    => sanitize_text_field($_POST['paypal_sandbox_client_id'] ?? ''),
            'paypal_sandbox_secret'       => sanitize_text_field($_POST['paypal_sandbox_secret'] ?? ''),
            'paypal_sandbox_clear_secret' => !empty($_POST['paypal_sandbox_clear_secret']) ? '1' : '0',
            'paypal_sandbox_webhook_id'   => sanitize_text_field($_POST['paypal_sandbox_webhook_id'] ?? ''),
            'paypal_sandbox_merchant_id'  => sanitize_text_field($_POST['paypal_sandbox_merchant_id'] ?? ''),
            'paypal_live_client_id'       => sanitize_text_field($_POST['paypal_live_client_id'] ?? ''),
            'paypal_live_secret'          => sanitize_text_field($_POST['paypal_live_secret'] ?? ''),
            'paypal_live_clear_secret'    => !empty($_POST['paypal_live_clear_secret']) ? '1' : '0',
            'paypal_live_webhook_id'      => sanitize_text_field($_POST['paypal_live_webhook_id'] ?? ''),
            'paypal_live_merchant_id'     => sanitize_text_field($_POST['paypal_live_merchant_id'] ?? ''),

            // Stripe
            'stripe_enabled'              => !empty($_POST['stripe_enabled']) ? 1 : 0,
            'stripe_test_publishable_key' => sanitize_text_field($_POST['stripe_test_publishable_key'] ?? ''),
            'stripe_test_secret_key'      => sanitize_text_field($_POST['stripe_test_secret_key'] ?? ''),
            'stripe_test_clear_secret'    => !empty($_POST['stripe_test_clear_secret']) ? '1' : '0',
            'stripe_test_webhook_secret'  => sanitize_text_field($_POST['stripe_test_webhook_secret'] ?? ''),
            'stripe_test_clear_webhook'   => !empty($_POST['stripe_test_clear_webhook']) ? '1' : '0',
        ];

        $res = LFA_Gateway_Settings::save_admin_settings($input, get_current_user_id());
        $notice = $res['success'] ? 'saved' : 'error';
        $msg = $res['error'] ?? '';

        wp_safe_redirect(add_query_arg([
            'page'       => 'lfa-payment-gateways',
            'lfa_notice' => $notice,
            'lfa_msg'    => urlencode($msg),
        ], admin_url('users.php')));
        exit;
    }

    /**
     * Handle Connection Test for PayPal or Stripe
     */
    public static function handle_test_gateway_connection(): void {
        if (!current_user_can(LFA_Gateway_Settings::CAPABILITY) && !current_user_can('manage_options')) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer('lfa_test_gateway_action', 'lfa_test_nonce');
        $gateway_id = sanitize_key($_POST['gateway_id'] ?? 'stripe');

        if ($gateway_id === 'stripe') {
            $stripe_gw = new LFA_Stripe_Gateway();
            $res = $stripe_gw->test_connection();
        } else {
            $mode = sanitize_text_field($_POST['test_mode'] ?? 'sandbox');
            $res = LFA_PayPal_Client::test_connection($mode);
        }

        $notice = $res['success'] ? 'test_success' : 'test_failed';
        $msg = $res['message'] ?? ($res['error'] ?? '');

        wp_safe_redirect(add_query_arg([
            'page'       => 'lfa-payment-gateways',
            'lfa_notice' => $notice,
            'lfa_msg'    => urlencode($msg),
            'tested_gw'  => $gateway_id,
        ], admin_url('users.php')));
        exit;
    }

    /**
     * Handle Safe Manual Reconciliation
     */
    public static function handle_reconcile_attempt(): void {
        if (!current_user_can('lfa_reconcile_payments') && !current_user_can('manage_options')) {
            wp_die('Unauthorized.', 'Forbidden', ['response' => 403]);
        }

        check_admin_referer('lfa_reconcile_attempt_action', 'lfa_recon_nonce');
        $attempt_id = (int)($_POST['attempt_id'] ?? 0);

        global $wpdb;
        $t_att = LFA_Payment_Service::table_attempts();
        $attempt = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_att} WHERE id = %d", $attempt_id));

        if (!$attempt) {
            wp_safe_redirect(add_query_arg(['page' => 'lfa-payment-reconciliation', 'lfa_notice' => 'not_found'], admin_url('users.php')));
            exit;
        }

        if ($attempt->provider === 'stripe' && !empty($attempt->order_id)) {
            // Reconcile Stripe Checkout Session
            LFA_Payment_Reconciliation::settle_stripe_session($attempt->order_id, (int)$attempt->user_id, $attempt->payable_type);
        } elseif (!empty($attempt->order_id)) {
            // Re-query PayPal order
            $order_res = LFA_PayPal_Client::get_order($attempt->order_id, $attempt->mode);
            if ($order_res['success'] && !empty($order_res['order']['status'])) {
                $status = $order_res['order']['status'];
                if ($status === 'COMPLETED' || $status === 'APPROVED') {
                    LFA_Payment_Service::capture_and_settle($attempt->order_id, (int)$attempt->user_id);
                }
            }
        }

        wp_safe_redirect(add_query_arg(['page' => 'lfa-payment-reconciliation', 'lfa_notice' => 'reconciled'], admin_url('users.php')));
        exit;
    }

    /**
     * Render Admin Dual Payment Gateways Screen
     */
    public static function render_gateways_page(): void {
        if (!current_user_can(LFA_Gateway_Settings::CAPABILITY) && !current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $s = LFA_Gateway_Settings::get_settings();
        $notice = $_GET['lfa_notice'] ?? '';
        $msg = !empty($_GET['lfa_msg']) ? urldecode($_GET['lfa_msg']) : '';
        $tested_gw = $_GET['tested_gw'] ?? '';

        $paypal_webhook_url = LFA_Gateway_Settings::get_paypal_webhook_url();
        $stripe_webhook_url = LFA_Gateway_Settings::get_stripe_webhook_url();

        $pp_sb_secret_status = !empty($s['paypal_sandbox_secret_enc']) ? '<span style="color:#15803d;font-weight:600;">[Configured &amp; Encrypted at Rest]</span>' : '<span style="color:#b91c1c;">[Not Configured]</span>';
        $pp_live_secret_status = !empty($s['paypal_live_secret_enc']) ? '<span style="color:#15803d;font-weight:600;">[Configured &amp; Encrypted at Rest]</span>' : '<span style="color:#b91c1c;">[Not Configured]</span>';

        $str_sk_status = !empty($s['stripe_test_secret_key_enc']) ? '<span style="color:#15803d;font-weight:600;">[Configured &amp; Encrypted at Rest]</span>' : '<span style="color:#b91c1c;">[Not Configured]</span>';
        $str_wh_status = !empty($s['stripe_test_webhook_secret_enc']) ? '<span style="color:#15803d;font-weight:600;">[Configured &amp; Encrypted at Rest]</span>' : '<span style="color:#b91c1c;">[Not Configured]</span>';

        $kill_switch = (int) $s['global_payments_kill_switch'] === 1;
        ?>
        <div class="wrap" style="max-width: 1080px;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:20px;border-bottom:1px solid #cbd5e1;padding-bottom:16px;">
                <div>
                    <h1 style="margin:0 0 6px 0;font-size:24px;font-weight:800;color:#0f172a;">
                        Love Family Alumni &mdash; Payment Gateways
                    </h1>
                    <p style="margin:0;color:#64748b;font-size:14px;">
                        Independent gateway configuration for PayPal and Stripe Test Mode with secure AES-256-GCM encryption.
                    </p>
                </div>
                <div style="display:flex;gap:10px;align-items:center;">
                    <?php if ($kill_switch): ?>
                        <span style="background:#dc2626;color:#fff;padding:6px 14px;border-radius:9999px;font-weight:700;font-size:12px;text-transform:uppercase;">
                            🚨 KILL SWITCH ENGAGED (PAYMENTS BLOCKED)
                        </span>
                    <?php else: ?>
                        <span style="background:#10b981;color:#fff;padding:6px 14px;border-radius:9999px;font-weight:700;font-size:12px;text-transform:uppercase;">
                            ✓ PAYMENTS OPERATIONAL
                        </span>
                    <?php endif; ?>
                    <span style="background:#64748b;color:#fff;padding:6px 14px;border-radius:9999px;font-weight:700;font-size:12px;text-transform:uppercase;">
                        LIVE MODE: OFF
                    </span>
                </div>
            </div>

            <?php if ($notice === 'saved'): ?>
                <div class="notice notice-success is-dismissible"><p><strong>Success:</strong> Payment gateway settings have been encrypted and saved successfully.</p></div>
            <?php elseif ($notice === 'error'): ?>
                <div class="notice notice-error is-dismissible"><p><strong>Configuration Error:</strong> <?php echo esc_html($msg); ?></p></div>
            <?php elseif ($notice === 'test_success'): ?>
                <div class="notice notice-success is-dismissible"><p><strong>Connection Test Succeeded [<?php echo esc_html(strtoupper($tested_gw)); ?>]:</strong> <?php echo esc_html($msg); ?></p></div>
            <?php elseif ($notice === 'test_failed'): ?>
                <div class="notice notice-error is-dismissible"><p><strong>Connection Test Failed [<?php echo esc_html(strtoupper($tested_gw)); ?>]:</strong> <?php echo esc_html($msg); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="lfa_save_gateway_settings">
                <?php wp_nonce_field('lfa_save_gateways_action', 'lfa_gateways_nonce'); ?>

                <!-- 1. Global Gateway Controls Panel -->
                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:24px;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                    <h2 style="margin:0 0 16px 0;font-size:16px;color:#0f172a;display:flex;align-items:center;gap:8px;">
                        <span>⚙️ Global Payment Controls</span>
                    </h2>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                        <div style="background:#f8fafc;padding:16px;border-radius:6px;border:1px solid #e2e8f0;">
                            <label style="display:block;font-weight:700;color:#0f172a;margin-bottom:6px;">
                                <input type="checkbox" name="global_payments_kill_switch" value="1" <?php checked($s['global_payments_kill_switch'], 1); ?>>
                                Emergency Global Kill Switch
                            </label>
                            <p style="margin:0;font-size:13px;color:#64748b;">
                                When checked, immediately blocks all new checkouts across PayPal and Stripe. Already initialized transactions will safely reconcile.
                            </p>
                        </div>
                        <div style="background:#f8fafc;padding:16px;border-radius:6px;border:1px solid #e2e8f0;">
                            <label style="display:block;font-weight:700;color:#0f172a;margin-bottom:6px;">
                                Default Testing Gateway
                            </label>
                            <select name="default_testing_gateway" style="width:100%;max-width:280px;">
                                <option value="stripe" <?php selected($s['default_testing_gateway'], 'stripe'); ?>>Stripe (Test Mode — Recommended)</option>
                                <option value="paypal" <?php selected($s['default_testing_gateway'], 'paypal'); ?>>PayPal (Sandbox Mode)</option>
                            </select>
                            <p style="margin:6px 0 0 0;font-size:13px;color:#64748b;">
                                Preferred gateway presented to members during development testing when both gateways are enabled.
                            </p>
                        </div>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">

                    <!-- 2. Stripe Test Gateway Configuration Panel -->
                    <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:8px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,0.05);border-top:4px solid #6366f1;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                            <h2 style="margin:0;font-size:18px;color:#1e1b4b;display:flex;align-items:center;gap:8px;">
                                <span>💳 Stripe Test Gateway</span>
                            </h2>
                            <label style="display:flex;align-items:center;gap:6px;font-weight:700;color:#0f172a;cursor:pointer;">
                                <input type="checkbox" name="stripe_enabled" value="1" <?php checked($s['stripe_enabled'], 1); ?>>
                                Enabled
                            </label>
                        </div>

                        <div style="background:#eef2ff;border-left:4px solid #6366f1;padding:12px;border-radius:4px;margin-bottom:20px;font-size:13px;color:#3730a3;">
                            <strong>Environment: Test Mode Only.</strong> Live mode is strictly locked off for development safety. Never enter <code>sk_live_</code> keys.
                        </div>

                        <table class="form-table" style="margin:0;">
                            <tr>
                                <th scope="row" style="width:140px;padding:10px 0;"><label>Publishable Key</label></th>
                                <td style="padding:10px 0;">
                                    <input type="text" name="stripe_test_publishable_key" value="<?php echo esc_attr($s['stripe_test_publishable_key']); ?>" class="large-text" placeholder="pk_test_..." autocomplete="off">
                                    <p class="description">Public client-side key for Stripe Checkout (must start with <code>pk_test_</code>).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row" style="padding:10px 0;"><label>Secret Key</label></th>
                                <td style="padding:10px 0;">
                                    <div style="margin-bottom:6px;"><?php echo $str_sk_status; ?></div>
                                    <input type="password" name="stripe_test_secret_key" value="" class="large-text" placeholder="Enter new sk_test_... to update" autocomplete="new-password">
                                    <label style="font-size:12px;color:#64748b;display:inline-block;margin-top:4px;">
                                        <input type="checkbox" name="stripe_test_clear_secret" value="1"> Clear stored Secret Key
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row" style="padding:10px 0;"><label>Webhook Secret</label></th>
                                <td style="padding:10px 0;">
                                    <div style="margin-bottom:6px;"><?php echo $str_wh_status; ?></div>
                                    <input type="password" name="stripe_test_webhook_secret" value="" class="large-text" placeholder="Enter whsec_... to update" autocomplete="new-password">
                                    <label style="font-size:12px;color:#64748b;display:inline-block;margin-top:4px;">
                                        <input type="checkbox" name="stripe_test_clear_webhook" value="1"> Clear stored Webhook Secret
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row" style="padding:10px 0;"><label>Webhook URL</label></th>
                                <td style="padding:10px 0;">
                                    <code style="word-break:break-all;display:block;background:#f1f5f9;padding:8px;border-radius:4px;font-size:12px;"><?php echo esc_html($stripe_webhook_url); ?></code>
                                    <p class="description">Provide this URL to Stripe CLI or Stripe Dashboard Webhooks (Events: <code>checkout.session.*</code>, <code>payment_intent.*</code>, <code>charge.refunded</code>).</p>
                                </td>
                            </tr>
                        </table>

                        <?php if (!empty($s['stripe_test_last_test'])): ?>
                            <div style="margin-top:16px;padding:12px;background:#f8fafc;border-radius:6px;border:1px solid #e2e8f0;font-size:12px;">
                                <strong>Last Connection Test:</strong> <?php echo esc_html($s['stripe_test_last_test']['tested_at']); ?> &middot;
                                <span style="color:<?php echo !empty($s['stripe_test_last_test']['success']) ? '#15803d' : '#b91c1c'; ?>;font-weight:700;">
                                    <?php echo !empty($s['stripe_test_last_test']['success']) ? 'SUCCESS' : 'FAILED'; ?>
                                </span>
                                <div style="color:#64748b;margin-top:2px;"><?php echo esc_html($s['stripe_test_last_test']['message']); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- 3. PayPal Gateway Configuration Panel -->
                    <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:8px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,0.05);border-top:4px solid #0284c7;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                            <h2 style="margin:0;font-size:18px;color:#0c4a6e;display:flex;align-items:center;gap:8px;">
                                <span>🅿️ PayPal Gateway</span>
                            </h2>
                            <label style="display:flex;align-items:center;gap:6px;font-weight:700;color:#0f172a;cursor:pointer;">
                                <input type="checkbox" name="paypal_enabled" value="1" <?php checked($s['paypal_enabled'], 1); ?>>
                                Enabled
                            </label>
                        </div>

                        <div style="margin-bottom:16px;">
                            <label style="font-weight:700;color:#0f172a;margin-right:12px;">Gateway Mode:</label>
                            <label style="margin-right:16px;">
                                <input type="radio" name="paypal_mode" value="sandbox" <?php checked($s['paypal_mode'], 'sandbox'); ?>>
                                Sandbox
                            </label>
                            <label>
                                <input type="radio" name="paypal_mode" value="live" <?php checked($s['paypal_mode'], 'live'); ?>>
                                Live (Production)
                            </label>
                        </div>

                        <table class="form-table" style="margin:0;">
                            <tr>
                                <th scope="row" style="width:140px;padding:10px 0;"><label>Sandbox Client ID</label></th>
                                <td style="padding:10px 0;">
                                    <input type="text" name="paypal_sandbox_client_id" value="<?php echo esc_attr($s['paypal_sandbox_client_id']); ?>" class="large-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row" style="padding:10px 0;"><label>Sandbox Secret</label></th>
                                <td style="padding:10px 0;">
                                    <div style="margin-bottom:6px;"><?php echo $pp_sb_secret_status; ?></div>
                                    <input type="password" name="paypal_sandbox_secret" value="" class="large-text" placeholder="Enter new secret to update" autocomplete="new-password">
                                    <label style="font-size:12px;color:#64748b;display:inline-block;margin-top:4px;">
                                        <input type="checkbox" name="paypal_sandbox_clear_secret" value="1"> Clear stored Sandbox Secret
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row" style="padding:10px 0;"><label>Sandbox Webhook ID</label></th>
                                <td style="padding:10px 0;">
                                    <input type="text" name="paypal_sandbox_webhook_id" value="<?php echo esc_attr($s['paypal_sandbox_webhook_id']); ?>" class="large-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row" style="padding:10px 0;"><label>Webhook URL</label></th>
                                <td style="padding:10px 0;">
                                    <code style="word-break:break-all;display:block;background:#f1f5f9;padding:8px;border-radius:4px;font-size:12px;"><?php echo esc_html($paypal_webhook_url); ?></code>
                                </td>
                            </tr>
                        </table>

                        <?php if (!empty($s['paypal_sandbox_last_test'])): ?>
                            <div style="margin-top:16px;padding:12px;background:#f8fafc;border-radius:6px;border:1px solid #e2e8f0;font-size:12px;">
                                <strong>Last Connection Test:</strong> <?php echo esc_html($s['paypal_sandbox_last_test']['tested_at']); ?> &middot;
                                <span style="color:<?php echo !empty($s['paypal_sandbox_last_test']['success']) ? '#15803d' : '#b91c1c'; ?>;font-weight:700;">
                                    <?php echo !empty($s['paypal_sandbox_last_test']['success']) ? 'SUCCESS' : 'FAILED'; ?>
                                </span>
                                <div style="color:#64748b;margin-top:2px;"><?php echo esc_html($s['paypal_sandbox_last_test']['message']); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="margin-top:24px;display:flex;gap:12px;align-items:center;">
                    <button type="submit" class="button button-primary button-hero" style="font-weight:700;">
                        Save All Gateway Settings
                    </button>
                </div>
            </form>

            <!-- Diagnostics Action Buttons -->
            <div style="margin-top:32px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:20px;">
                <h3 style="margin:0 0 12px 0;font-size:15px;color:#0f172a;">Gateway Connection Diagnostics</h3>
                <div style="display:flex;gap:16px;flex-wrap:wrap;">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="lfa_test_gateway_connection">
                        <input type="hidden" name="gateway_id" value="stripe">
                        <?php wp_nonce_field('lfa_test_gateway_action', 'lfa_test_nonce'); ?>
                        <button type="submit" class="button button-secondary">
                            ⚡ Test Stripe Test Mode Connection
                        </button>
                    </form>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="lfa_test_gateway_connection">
                        <input type="hidden" name="gateway_id" value="paypal">
                        <input type="hidden" name="test_mode" value="sandbox">
                        <?php wp_nonce_field('lfa_test_gateway_action', 'lfa_test_nonce'); ?>
                        <button type="submit" class="button button-secondary">
                            ⚡ Test PayPal Sandbox Connection
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Payment Transactions Ledger
     */
    public static function render_transactions_page(): void {
        if (!current_user_can('lfa_view_payment_transactions') && !current_user_can(LFA_Gateway_Settings::CAPABILITY)) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $t_cap = LFA_Payment_Service::table_captures();
        $t_inv = LFA_Membership::table_invoices();
        $t_users = $wpdb->users;

        $filter_provider = sanitize_key($_GET['f_provider'] ?? '');
        $filter_type = sanitize_key($_GET['f_type'] ?? '');

        $where = "WHERE 1=1";
        if (!empty($filter_provider)) {
            $where .= $wpdb->prepare(" AND (c.gateway_provider = %s OR (c.gateway_provider IS NULL AND c.mode = 'sandbox' AND %s = 'paypal'))", $filter_provider, $filter_provider);
        }
        if (!empty($filter_type)) {
            $where .= $wpdb->prepare(" AND c.payable_type = %s", $filter_type);
        }

        $captures = $wpdb->get_results("
            SELECT c.*, i.invoice_number, i.user_id, u.display_name, u.user_email
            FROM {$t_cap} c
            LEFT JOIN {$t_inv} i ON c.invoice_id = i.id
            LEFT JOIN {$t_users} u ON i.user_id = u.ID
            {$where}
            ORDER BY c.id DESC
            LIMIT 100
        ");
        ?>
        <div class="wrap">
            <h1 style="margin-bottom:6px;">Alumni Payment Transactions Ledger</h1>
            <p style="color:#64748b;margin:0 0 16px 0;">Authoritative record of captured payments, settled invoices, and reconciliation states.</p>

            <!-- Filters -->
            <form method="get" style="display:flex;gap:12px;align-items:center;margin-bottom:16px;background:#f8fafc;padding:12px 16px;border-radius:6px;border:1px solid #e2e8f0;">
                <input type="hidden" name="page" value="lfa-payment-transactions">
                <label style="font-weight:600;font-size:13px;">Provider:
                    <select name="f_provider">
                        <option value="">All Providers</option>
                        <option value="stripe" <?php selected($filter_provider, 'stripe'); ?>>Stripe</option>
                        <option value="paypal" <?php selected($filter_provider, 'paypal'); ?>>PayPal</option>
                    </select>
                </label>
                <label style="font-weight:600;font-size:13px;">Payable Type:
                    <select name="f_type">
                        <option value="">All Categories</option>
                        <option value="membership_due" <?php selected($filter_type, 'membership_due'); ?>>Membership Dues</option>
                        <option value="conference" <?php selected($filter_type, 'conference'); ?>>Conference Pass</option>
                        <option value="donation" <?php selected($filter_type, 'donation'); ?>>Donation</option>
                    </select>
                </label>
                <button type="submit" class="button button-secondary">Filter Ledger</button>
                <?php if (!empty($filter_provider) || !empty($filter_type)): ?>
                    <a href="<?php echo esc_url(admin_url('users.php?page=lfa-payment-transactions')); ?>" class="button button-link">Reset</a>
                <?php endif; ?>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:130px;">Date</th>
                        <th style="width:110px;">Provider</th>
                        <th style="width:120px;">Category</th>
                        <th style="width:140px;">Invoice / Ref #</th>
                        <th>Member / Donor</th>
                        <th style="width:160px;">Payment ID</th>
                        <th style="width:90px;">Amount</th>
                        <th style="width:110px;">Status</th>
                        <th style="width:100px;">Receipt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($captures)): ?>
                        <tr><td colspan="9" style="text-align:center;padding:24px;color:#64748b;">No payment transactions match the active filter criteria.</td></tr>
                    <?php else: ?>
                        <?php foreach ($captures as $c):
                            $gw = $c->gateway_provider ?? ($c->mode === 'test' ? 'stripe' : 'paypal');
                            $is_stripe = ($gw === 'stripe');
                            $disp_id = !empty($c->provider_payment_id) ? $c->provider_payment_id : $c->paypal_capture_id;
                        ?>
                            <tr>
                                <td><?php echo esc_html($c->captured_at); ?></td>
                                <td>
                                    <?php if ($is_stripe): ?>
                                        <span style="font-size:11px;padding:2px 8px;border-radius:4px;font-weight:700;background:#e0e7ff;color:#3730a3;">STRIPE TEST</span>
                                    <?php else: ?>
                                        <span style="font-size:11px;padding:2px 8px;border-radius:4px;font-weight:700;background:#e0f2fe;color:#0369a1;">PAYPAL <?php echo esc_html(strtoupper($c->mode)); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo esc_html(ucfirst(str_replace('_', ' ', $c->payable_type))); ?></code></td>
                                <td><strong><?php echo esc_html($c->invoice_number ?: ('#' . $c->invoice_id)); ?></strong></td>
                                <td>
                                    <?php echo esc_html($c->display_name ?: 'Alumni Member'); ?><br>
                                    <span style="font-size:11px;color:#64748b;"><?php echo esc_html($c->user_email); ?></span>
                                </td>
                                <td><code><?php echo esc_html(substr($disp_id, 0, 16) . '...'); ?></code></td>
                                <td><strong>$<?php echo esc_html(number_format($c->amount_cents / 100, 2)); ?></strong> <?php echo esc_html($c->currency); ?></td>
                                <td>
                                    <span style="font-size:11px;padding:2px 8px;border-radius:9999px;font-weight:700;background:#dcfce7;color:#166534;">
                                        <?php echo esc_html($c->capture_status); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url(rest_url('lfa/v1/payments/receipt/' . $c->invoice_id)); ?>" target="_blank" class="button button-small">View Receipt ↗</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render Payment Reconciliation Screen
     */
    public static function render_reconciliation_page(): void {
        if (!current_user_can('lfa_reconcile_payments') && !current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;
        $t_att = LFA_Payment_Service::table_attempts();
        $t_inv = LFA_Membership::table_invoices();
        $t_users = $wpdb->users;

        $unresolved = $wpdb->get_results("
            SELECT a.*, i.invoice_number, u.display_name
            FROM {$t_att} a
            LEFT JOIN {$t_inv} i ON a.invoice_id = i.id
            LEFT JOIN {$t_users} u ON a.user_id = u.ID
            WHERE a.state NOT IN ('captured', 'failed')
            ORDER BY a.id DESC
            LIMIT 30
        ");
        ?>
        <div class="wrap">
            <h1>Payment Reconciliation &amp; Status Diagnostics</h1>
            <p>Diagnose unresolved attempts across PayPal and Stripe and query authoritative provider APIs for settlement confirmation.</p>

            <table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
                <thead>
                    <tr>
                        <th style="width:120px;">Attempt Date</th>
                        <th style="width:100px;">Provider</th>
                        <th style="width:130px;">Invoice #</th>
                        <th>Member</th>
                        <th style="width:160px;">Session / Order ID</th>
                        <th style="width:130px;">Attempt State</th>
                        <th style="width:140px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($unresolved)): ?>
                        <tr><td colspan="7" style="text-align:center;padding:24px;color:#166534;background:#f0fdf4;">All payment attempts are currently synchronized. Zero unresolved transactions pending.</td></tr>
                    <?php else: ?>
                        <?php foreach ($unresolved as $att):
                            $is_stripe = ($att->provider === 'stripe');
                            $remote_id = $att->order_id ?: ($att->checkout_session_id ?? '');
                        ?>
                            <tr>
                                <td><?php echo esc_html($att->created_at); ?></td>
                                <td>
                                    <span style="font-size:11px;font-weight:700;padding:2px 6px;border-radius:4px;background:<?php echo $is_stripe ? '#e0e7ff;color:#3730a3' : '#e0f2fe;color:#0369a1'; ?>;">
                                        <?php echo esc_html(strtoupper($att->provider)); ?>
                                    </span>
                                </td>
                                <td><strong><?php echo esc_html($att->invoice_number ?: ('#' . $att->invoice_id)); ?></strong></td>
                                <td><?php echo esc_html($att->display_name ?: 'Alumni Member'); ?></td>
                                <td><code><?php echo esc_html(substr($remote_id ?: 'None', 0, 16) . '...'); ?></code></td>
                                <td>
                                    <span style="font-size:11px;padding:2px 8px;border-radius:9999px;font-weight:700;background:#fef3c7;color:#92400e;">
                                        <?php echo esc_html($att->state); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($remote_id)): ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                            <input type="hidden" name="action" value="lfa_reconcile_attempt">
                                            <input type="hidden" name="attempt_id" value="<?php echo esc_attr($att->id); ?>">
                                            <?php wp_nonce_field('lfa_reconcile_attempt_action', 'lfa_recon_nonce'); ?>
                                            <button type="submit" class="button button-small button-secondary">
                                                Re-query <?php echo esc_html(ucfirst($att->provider)); ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;font-size:12px;">No remote ID</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render Member Permission Diagnostics Page
     */
    public static function render_diagnostics_page(): void {
        if (!current_user_can('edit_users') && !current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $selected_user_id = (int)($_GET['user_id'] ?? 0);
        $diag = null;
        if ($selected_user_id > 0) {
            $diag = LFA_Payment_Entitlements::diagnose_member($selected_user_id);
        }

        $all_users = get_users(['number' => 200, 'orderby' => 'display_name']);
        ?>
        <div class="wrap" style="max-width:1080px;">
            <h1 style="margin-bottom:6px;">Member Access &amp; Permission Diagnostics</h1>
            <p style="color:#64748b;margin:0 0 20px 0;">
                Diagnose why a member can or cannot access specific portal resources. Explains independent account approval, dues payment standing, test vs live payment effects, and confirms that payment NEVER grants WordPress administrative privileges.
            </p>

            <div style="background:#ffffff;border:1px solid #e2e8f0;padding:20px;border-radius:8px;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <form method="get" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="page" value="lfa-member-diagnostics">
                    <label style="font-weight:700;color:#0f172a;">Select Alumni Member to Diagnose:</label>
                    <select name="user_id" style="min-width:320px;">
                        <option value="">-- Choose an Account --</option>
                        <?php foreach ($all_users as $u): ?>
                            <option value="<?php echo esc_attr($u->ID); ?>" <?php selected($selected_user_id, $u->ID); ?>>
                                <?php echo esc_html($u->display_name ?: $u->user_login); ?> (<?php echo esc_html($u->user_email); ?>) [ID: <?php echo esc_attr($u->ID); ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button button-primary">Run Diagnostics</button>
                </form>
            </div>

            <?php if ($diag && !empty($diag['found'])): ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">

                    <!-- Account & Verification Standing -->
                    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                        <h3 style="margin:0 0 16px 0;font-size:15px;color:#0f172a;border-bottom:1px solid #f1f5f9;padding-bottom:10px;">
                            1. Identity &amp; Approval Governance
                        </h3>
                        <table class="widefat" style="border:none;">
                            <tr>
                                <td style="width:180px;font-weight:600;">Member Account:</td>
                                <td><strong><?php echo esc_html($diag['display_name']); ?></strong> (ID #<?php echo esc_html($diag['user_id']); ?>)</td>
                            </tr>
                            <tr>
                                <td style="font-weight:600;">Email Address:</td>
                                <td><?php echo esc_html($diag['email']); ?></td>
                            </tr>
                            <tr>
                                <td style="font-weight:600;">Email Verified:</td>
                                <td>
                                    <?php if ($diag['email_verified']): ?>
                                        <span style="color:#15803d;font-weight:700;">✓ Verified</span>
                                    <?php else: ?>
                                        <span style="color:#dc2626;font-weight:700;">✗ Unverified</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="font-weight:600;">Administrator Approved:</td>
                                <td>
                                    <?php if ($diag['account_approved']): ?>
                                        <span style="color:#15803d;font-weight:700;">✓ Approved Alumnus</span>
                                    <?php else: ?>
                                        <span style="color:#b45309;font-weight:700;">⏳ Pending Review (<?php echo esc_html($diag['account_status']); ?>)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="font-weight:600;">Alumni Directory Access:</td>
                                <td>
                                    <?php echo $diag['can_access_directory'] ? '<span style="color:#15803d;font-weight:600;">Allowed (Approved Member)</span>' : '<span style="color:#dc2626;font-weight:600;">Blocked (Approval Required)</span>'; ?>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- Payment Standing & Entitlement Isolation -->
                    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                        <h3 style="margin:0 0 16px 0;font-size:15px;color:#0f172a;border-bottom:1px solid #f1f5f9;padding-bottom:10px;">
                            2. Financial Dues &amp; Staging Isolation
                        </h3>
                        <table class="widefat" style="border:none;">
                            <tr>
                                <td style="width:180px;font-weight:600;">Dues Standing:</td>
                                <td>
                                    <span style="font-size:12px;font-weight:700;padding:2px 8px;border-radius:9999px;background:<?php echo $diag['is_paid'] ? '#dcfce7;color:#166534' : '#fee2e2;color:#991b1b'; ?>;">
                                        <?php echo esc_html(strtoupper($diag['dues_standing'])); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td style="font-weight:600;">Test Payment Status:</td>
                                <td>
                                    <?php if ($diag['is_test_paid']): ?>
                                        <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:4px;font-weight:700;font-size:12px;">
                                            STRIPE TEST PAID (STAGING CURRENT)
                                        </span>
                                    <?php elseif ($diag['is_live_paid']): ?>
                                        <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:4px;font-weight:700;font-size:12px;">
                                            PRODUCTION VERIFIED PAID
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#64748b;font-weight:600;">No Paid Transaction</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td style="font-weight:600;">Active Invoice #:</td>
                                <td><code><?php echo esc_html($diag['invoice_number'] ?: 'None issued'); ?></code></td>
                            </tr>
                            <tr>
                                <td style="font-weight:600;">Gateway Used:</td>
                                <td><?php echo esc_html(strtoupper($diag['gateway_provider'] ?: 'None')); ?></td>
                            </tr>
                            <tr>
                                <td style="font-weight:600;">Privileged Roles Audit:</td>
                                <td>
                                    <?php if ($diag['has_staff_role']): ?>
                                        <span style="color:#0369a1;font-weight:700;"><?php echo esc_html(implode(', ', $diag['staff_roles'])); ?> (Explicit Staff Appointment)</span>
                                    <?php else: ?>
                                        <span style="color:#15803d;font-weight:600;">Regular Member (No Admin Roles Granted by Payment)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

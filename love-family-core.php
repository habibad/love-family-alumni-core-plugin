<?php
/**
 * Plugin Name: Love Family Alumni Core
 * Description: Phase 05 secure PayPal-only conference registration, attendee management, and dues payment system with Orders v2 server capture, signed webhooks, immutable ledger, genuine receipts and administrative governance.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Text Domain: love-family-core
 */
if (!defined('ABSPATH')) { exit; }

if (!defined('LFA_CORE_VERSION')) {
    define('LFA_CORE_VERSION', '1.0.0');
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

require_once __DIR__ . '/includes/class-lfa-core.php';
require_once __DIR__ . '/includes/class-lfa-privacy.php';
require_once __DIR__ . '/includes/class-lfa-media.php';
require_once __DIR__ . '/includes/class-lfa-auth.php';
require_once __DIR__ . '/includes/class-lfa-profile.php';
require_once __DIR__ . '/includes/class-lfa-membership.php';
require_once __DIR__ . '/includes/class-lfa-admin.php';
require_once __DIR__ . '/includes/class-lfa-gateway-interface.php';
require_once __DIR__ . '/includes/class-lfa-gateway-settings.php';
require_once __DIR__ . '/includes/class-lfa-paypal-settings.php';
require_once __DIR__ . '/includes/class-lfa-paypal-client.php';
require_once __DIR__ . '/includes/class-lfa-paypal-gateway.php';
require_once __DIR__ . '/includes/class-lfa-stripe-gateway.php';
require_once __DIR__ . '/includes/class-lfa-payment-entitlements.php';
require_once __DIR__ . '/includes/class-lfa-payment-reconciliation.php';
require_once __DIR__ . '/includes/class-lfa-payment-orchestrator.php';
require_once __DIR__ . '/includes/class-lfa-payment-service.php';
require_once __DIR__ . '/includes/class-lfa-payment-rest.php';
require_once __DIR__ . '/includes/class-lfa-admin-payments.php';
require_once __DIR__ . '/includes/class-lfa-conference.php';
require_once __DIR__ . '/includes/class-lfa-conference-admin.php';
require_once __DIR__ . '/includes/class-lfa-conference-rest.php';
require_once __DIR__ . '/includes/class-lfa-business.php';
require_once __DIR__ . '/includes/class-lfa-business-admin.php';
require_once __DIR__ . '/includes/class-lfa-alumni-directory.php';
require_once __DIR__ . '/includes/class-lfa-directory-rest.php';
require_once __DIR__ . '/includes/class-lfa-scholarship.php';
require_once __DIR__ . '/includes/class-lfa-scholarship-admin.php';
require_once __DIR__ . '/includes/class-lfa-donation.php';
require_once __DIR__ . '/includes/class-lfa-donation-admin.php';
require_once __DIR__ . '/includes/class-lfa-donation-rest.php';
require_once __DIR__ . '/includes/class-lfa-content-audit.php';
require_once __DIR__ . '/includes/class-lfa-events.php';
require_once __DIR__ . '/includes/class-lfa-events-admin.php';
require_once __DIR__ . '/includes/class-lfa-news.php';
require_once __DIR__ . '/includes/class-lfa-news-admin.php';
require_once __DIR__ . '/includes/class-lfa-archives.php';
require_once __DIR__ . '/includes/class-lfa-archives-admin.php';
require_once __DIR__ . '/includes/class-lfa-roles.php';
require_once __DIR__ . '/includes/class-lfa-volunteers.php';
require_once __DIR__ . '/includes/class-lfa-leadership.php';
require_once __DIR__ . '/includes/class-lfa-notifications.php';
require_once __DIR__ . '/includes/class-lfa-reports.php';
require_once __DIR__ . '/includes/class-lfa-admin-portal.php';
require_once __DIR__ . '/includes/class-lfa-contact.php';

register_activation_hook(__FILE__, static function () {
    LFA_Core::activate();
    LFA_Membership::install_schema();
    LFA_Payment_Service::install_schema();
    LFA_Conference::install_schema();
    LFA_Business::ensure_schema();
    LFA_Scholarship::install_schema();
    LFA_Donation::install_schema();
    LFA_Content_Audit::install_schema();
    LFA_Events::install_schema();
    LFA_News::install_schema();
    LFA_Archives::install_schema();
    LFA_Roles::install_schema();
    LFA_Volunteers::install_schema();
    LFA_Leadership::install_schema();
    LFA_Notifications::install_schema();
    LFA_Reports::install_schema();
    LFA_Contact::install_schema();
});

add_action('plugins_loaded', static function () {
    LFA_Core::init();
    LFA_Privacy::init();
    LFA_Media::init();
    LFA_Auth::init();
    LFA_Profile::init();
    LFA_Membership::init();
    LFA_Admin::init();
    LFA_Payment_Service::init();
    LFA_Payment_REST::init();
    LFA_Admin_Payments::init();
    LFA_Conference::init();
    LFA_Conference_Admin::init();
    LFA_Conference_REST::init();
    LFA_Business::init();
    LFA_Business_Admin::init();
    LFA_Directory_REST::init();
    LFA_Scholarship::init();
    LFA_Scholarship_Admin::init();
    LFA_Donation::init();
    LFA_Donation_Admin::init();
    LFA_Donation_REST::init();
    LFA_Events::init();
    LFA_Events_Admin::init();
    LFA_News::init();
    LFA_News_Admin::init();
    LFA_Archives::init();
    LFA_Archives_Admin::init();
    LFA_Roles::init();
    LFA_Volunteers::init();
    LFA_Leadership::init();
    LFA_Notifications::init();
    LFA_Reports::init();
    LFA_Admin_Portal::init();
    LFA_Contact::init();
});

// Ensure valid From header for wp_mail without breaking WP Mail SMTP authenticated mailer
add_filter('wp_mail_from', static function ($from_email) {
    $smtp_opts = get_option('wp_mail_smtp', []);
    if (!empty($smtp_opts['mail']['from_email'])) {
        return $smtp_opts['mail']['from_email'];
    }
    if (empty($from_email) || str_contains((string)$from_email, '@localhost')) {
        $admin_email = get_option('admin_email');
        return !empty($admin_email) ? $admin_email : 'no-reply@lovefamilyalumni.org';
    }
    return $from_email;
}, 20);

add_filter('wp_mail_from_name', static function ($from_name) {
    $smtp_opts = get_option('wp_mail_smtp', []);
    if (!empty($smtp_opts['mail']['from_name'])) {
        return $smtp_opts['mail']['from_name'];
    }
    if (empty($from_name) || $from_name === 'WordPress') {
        return 'Love Family Alumni — North America';
    }
    return $from_name;
}, 20);

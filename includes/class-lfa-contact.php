<?php
/**
 * Love Family Alumni — Operational Contact Us Workflow Service
 *
 * Implements server-side processing for public contact form submissions:
 * - Strict CSRF verification via wp_nonce
 * - Honeypot anti-spam protection
 * - IP rate-limiting via WordPress transients
 * - Input sanitization & category whitelisting
 * - Secure persistence in wp_lfa_contact_messages
 * - Reliable dispatch via wp_mail (WP Mail SMTP)
 * - JSON and POST redirect support
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Contact {

    public const TABLE_NAME = 'lfa_contact_messages';

    public static function init(): void {
        self::install_schema();

        add_action('admin_post_nopriv_lfa_contact_submit', [__CLASS__, 'handle_submission']);
        add_action('admin_post_lfa_contact_submit', [__CLASS__, 'handle_submission']);

        add_action('wp_ajax_nopriv_lfa_contact_submit', [__CLASS__, 'handle_submission']);
        add_action('wp_ajax_lfa_contact_submit', [__CLASS__, 'handle_submission']);
    }

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    public static function install_schema(): void {
        global $wpdb;
        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `full_name` VARCHAR(150) NOT NULL,
            `email` VARCHAR(190) NOT NULL,
            `phone` VARCHAR(50) DEFAULT '',
            `inquiry_type` VARCHAR(100) NOT NULL,
            `subject` VARCHAR(255) NOT NULL,
            `message` TEXT NOT NULL,
            `ip_hash` VARCHAR(64) NOT NULL,
            `status` VARCHAR(30) NOT NULL DEFAULT 'new',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_status` (`status`),
            KEY `idx_created` (`created_at`)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    private static function check_rate_limit(): bool {
        $raw_ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        $key = 'lfa_contact_' . substr(hash_hmac('sha256', $raw_ip, wp_salt('auth')), 0, 28);
        $attempts = (int) get_transient($key);
        if ($attempts >= 5) {
            return false;
        }
        set_transient($key, $attempts + 1, HOUR_IN_SECONDS);
        return true;
    }

    public static function handle_submission(): void {
        $is_ajax = wp_doing_ajax() || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

        // 1. Honeypot check
        if (!empty($_POST['lfa_contact_hp'])) {
            if ($is_ajax) {
                wp_send_json_error(['message' => 'Spam detected.'], 400);
            }
            wp_die('Invalid submission.', 'Security Error', ['response' => 400]);
        }

        // 2. CSRF Token verification
        $nonce = isset($_POST['lfa_contact_nonce']) ? sanitize_text_field(wp_unslash($_POST['lfa_contact_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'lfa_contact_submit')) {
            if ($is_ajax) {
                wp_send_json_error(['message' => 'Security token expired. Please refresh the page and try again.'], 403);
            }
            wp_die('Security token expired. Refresh and try again.', 'Forbidden', ['response' => 403]);
        }

        // 3. Rate limiting
        if (!self::check_rate_limit()) {
            $msg = 'You have submitted several inquiries recently. Please wait a while before sending another.';
            if ($is_ajax) {
                wp_send_json_error(['message' => $msg], 429);
            }
            wp_die($msg, 'Too Many Requests', ['response' => 429]);
        }

        // 4. Input Extraction & Sanitization
        // Support both camelCase (JS) and snake_case aliases
        $name    = sanitize_text_field(wp_unslash($_POST['fullName'] ?? $_POST['full_name'] ?? ''));
        $email   = sanitize_email(wp_unslash($_POST['emailAddress'] ?? $_POST['email'] ?? ''));
        $phone   = sanitize_text_field(wp_unslash($_POST['phoneNumber'] ?? $_POST['phone'] ?? ''));
        $inquiry = sanitize_text_field(wp_unslash($_POST['inquiryType'] ?? $_POST['inquiry_type'] ?? ''));
        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));

        // 5. Validation
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            $err = 'Please provide your full name (between 2 and 150 characters).';
            if ($is_ajax) { wp_send_json_error(['message' => $err], 400); }
            wp_die($err, 'Validation Error', ['response' => 400]);
        }

        if (!is_email($email)) {
            $err = 'Please provide a valid email address.';
            if ($is_ajax) { wp_send_json_error(['message' => $err], 400); }
            wp_die($err, 'Validation Error', ['response' => 400]);
        }

        if (mb_strlen($subject) < 3 || mb_strlen($subject) > 250) {
            $err = 'Please provide a subject line (between 3 and 250 characters).';
            if ($is_ajax) { wp_send_json_error(['message' => $err], 400); }
            wp_die($err, 'Validation Error', ['response' => 400]);
        }

        if (mb_strlen($message) < 10 || mb_strlen($message) > 3000) {
            $err = 'Please provide a message between 10 and 3,000 characters.';
            if ($is_ajax) { wp_send_json_error(['message' => $err], 400); }
            wp_die($err, 'Validation Error', ['response' => 400]);
        }

        $valid_inquiries = [
            'General Inquiry',
            'Membership Support',
            'Alumni Directory',
            'Events & Conferences',
            'Scholarships & Giving',
            'Business Directory',
            'Partnerships & Collaboration',
            'Other',
        ];
        if (!in_array($inquiry, $valid_inquiries, true)) {
            $inquiry = 'General Inquiry';
        }

        // 6. Database Storage
        global $wpdb;
        $raw_ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        $ip_hash = hash('sha256', $raw_ip . wp_salt('auth'));

        $inserted = $wpdb->insert(
            self::table(),
            [
                'full_name'    => $name,
                'email'        => $email,
                'phone'        => $phone,
                'inquiry_type' => $inquiry,
                'subject'      => $subject,
                'message'      => $message,
                'ip_hash'      => $ip_hash,
                'status'       => 'new',
                'created_at'   => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        $record_id = $inserted ? $wpdb->insert_id : 0;

        // 7. Email Dispatch
        $recipient = get_option('lfa_contact_recipient', '');
        if (empty($recipient) || !is_email($recipient)) {
            $recipient = get_option('admin_email');
        }

        $email_subject = sprintf('[LFA Contact Us] %s: %s', $inquiry, $subject);
        $email_body = "New message received via Love Family Alumni Contact Form:\n\n"
            . "--------------------------------------------------\n"
            . "Full Name:    " . $name . "\n"
            . "Email:        " . $email . "\n"
            . "Phone:        " . ($phone ?: 'Not provided') . "\n"
            . "Inquiry Type: " . $inquiry . "\n"
            . "Subject:      " . $subject . "\n"
            . "Date/Time:    " . current_time('r') . "\n"
            . "--------------------------------------------------\n\n"
            . "Message Content:\n\n"
            . $message . "\n\n"
            . "--------------------------------------------------\n"
            . "Love Family Alumni — North America Zone Secretariat";

        $headers = [
            'Reply-To: ' . sprintf('%s <%s>', $name, $email),
        ];

        $sent = wp_mail($recipient, $email_subject, $email_body, $headers);

        if (class_exists('LFA_Core')) {
            LFA_Core::log_email_event('contact_submission', 0, $recipient, $sent ? 'dispatched' : 'failed');
        }

        $success_msg = 'Thank you for reaching out! Your message has been sent successfully. An alumni coordinator will review your inquiry and follow up shortly.';

        if ($is_ajax) {
            wp_send_json_success([
                'message'   => $success_msg,
                'record_id' => $record_id,
            ]);
        }

        wp_safe_redirect(add_query_arg('lfa_contact_status', 'success', home_url('/contact/#contact-form')));
        exit;
    }
}

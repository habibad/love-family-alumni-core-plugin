<?php
/**
 * Love Family Alumni — Secure Protected Avatar Media Handler
 *
 * Implements private storage, EXIF stripping, server-side access enforcement,
 * and secure streaming without public uploads fallback.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Media {
    public const MAX_FILE_SIZE = 3145728; // 3 MB
    public const MAX_DIMENSION = 2500;
    public const MIN_DIMENSION = 50;

    public static function init(): void {
        self::ensure_storage_dir();

        // Serve endpoints (for both logged-in and guests, privacy checked inside)
        add_action('admin_post_lfa_avatar', [__CLASS__, 'serve_avatar']);
        add_action('admin_post_nopriv_lfa_avatar', [__CLASS__, 'serve_avatar']);

        // Upload and delete endpoints
        add_action('admin_post_lfa_upload_avatar', [__CLASS__, 'handle_upload']);
        add_action('admin_post_lfa_delete_avatar', [__CLASS__, 'handle_delete']);
    }

    public static function get_storage_dir(): string {
        return WP_CONTENT_DIR . '/lfa-private-storage/avatars';
    }

    public static function ensure_storage_dir(): string {
        $dir = self::get_storage_dir();
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        $base = dirname($dir);
        $htaccess = "# Deny direct access to private alumni media\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
        $index = "<?php\nhttp_response_code(403);\nexit('Direct access forbidden.');\n";

        foreach ([$base, $dir] as $d) {
            $ht_file = $d . '/.htaccess';
            if (!file_exists($ht_file)) {
                @file_put_contents($ht_file, $htaccess);
            }
            $idx_file = $d . '/index.php';
            if (!file_exists($idx_file)) {
                @file_put_contents($idx_file, $index);
            }
        }

        return $dir;
    }

    public static function has_avatar(int $user_id): bool {
        $filename = (string) get_user_meta($user_id, 'lfa_avatar_file', true);
        if (empty($filename)) {
            return false;
        }
        $filepath = self::get_storage_dir() . '/' . basename($filename);
        return file_exists($filepath);
    }

    public static function get_avatar_url(int $user_id): string {
        if (!self::has_avatar($user_id)) {
            return '';
        }
        return admin_url('admin-post.php?action=lfa_avatar&uid=' . $user_id);
    }

    public static function handle_upload(): void {
        if (!is_user_logged_in() || !LFA_Core::can_use_portal()) {
            wp_die('Unauthorized', 'Access Denied', ['response' => 403]);
        }

        $user_id = get_current_user_id();
        if (empty($_POST['lfa_avatar_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lfa_avatar_nonce'])), 'lfa_avatar_action_' . $user_id)) {
            wp_die('Security token expired. Please refresh and try again.', 'Security Check Failed', ['response' => 403]);
        }

        if (empty($_FILES['avatar']) || !is_array($_FILES['avatar']) || empty($_FILES['avatar']['name'])) {
            LFA_Core::redirect_notice('member-dashboard', 'upload_invalid');
        }

        $file = $_FILES['avatar'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            LFA_Core::redirect_notice('member-dashboard', 'upload_invalid');
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            LFA_Core::redirect_notice('member-dashboard', 'upload_invalid');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowed_exts, true)) {
            LFA_Core::redirect_notice('member-dashboard', 'upload_invalid');
        }

        // Verify MIME type using finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
        if ($finfo) { finfo_close($finfo); }

        $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowed_mimes, true)) {
            LFA_Core::redirect_notice('member-dashboard', 'upload_invalid');
        }

        // Check image dimensions & validity
        $img_info = @getimagesize($file['tmp_name']);
        if (!$img_info || empty($img_info[0]) || empty($img_info[1])) {
            LFA_Core::redirect_notice('member-dashboard', 'upload_invalid');
        }

        $width = (int) $img_info[0];
        $height = (int) $img_info[1];

        if ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            LFA_Core::redirect_notice('member-dashboard', 'upload_invalid');
        }

        // Decode and re-encode with GD to strip EXIF/GPS, comments, and polyglots
        $src_img = null;
        switch ($mime) {
            case 'image/jpeg':
                $src_img = @imagecreatefromjpeg($file['tmp_name']);
                break;
            case 'image/png':
                $src_img = @imagecreatefrompng($file['tmp_name']);
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $src_img = @imagecreatefromwebp($file['tmp_name']);
                }
                break;
        }

        if (!$src_img) {
            // Fallback decode attempt via string
            $raw_data = file_get_contents($file['tmp_name']);
            if ($raw_data) {
                $src_img = @imagecreatefromstring($raw_data);
            }
        }

        if (!$src_img) {
            LFA_Core::redirect_notice('member-dashboard', 'upload_invalid');
        }

        // Normalize size: max 600x600 square/proportional avatar
        $max_box = 600;
        $ratio = min($max_box / $width, $max_box / $height, 1.0);
        $new_w = max(1, (int) round($width * $ratio));
        $new_h = max(1, (int) round($height * $ratio));

        $dest_img = imagecreatetruecolor($new_w, $new_h);
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($dest_img, false);
            imagesavealpha($dest_img, true);
            $transparent = imagecolorallocatealpha($dest_img, 255, 255, 255, 127);
            imagefilledrectangle($dest_img, 0, 0, $new_w, $new_h, $transparent);
        }

        imagecopyresampled($dest_img, $src_img, 0, 0, 0, 0, $new_w, $new_h, $width, $height);
        imagedestroy($src_img);

        // Remove old avatar file if present
        self::delete_current_file($user_id);

        // Generate opaque random filename
        try {
            $random_token = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            $random_token = wp_generate_password(32, false, false);
        }

        $storage_dir = self::ensure_storage_dir();
        $new_filename = sprintf('avatar_%d_%s.webp', $user_id, $random_token);
        $dest_path = $storage_dir . '/' . $new_filename;

        // Save as WebP if supported, otherwise JPEG
        $saved_mime = 'image/webp';
        if (function_exists('imagewebp')) {
            imagewebp($dest_img, $dest_path, 88);
        } else {
            $new_filename = sprintf('avatar_%d_%s.jpg', $user_id, $random_token);
            $dest_path = $storage_dir . '/' . $new_filename;
            imagejpeg($dest_img, $dest_path, 90);
            $saved_mime = 'image/jpeg';
        }
        imagedestroy($dest_img);

        // Store metadata
        update_user_meta($user_id, 'lfa_avatar_file', $new_filename);
        update_user_meta($user_id, 'lfa_avatar_mime', $saved_mime);
        update_user_meta($user_id, 'lfa_avatar_updated', time());

        // Update avatar visibility if provided
        if (isset($_POST['avatar_visibility'])) {
            $vis = LFA_Privacy::sanitize_visibility(sanitize_text_field(wp_unslash($_POST['avatar_visibility'])));
            update_user_meta($user_id, 'lfa_avatar_visibility', $vis);
        }

        LFA_Core::redirect_notice('member-dashboard', 'profile_saved');
    }

    public static function handle_delete(): void {
        if (!is_user_logged_in() || !LFA_Core::can_use_portal()) {
            wp_die('Unauthorized', 'Access Denied', ['response' => 403]);
        }

        $user_id = get_current_user_id();
        if (empty($_POST['lfa_avatar_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['lfa_avatar_nonce'])), 'lfa_avatar_action_' . $user_id)) {
            wp_die('Security token expired. Please refresh and try again.', 'Security Check Failed', ['response' => 403]);
        }

        self::delete_current_file($user_id);
        delete_user_meta($user_id, 'lfa_avatar_file');
        delete_user_meta($user_id, 'lfa_avatar_mime');
        delete_user_meta($user_id, 'lfa_avatar_updated');

        LFA_Core::redirect_notice('member-dashboard', 'profile_saved');
    }

    private static function delete_current_file(int $user_id): void {
        $old_filename = (string) get_user_meta($user_id, 'lfa_avatar_file', true);
        if (!empty($old_filename)) {
            $old_path = self::get_storage_dir() . '/' . basename($old_filename);
            if (file_exists($old_path) && is_file($old_path)) {
                @unlink($old_path);
            }
        }
    }

    public static function serve_avatar(): void {
        $subject_id = isset($_GET['uid']) ? absint($_GET['uid']) : 0;
        if ($subject_id <= 0) {
            status_header(404);
            exit('Avatar not found.');
        }

        $viewer_id = get_current_user_id();
        $is_self   = ($viewer_id > 0 && $viewer_id === $subject_id);
        $is_admin  = user_can($viewer_id, 'edit_users') || user_can($viewer_id, 'manage_options');

        // Directory Access Gate: Block unauthorized users from scraping member avatars
        if (!$is_self && !$is_admin) {
            if (class_exists('LFA_Payment_Entitlements') && !LFA_Payment_Entitlements::can_access_directory($viewer_id)) {
                status_header(403);
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: private, no-store, no-cache, must-revalidate');
                exit('Access Denied: Directory membership required.');
            }
        }

        // Server-enforced privacy check
        if (!LFA_Privacy::can_view_avatar($subject_id, $viewer_id)) {
            status_header(403);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store, no-cache, must-revalidate');
            exit('Access Denied: Private avatar.');
        }

        $filename = (string) get_user_meta($subject_id, 'lfa_avatar_file', true);
        if (empty($filename)) {
            status_header(404);
            exit('No avatar set.');
        }

        $filepath = self::get_storage_dir() . '/' . basename($filename);
        if (!file_exists($filepath) || !is_file($filepath)) {
            status_header(404);
            exit('Avatar file missing.');
        }

        $mime = (string) get_user_meta($subject_id, 'lfa_avatar_mime', true);
        if (empty($mime)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $filepath) : 'image/jpeg';
            if ($finfo) { finfo_close($finfo); }
        }

        // Security and caching headers
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Content-Length: ' . filesize($filepath));

        // Stream binary data
        readfile($filepath);
        exit;
    }
}

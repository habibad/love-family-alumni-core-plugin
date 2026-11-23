<?php
/**
 * Love Family Alumni — Akpo Family Scholarship Domain & Application System
 *
 * Implements scholarship programs, student applications, academic records,
 * personal statements, secure supporting documents outside webroot,
 * recommendations, multi-tier staff reviews, decisions, and audit logging.
 * Applications come from the public application form and are open to every
 * applicant worldwide: no account, approval, or membership dues are required.
 *
 * @package Love_Family_Core
 */

if (!defined('ABSPATH')) { exit; }

final class LFA_Scholarship {
    public const SCHEMA_VERSION = '0.7.0';
    public const OPTION_SCHEMA_VER = 'lfa_scholarship_schema_version';
    public const OPTION_SETTINGS = 'lfa_scholarship_settings';

    // Application statuses
    public const STATUS_DRAFT                    = 'draft';
    public const STATUS_SUBMITTED                = 'submitted';
    public const STATUS_UNDER_REVIEW             = 'under_review';
    public const STATUS_ADDITIONAL_INFO_REQUIRED = 'additional_info_requested';
    public const STATUS_SHORTLISTED              = 'shortlisted';
    public const STATUS_AWARDED                  = 'awarded';
    public const STATUS_DECLINED                 = 'declined';
    public const STATUS_WITHDRAWN                = 'withdrawn';

    // Program statuses
    public const PROGRAM_DRAFT                   = 'draft';
    public const PROGRAM_PUBLISHED               = 'published';
    public const PROGRAM_OPEN                    = 'open';
    public const PROGRAM_CLOSED                  = 'closed';
    public const PROGRAM_ARCHIVED                = 'archived';

    // Capabilities
    public const CAP_MANAGE_SCHOLARSHIPS         = 'lfa_manage_scholarships';
    public const CAP_REVIEW_SCHOLARSHIPS         = 'lfa_review_scholarships';
    public const CAP_PUBLISH_RECIPIENTS          = 'lfa_publish_scholarship_recipients';
    public const CAP_EXPORT_SCHOLARSHIPS         = 'lfa_export_scholarships';

    // Roles
    public const ROLE_REVIEWER                   = 'lfa_scholarship_reviewer';
    public const ROLE_ADMIN                      = 'lfa_scholarship_admin';

    // Storage path for private documents outside webroot
    public const PRIVATE_STORAGE_PATH = 'C:\\xampp\\secure_storage\\scholarship_docs\\';

    public static function init(): void {
        self::ensure_storage_dir();
        if (get_option(self::OPTION_SCHEMA_VER) !== self::SCHEMA_VERSION) {
            self::install_schema();
        }
        add_action('init', [__CLASS__, 'register_capabilities_and_roles']);
        add_action('admin_post_lfa_download_scholarship_doc', [__CLASS__, 'handle_download_document']);
        add_action('admin_post_nopriv_lfa_download_scholarship_doc', [__CLASS__, 'handle_download_document_anon']);
        add_action('admin_post_lfa_submit_scholarship_application', [__CLASS__, 'handle_public_submit']);
        add_action('admin_post_nopriv_lfa_submit_scholarship_application', [__CLASS__, 'handle_public_submit']);
    }


    public static function ensure_storage_dir(): void {
        if (!is_dir(self::PRIVATE_STORAGE_PATH)) {
            @mkdir(self::PRIVATE_STORAGE_PATH, 0750, true);
        }
    }

    public static function register_capabilities_and_roles(): void {
        $admin = get_role('administrator');
        if ($admin) {
            $caps = [
                self::CAP_MANAGE_SCHOLARSHIPS,
                self::CAP_REVIEW_SCHOLARSHIPS,
                self::CAP_PUBLISH_RECIPIENTS,
                self::CAP_EXPORT_SCHOLARSHIPS,
            ];
            foreach ($caps as $cap) {
                if (!$admin->has_cap($cap)) {
                    $admin->add_cap($cap);
                }
            }
        }

        // Scholarship Reviewer Role
        if (!get_role(self::ROLE_REVIEWER)) {
            add_role(self::ROLE_REVIEWER, 'Scholarship Reviewer', [
                'read'                   => true,
                self::CAP_REVIEW_SCHOLARSHIPS => true,
            ]);
        }

        // Scholarship Administrator Role
        if (!get_role(self::ROLE_ADMIN)) {
            add_role(self::ROLE_ADMIN, 'Scholarship Administrator', [
                'read'                         => true,
                self::CAP_MANAGE_SCHOLARSHIPS  => true,
                self::CAP_REVIEW_SCHOLARSHIPS  => true,
                self::CAP_PUBLISH_RECIPIENTS   => true,
                self::CAP_EXPORT_SCHOLARSHIPS  => true,
            ]);
        }
    }

    /* Table Name Helpers */
    public static function table_programs(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_scholarship_programs';
    }

    public static function table_applications(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_scholarship_applications';
    }

    public static function table_documents(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_scholarship_documents';
    }

    public static function table_recommendations(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_scholarship_recommendations';
    }

    public static function table_reviews(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_scholarship_reviews';
    }

    public static function table_audit(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_scholarship_audit';
    }

    public static function table_recipients(): string {
        global $wpdb;
        return $wpdb->prefix . 'lfa_scholarship_recipients';
    }

    /**
     * Versioned, idempotent schema installation using dbDelta
     */
    public static function install_schema(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        // 1. Programs Table
        $t_programs = self::table_programs();
        $sql_programs = "CREATE TABLE {$t_programs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            slug varchar(64) NOT NULL,
            title varchar(255) NOT NULL,
            purpose text NOT NULL,
            eligibility_text text NULL,
            instructions text NULL,
            application_window_start datetime NULL,
            application_window_end datetime NULL,
            timezone varchar(64) NOT NULL DEFAULT 'America/New_York',
            award_amount_cents int(11) NULL,
            currency varchar(10) NULL,
            status varchar(30) NOT NULL DEFAULT 'closed',
            capacity int(11) NULL,
            policy_version varchar(50) NOT NULL DEFAULT 'v1.0-unconfigured',
            details_json longtext NULL,
            created_by bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug_unique (slug),
            KEY status (status)
        ) {$charset_collate};";

        // 2. Applications Table
        $t_applications = self::table_applications();
        $sql_applications = "CREATE TABLE {$t_applications} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            application_ref varchar(64) NOT NULL,
            program_id bigint(20) unsigned NOT NULL,
            owner_user_id bigint(20) unsigned NULL,
            applicant_name varchar(150) NOT NULL,
            applicant_email varchar(150) NOT NULL,
            applicant_phone varchar(50) NULL,
            school_name varchar(191) NOT NULL,
            program_of_study varchar(191) NOT NULL,
            academic_level varchar(100) NULL,
            gpa_academic_details text NULL,
            personal_statement longtext NOT NULL,
            assigned_reviewer_id bigint(20) unsigned NULL,
            status varchar(30) NOT NULL DEFAULT 'draft',
            idempotency_key varchar(64) NULL,
            consent_agreed tinyint(1) NOT NULL DEFAULT 1,
            consent_version varchar(50) NOT NULL DEFAULT 'v1.0',
            applicant_feedback_notes text NULL,
            revision_version int(11) NOT NULL DEFAULT 1,
            submitted_at datetime NULL,
            decision_at datetime NULL,
            decision_by bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY app_ref_unique (application_ref),
            UNIQUE KEY idempotency_unique (idempotency_key),
            KEY program_id (program_id),
            KEY owner_user_id (owner_user_id),
            KEY assigned_reviewer_id (assigned_reviewer_id),
            KEY status (status)
        ) {$charset_collate};";

        // 3. Documents Table (Private Storage)
        $t_docs = self::table_documents();
        $sql_docs = "CREATE TABLE {$t_docs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            application_id bigint(20) unsigned NOT NULL,
            owner_user_id bigint(20) unsigned NULL,
            category varchar(64) NOT NULL DEFAULT 'supporting_doc',
            document_ref varchar(64) NOT NULL,
            display_filename varchar(255) NOT NULL,
            stored_filename varchar(255) NOT NULL,
            mime_type varchar(100) NOT NULL,
            file_size int(11) NOT NULL,
            file_hash varchar(64) NOT NULL,
            scan_state varchar(30) NOT NULL DEFAULT 'unscanned',
            is_private tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY doc_ref_unique (document_ref),
            KEY application_id (application_id),
            KEY owner_user_id (owner_user_id)
        ) {$charset_collate};";

        // 4. Recommendations Table
        $t_recs = self::table_recommendations();
        $sql_recs = "CREATE TABLE {$t_recs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            application_id bigint(20) unsigned NOT NULL,
            recommender_name varchar(150) NOT NULL,
            recommender_email varchar(150) NOT NULL,
            recommender_phone varchar(50) NULL,
            recommender_relationship varchar(100) NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'pending',
            recommendation_text text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY application_id (application_id)
        ) {$charset_collate};";

        // 5. Reviews Table (Confidential Staff Notes)
        $t_reviews = self::table_reviews();
        $sql_reviews = "CREATE TABLE {$t_reviews} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            application_id bigint(20) unsigned NOT NULL,
            reviewer_id bigint(20) unsigned NOT NULL,
            review_state varchar(30) NOT NULL DEFAULT 'under_review',
            private_notes longtext NOT NULL,
            applicant_feedback text NULL,
            conflict_of_interest_cleared tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY application_id (application_id),
            KEY reviewer_id (reviewer_id)
        ) {$charset_collate};";

        // 6. Audit Trail Table
        $t_audit = self::table_audit();
        $sql_audit = "CREATE TABLE {$t_audit} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            application_id bigint(20) unsigned NULL,
            actor_id bigint(20) unsigned NULL,
            actor_type varchar(30) NOT NULL DEFAULT 'system',
            action varchar(100) NOT NULL,
            from_status varchar(30) NULL,
            to_status varchar(30) NULL,
            details_json text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY application_id (application_id),
            KEY actor_id (actor_id),
            KEY action (action)
        ) {$charset_collate};";

        // 7. Recipients Table (Explicit Consent & Publish Cap Required)
        $t_recipients = self::table_recipients();
        $sql_recipients = "CREATE TABLE {$t_recipients} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            program_id bigint(20) unsigned NOT NULL,
            application_id bigint(20) unsigned NOT NULL,
            public_name varchar(150) NOT NULL,
            academic_year varchar(20) NOT NULL,
            institution varchar(191) NOT NULL,
            award_details text NULL,
            photo_url varchar(255) NULL,
            consent_given tinyint(1) NOT NULL DEFAULT 0,
            published tinyint(1) NOT NULL DEFAULT 0,
            published_by bigint(20) unsigned NULL,
            published_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY program_id (program_id),
            KEY application_id (application_id),
            KEY published (published)
        ) {$charset_collate};";

        dbDelta($sql_programs);
        dbDelta($sql_applications);
        dbDelta($sql_docs);
        dbDelta($sql_recs);
        dbDelta($sql_reviews);
        dbDelta($sql_audit);
        dbDelta($sql_recipients);

        // Seed Akpo Family Scholarship Program
        self::seed_akpo_family_scholarship();

        update_option(self::OPTION_SCHEMA_VER, self::SCHEMA_VERSION);
    }

    /**
     * Seed authoritative Akpo Family Scholarship program row with safe unconfigured defaults.
     * Does NOT invent award amounts, eligibility criteria, or deadlines.
     */
    public static function seed_akpo_family_scholarship(): void {
        global $wpdb;
        $t_programs = self::table_programs();
        $slug = 'the-akpo-family-scholarship';

        $existing = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$t_programs} WHERE slug = %s", $slug));
        if ($existing) {
            return;
        }

        $now = current_time('mysql', true);
        $wpdb->insert($t_programs, [
            'slug'                     => $slug,
            'title'                    => 'The Akpo Family Scholarship',
            'purpose'                  => 'To provide educational support and academic advancement opportunities for eligible students within the Love Family Alumni community.',
            'eligibility_text'         => 'Eligibility guidelines, academic standing criteria, and approved student candidate parameters are pending executive committee policy confirmation.',
            'instructions'             => 'Review program guidelines, supply current institution and program of study details, submit your academic background and personal statement, and upload verified supporting documentation once the submission window officially opens.',
            'application_window_start' => null,
            'application_window_end'   => null,
            'timezone'                 => 'America/New_York',
            'award_amount_cents'       => null,
            'currency'                 => null,
            'status'                   => self::PROGRAM_CLOSED,
            'capacity'                 => null,
            'policy_version'           => 'v1.0-unconfigured',
            'details_json'             => json_encode([
                'sponsor'          => 'The Akpo Family',
                'selection_info'   => 'Selection is determined by the Scholarship Committee following comprehensive application review.',
                'documents_note'   => 'Configurable supporting documents (such as academic transcript and student proof) are verified upon window opening.',
                'recommendations'  => 'Academic and professional recommendations may be requested based on configured program requirements.',
            ], JSON_UNESCAPED_SLASHES),
            'created_by'               => 1,
            'created_at'               => $now,
            'updated_at'               => $now,
        ]);
    }

    /* Program Helpers */
    public static function get_program(int $id): ?object {
        global $wpdb;
        $t = self::table_programs();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $id));
    }

    public static function get_program_by_slug(string $slug): ?object {
        global $wpdb;
        $t = self::table_programs();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE slug = %s", $slug));
    }

    public static function get_all_programs(bool $only_published = false): array {
        global $wpdb;
        $t = self::table_programs();
        if ($only_published) {
            return $wpdb->get_results("SELECT * FROM {$t} WHERE status IN ('published', 'open', 'closed') ORDER BY id DESC");
        }
        return $wpdb->get_results("SELECT * FROM {$t} ORDER BY id DESC");
    }

    public static function is_application_open(object $program): bool {
        if ($program->status !== self::PROGRAM_OPEN) {
            return false;
        }
        if (empty($program->application_window_start) || empty($program->application_window_end)) {
            // Unconfigured eligibility / deadlines keep submission closed
            return false;
        }

        try {
            $tz = new DateTimeZone($program->timezone ?: 'America/New_York');
            $now = new DateTime('now', $tz);
            $start = new DateTime($program->application_window_start, $tz);
            $end = new DateTime($program->application_window_end, $tz);
            return ($now >= $start && $now <= $end);
        } catch (Exception $e) {
            return false;
        }
    }

    /* Application Helpers */
    public static function get_application(int $id): ?object {
        global $wpdb;
        $t = self::table_applications();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $id));
    }

    public static function get_application_by_ref(string $ref): ?object {
        global $wpdb;
        $t = self::table_applications();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE application_ref = %s", $ref));
    }

    public static function generate_application_ref(): string {
        return 'SCH-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * Create and submit an application from the public application form.
     * Open to every applicant: no account, approval, or membership dues are required.
     * Validated and idempotent; the optional supporting document is checked before anything is saved.
     */
    public static function submit_application(array $data, array $file, int $user_id): array {
        global $wpdb;
        $t_app = self::table_applications();

        $program = self::get_program((int)($data['program_id'] ?? 0));
        if (!$program) {
            return ['success' => false, 'code' => 'PROGRAM_NOT_FOUND', 'message' => 'Scholarship program not found.'];
        }

        // A repeated submission of the same form returns the original application (Idempotency)
        $idempotency_key = sanitize_text_field($data['idempotency_key'] ?? '');
        if ($idempotency_key !== '') {
            $existing_ref = $wpdb->get_var($wpdb->prepare("SELECT application_ref FROM {$t_app} WHERE idempotency_key = %s", $idempotency_key));
            if ($existing_ref) {
                return ['success' => true, 'application_ref' => $existing_ref];
            }
        }

        // Check if application window is open
        if (!self::is_application_open($program)) {
            return [
                'success' => false,
                'code'    => 'WINDOW_CLOSED',
                'message' => 'Applications are currently closed for this scholarship program. Please check the official application schedule.',
            ];
        }

        $name      = sanitize_text_field($data['applicant_name'] ?? '');
        $email     = sanitize_email($data['applicant_email'] ?? '');
        $school    = sanitize_text_field($data['school_name'] ?? '');
        $major     = sanitize_text_field($data['program_of_study'] ?? '');
        $statement = sanitize_textarea_field($data['personal_statement'] ?? '');

        // Server-side required field validation
        $errors = [];
        if ($name === '') { $errors[] = 'Full applicant name is required.'; }
        if (!is_email($email)) { $errors[] = 'A valid applicant email address is required.'; }
        if ($school === '') { $errors[] = 'Current school / institution name is required.'; }
        if ($major === '') { $errors[] = 'Program of study / major is required.'; }
        if (mb_strlen($statement) < 50) { $errors[] = 'A personal statement of at least 50 characters is required.'; }
        if (empty($data['consent_agreed'])) { $errors[] = 'Please confirm that the information you submitted is accurate.'; }

        $document = null;
        if (!empty($file)) {
            $document = self::validate_document($file);
            if (isset($document['error'])) { $errors[] = $document['error']; }
        }

        if (!empty($errors)) {
            return ['success' => false, 'code' => 'VALIDATION_FAILED', 'message' => implode(' ', $errors)];
        }

        $now = current_time('mysql', true);
        $ref = self::generate_application_ref();
        $inserted = $wpdb->insert($t_app, [
            'application_ref'      => $ref,
            'program_id'           => (int)$program->id,
            'owner_user_id'        => ($user_id > 0) ? $user_id : null,
            'applicant_name'       => $name,
            'applicant_email'      => $email,
            'school_name'          => $school,
            'program_of_study'     => $major,
            'academic_level'       => sanitize_text_field($data['academic_level'] ?? ''),
            'gpa_academic_details' => sanitize_textarea_field($data['gpa_academic_details'] ?? ''),
            'personal_statement'   => $statement,
            'status'               => self::STATUS_SUBMITTED,
            'idempotency_key'      => ($idempotency_key !== '') ? $idempotency_key : null,
            'consent_agreed'       => 1,
            'consent_version'      => 'v1.0',
            'submitted_at'         => $now,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);
        if (!$inserted) {
            return ['success' => false, 'code' => 'STORAGE_ERROR', 'message' => 'Your application could not be saved. Please try again.'];
        }

        $app = self::get_application((int)$wpdb->insert_id);
        self::log_audit((int)$app->id, $user_id, 'applicant', 'submitted', null, self::STATUS_SUBMITTED, 'Application submitted via the public application form.');

        if ($document) {
            self::store_document($app, $file, $document, $user_id);
        }

        // Dispatch genuine confirmation email via wp_mail
        self::send_application_email($app, 'submitted');

        return ['success' => true, 'application_ref' => $ref];
    }

    /**
     * Validate an uploaded supporting document: PDF, JPG or PNG up to 5MB (strict finfo MIME check).
     * Returns the detected MIME type, stored extension and display name, or an error message.
     */
    private static function validate_document(array $file): array {
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['error' => 'The supporting document could not be uploaded. Please attach a PDF, JPG, or PNG file up to 5MB.'];
        }

        if ((int)$file['size'] > 5242880) {
            return ['error' => 'File size exceeds 5MB limit.'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowed_mimes = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
        $name = sanitize_file_name($file['name'] ?? 'document');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset($allowed_mimes[$mime]) || !in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
            return ['error' => 'Invalid file format. Only PDF, JPG, and PNG documents are permitted.'];
        }

        return ['mime' => $mime, 'ext' => $allowed_mimes[$mime], 'name' => $name];
    }

    /* Document Storage Outside Web Root */
    private static function store_document(object $app, array $file, array $document, int $user_id): void {
        global $wpdb;
        self::ensure_storage_dir();

        $doc_ref = 'DOC-' . strtoupper(bin2hex(random_bytes(8)));
        $stored_filename = 'lfa_doc_' . $app->application_ref . '_' . bin2hex(random_bytes(6)) . '.' . $document['ext'];
        $dest_path = self::PRIVATE_STORAGE_PATH . $stored_filename;

        if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
            self::log_audit((int)$app->id, $user_id, 'system', 'document_storage_failed', null, null, 'Could not save supporting document to secure storage: ' . $document['name']);
            return;
        }

        $wpdb->insert(self::table_documents(), [
            'application_id'   => (int)$app->id,
            'owner_user_id'    => $app->owner_user_id,
            'category'         => 'transcript',
            'document_ref'     => $doc_ref,
            'display_filename' => $document['name'],
            'stored_filename'  => $stored_filename,
            'mime_type'        => $document['mime'],
            'file_size'        => (int)$file['size'],
            'file_hash'        => hash_file('sha256', $dest_path),
            'scan_state'       => 'unscanned',
            'is_private'       => 1,
            'created_at'       => current_time('mysql', true),
        ]);

        self::log_audit((int)$app->id, $user_id, 'applicant', 'document_uploaded', null, null, 'Uploaded supporting document: ' . $document['name'] . ' [Ref: ' . $doc_ref . ']');
    }

    /**
     * Get documents for an application
     */
    public static function get_application_documents(int $app_id): array {
        global $wpdb;
        $t = self::table_documents();
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE application_id = %d ORDER BY id ASC", $app_id));
    }

    /**
     * Serve private document via authorized streaming
     */
    public static function handle_download_document(): void {
        $doc_ref = sanitize_text_field($_GET['doc_ref'] ?? '');
        $current_user_id = get_current_user_id();

        if (empty($doc_ref)) {
            wp_die('Invalid document request.', 'Bad Request', ['response' => 400]);
        }

        global $wpdb;
        $t_docs = self::table_documents();
        $doc = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_docs} WHERE document_ref = %s", $doc_ref));
        if (!$doc) {
            wp_die('Document not found.', 'Not Found', ['response' => 404]);
        }

        $app = self::get_application((int)$doc->application_id);
        if (!$app) {
            wp_die('Application not found.', 'Not Found', ['response' => 404]);
        }

        // Authorization check
        $can_access = false;
        if (current_user_can(self::CAP_MANAGE_SCHOLARSHIPS)) {
            $can_access = true;
        } elseif (current_user_can(self::CAP_REVIEW_SCHOLARSHIPS)) {
            // Reviewer must be assigned to this application, or admin
            if ((int)$app->assigned_reviewer_id === $current_user_id || current_user_can('manage_options')) {
                $can_access = true;
            }
        } elseif ($current_user_id > 0 && (int)$app->owner_user_id === $current_user_id) {
            $can_access = true;
        }

        if (!$can_access) {
            wp_die('Unauthorized: You do not have permission to view or download this private document.', 'Forbidden', ['response' => 403]);
        }

        $filepath = self::PRIVATE_STORAGE_PATH . $doc->stored_filename;
        if (!file_exists($filepath)) {
            wp_die('Document storage file missing.', 'Not Found', ['response' => 404]);
        }

        // Log document access (without exposing private payload)
        self::log_audit((int)$app->id, $current_user_id, 'staff', 'document_downloaded', null, null, 'Document ref downloaded: ' . $doc->document_ref);

        // Send secure download headers
        if (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: ' . $doc->mime_type);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $doc->display_filename) . '"');
        header('Content-Length: ' . filesize($filepath));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        readfile($filepath);
        exit;
    }

    public static function handle_download_document_anon(): void {
        wp_die('Unauthorized: Sign in required to access private documents.', 'Forbidden', ['response' => 403]);
    }

    /* Staff Review & Decision Workflows */
    public static function assign_reviewer(int $app_id, int $reviewer_id, int $admin_id): array {
        if (!current_user_can(self::CAP_MANAGE_SCHOLARSHIPS)) {
            return ['success' => false, 'code' => 'FORBIDDEN', 'message' => 'Unauthorized: Only scholarship administrators can assign reviewers.'];
        }

        $app = self::get_application($app_id);
        if (!$app) {
            return ['success' => false, 'code' => 'NOT_FOUND', 'message' => 'Application not found.'];
        }

        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->update(self::table_applications(), [
            'assigned_reviewer_id' => $reviewer_id,
            'status'               => ($app->status === self::STATUS_SUBMITTED) ? self::STATUS_UNDER_REVIEW : $app->status,
            'updated_at'           => $now,
        ], ['id' => $app_id]);

        self::log_audit($app_id, $admin_id, 'admin', 'reviewer_assigned', $app->status, self::STATUS_UNDER_REVIEW, 'Assigned reviewer user ID: ' . $reviewer_id);

        return ['success' => true, 'message' => 'Reviewer assigned successfully.'];
    }

    public static function add_review_notes(int $app_id, int $reviewer_id, string $review_state, string $notes, string $applicant_feedback = ''): array {
        if (!current_user_can(self::CAP_REVIEW_SCHOLARSHIPS)) {
            return ['success' => false, 'code' => 'FORBIDDEN', 'message' => 'Unauthorized: Review capability required.'];
        }

        $app = self::get_application($app_id);
        if (!$app) {
            return ['success' => false, 'code' => 'NOT_FOUND', 'message' => 'Application not found.'];
        }

        // Reviewer assignment check (must be assigned, or admin)
        if (!current_user_can(self::CAP_MANAGE_SCHOLARSHIPS) && (int)$app->assigned_reviewer_id !== $reviewer_id) {
            return ['success' => false, 'code' => 'UNASSIGNED', 'message' => 'You are not assigned to review this application.'];
        }

        global $wpdb;
        $t_rev = self::table_reviews();
        $now = current_time('mysql', true);

        $wpdb->insert($t_rev, [
            'application_id'             => $app_id,
            'reviewer_id'                => $reviewer_id,
            'review_state'               => sanitize_key($review_state ?: 'under_review'),
            'private_notes'              => sanitize_textarea_field($notes),
            'applicant_feedback'         => sanitize_textarea_field($applicant_feedback),
            'conflict_of_interest_cleared' => 1,
            'created_at'                 => $now,
            'updated_at'                 => $now,
        ]);

        $new_status = $app->status;
        if ($review_state === 'shortlisted') {
            $new_status = self::STATUS_SHORTLISTED;
        } elseif ($review_state === 'info_requested') {
            $new_status = self::STATUS_ADDITIONAL_INFO_REQUIRED;
        } elseif ($app->status === self::STATUS_SUBMITTED) {
            $new_status = self::STATUS_UNDER_REVIEW;
        }

        $update_app = [
            'status'     => $new_status,
            'updated_at' => $now,
        ];
        if (!empty($applicant_feedback)) {
            $update_app['applicant_feedback_notes'] = sanitize_textarea_field($applicant_feedback);
        }

        $wpdb->update(self::table_applications(), $update_app, ['id' => $app_id]);

        self::log_audit($app_id, $reviewer_id, 'staff', 'review_recorded', $app->status, $new_status, 'Review state: ' . $review_state);

        if ($new_status === self::STATUS_ADDITIONAL_INFO_REQUIRED) {
            self::send_application_email($app, 'info_requested', $applicant_feedback);
        }

        return ['success' => true, 'status' => $new_status, 'message' => 'Review notes recorded successfully.'];
    }

    public static function record_decision(int $app_id, string $decision, string $admin_notes, int $admin_id): array {
        if (!current_user_can(self::CAP_MANAGE_SCHOLARSHIPS)) {
            return ['success' => false, 'code' => 'FORBIDDEN', 'message' => 'Unauthorized: Only scholarship administrators can record final scholarship decisions.'];
        }

        $app = self::get_application($app_id);
        if (!$app) {
            return ['success' => false, 'code' => 'NOT_FOUND', 'message' => 'Application not found.'];
        }

        if (!in_array($decision, [self::STATUS_AWARDED, self::STATUS_DECLINED], true)) {
            return ['success' => false, 'code' => 'INVALID_DECISION', 'message' => 'Decision must be either awarded or declined.'];
        }

        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->update(self::table_applications(), [
            'status'       => $decision,
            'decision_at'  => $now,
            'decision_by'  => $admin_id,
            'updated_at'   => $now,
        ], ['id' => $app_id]);

        self::log_audit($app_id, $admin_id, 'admin', 'decision_recorded', $app->status, $decision, 'Decision: ' . $decision . '. Note: ' . sanitize_text_field($admin_notes));

        // Note: STATUS_AWARDED does NOT trigger PayPal payment or publicize recipient automatically!
        self::send_application_email($app, 'decision_' . $decision, $admin_notes);

        return ['success' => true, 'status' => $decision, 'message' => 'Decision recorded successfully: ' . strtoupper($decision)];
    }

    /**
     * Publish Recipient (Strict Consent & Publish Permission Required)
     */
    public static function publish_recipient(int $app_id, array $recipient_data, int $admin_id): array {
        if (!current_user_can(self::CAP_PUBLISH_RECIPIENTS)) {
            return ['success' => false, 'code' => 'FORBIDDEN', 'message' => 'Unauthorized: lfa_publish_scholarship_recipients capability required.'];
        }

        $app = self::get_application($app_id);
        if (!$app || $app->status !== self::STATUS_AWARDED) {
            return ['success' => false, 'code' => 'NOT_ELIGIBLE', 'message' => 'Only awarded scholarship applicants can be published as recipients.'];
        }

        if (empty($recipient_data['consent_given'])) {
            return ['success' => false, 'code' => 'CONSENT_REQUIRED', 'message' => 'Explicit recipient consent is mandatory prior to public publication.'];
        }

        global $wpdb;
        $t_rec = self::table_recipients();
        $now = current_time('mysql', true);

        $wpdb->insert($t_rec, [
            'program_id'     => $app->program_id,
            'application_id' => $app_id,
            'public_name'    => sanitize_text_field($recipient_data['public_name'] ?? $app->applicant_name),
            'academic_year'  => sanitize_text_field($recipient_data['academic_year'] ?? date('Y')),
            'institution'    => sanitize_text_field($recipient_data['institution'] ?? $app->school_name),
            'award_details'  => sanitize_textarea_field($recipient_data['award_details'] ?? ''),
            'photo_url'      => esc_url_raw($recipient_data['photo_url'] ?? ''),
            'consent_given'  => 1,
            'published'      => 1,
            'published_by'   => $admin_id,
            'published_at'   => $now,
            'created_at'     => $now,
        ]);

        self::log_audit($app_id, $admin_id, 'admin', 'recipient_published', null, 'published', 'Recipient publicized with consent.');

        return ['success' => true, 'recipient_id' => (int)$wpdb->insert_id, 'message' => 'Recipient published successfully.'];
    }

    public static function get_published_recipients(int $program_id): array {
        global $wpdb;
        $t = self::table_recipients();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE program_id = %d AND published = 1 AND consent_given = 1 ORDER BY id DESC",
            $program_id
        ));
    }

    /* Email Notification Helper (Uses wp_mail, Deduplicated, No PII in Logs) */
    public static function send_application_email(object $app, string $event_type, string $extra_note = ''): bool {
        $to = sanitize_email($app->applicant_email);
        if (empty($to)) {
            return false;
        }

        $program = self::get_program((int)$app->program_id);
        $program_title = $program ? $program->title : 'The Akpo Family Scholarship';

        $subject = match ($event_type) {
            'submitted'          => 'Application Received: ' . $program_title . ' [' . $app->application_ref . ']',
            'info_requested'     => 'Additional Information Requested: ' . $program_title . ' [' . $app->application_ref . ']',
            'decision_awarded'   => 'Scholarship Decision Notification: ' . $program_title . ' [' . $app->application_ref . ']',
            'decision_declined'  => 'Scholarship Decision: ' . $program_title . ' [' . $app->application_ref . ']',
            default              => 'Scholarship Update: ' . $program_title,
        };

        $body = "Dear " . esc_html($app->applicant_name) . ",\n\n";

        if ($event_type === 'submitted') {
            $body .= "Thank you for submitting your application for " . $program_title . ".\n\n";
            $body .= "Your application reference number is: " . $app->application_ref . "\n";
            $body .= "Our scholarship committee will review your submission in accordance with established program guidelines. We will contact you at this email address with any updates on your application.\n";
        } elseif ($event_type === 'info_requested') {
            $body .= "The scholarship review committee has requested additional information regarding your application (" . $app->application_ref . ").\n\n";
            if (!empty($extra_note)) {
                $body .= "Committee Feedback / Details Needed:\n" . esc_html($extra_note) . "\n\n";
            }
            $body .= "Please reply to this email with the requested details and include your application reference (" . $app->application_ref . ").\n";
        } elseif ($event_type === 'decision_awarded') {
            $body .= "Congratulations! We are pleased to inform you that your application (" . $app->application_ref . ") for " . $program_title . " has been approved for award.\n\n";
            $body .= "The committee will contact you regarding the formal verification and award distribution steps.\n";
        } elseif ($event_type === 'decision_declined') {
            $body .= "Thank you for taking the time to apply for " . $program_title . " (" . $app->application_ref . ").\n\n";
            $body .= "Following careful evaluation of all applications, we regret to inform you that your application was not selected for this award cycle. We encourage you to apply again in future cycles.\n";
        }

        $body .= "\nWarm regards,\nLove Family Alumni — Scholarship Committee\nNorth America Zone\n" . site_url() . "\n";

        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $sent = wp_mail($to, $subject, $body, $headers);

        // Security-hardened event logging: NO full body or essay text stored in logs!
        self::log_audit((int)$app->id, 0, 'system', 'email_dispatched', null, null, 'Email type: ' . $event_type . ', recipient domain: ' . substr(strrchr($to, "@"), 1) . ', result: ' . ($sent ? 'sent' : 'failed'));

        return $sent;
    }

    /* Immutable Audit Logger */
    public static function log_audit(
        ?int $app_id,
        ?int $actor_id,
        string $actor_type,
        string $action,
        ?string $from_status,
        ?string $to_status,
        string $details = ''
    ): void {
        global $wpdb;
        $t_audit = self::table_audit();
        $wpdb->insert($t_audit, [
            'application_id' => $app_id,
            'actor_id'       => $actor_id,
            'actor_type'     => sanitize_key($actor_type),
            'action'         => sanitize_key($action),
            'from_status'    => $from_status ? sanitize_key($from_status) : null,
            'to_status'      => $to_status ? sanitize_key($to_status) : null,
            'details_json'   => json_encode(['details' => sanitize_text_field($details)], JSON_UNESCAPED_SLASHES),
            'created_at'     => current_time('mysql', true),
        ]);
    }

    /* Public Application Form Handler (admin-post, guests and signed-in visitors alike) */
    public static function handle_public_submit(): void {
        // Honeypot anti-spam field must stay empty
        if (!empty($_POST['lfa_scholarship_hp'])) {
            wp_die('Invalid submission.', 'Security Error', ['response' => 400]);
        }

        check_admin_referer('lfa_scholarship_apply', 'lfa_scholarship_nonce');

        $back = home_url('/scholarships/');

        // IP rate limit: at most 5 submitted applications per hour
        $rate_key = 'lfa_sch_apply_' . substr(hash_hmac('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), wp_salt('auth')), 0, 28);
        $submitted_count = (int)get_transient($rate_key);
        if ($submitted_count >= 5) {
            wp_safe_redirect(add_query_arg('lfa_error', rawurlencode('You have submitted several applications recently. Please wait an hour before submitting another.'), $back) . '#scholarship-application');
            exit;
        }

        $file = $_FILES['document_file'] ?? [];
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $file = [];
        }

        $data = [];
        foreach (['program_id', 'applicant_name', 'applicant_email', 'school_name', 'program_of_study', 'academic_level', 'gpa_academic_details', 'personal_statement', 'consent_agreed', 'idempotency_key'] as $field) {
            $data[$field] = (isset($_POST[$field]) && is_scalar($_POST[$field])) ? wp_unslash((string)$_POST[$field]) : '';
        }

        $res = self::submit_application($data, $file, get_current_user_id());
        if ($res['success']) {
            set_transient($rate_key, $submitted_count + 1, HOUR_IN_SECONDS);
            $back = add_query_arg('lfa_notice', 'scholarship_submitted', $back);
        } else {
            $back = add_query_arg('lfa_error', rawurlencode($res['message']), $back);
        }

        wp_safe_redirect($back . '#scholarship-application');
        exit;
    }
}

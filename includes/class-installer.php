<?php
/**
 * Installateur du plugin — création des tables SQL.
 */

defined('ABSPATH') || exit;

class PE_Installer {

    /**
     * Vérifie si les tables existent réellement en base.
     */
    public static function tables_exist(): bool {
        global $wpdb;
        $table = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->prefix . 'b2b_companies')
        );
        return !empty($table);
    }

    public static function install(): void {
        global $wpdb;

        // Toujours créer si les tables n'existent pas, peu importe la version stockée.
        if (!self::tables_exist()) {
            // Forcer la réinstallation.
            delete_option('pe_db_version');
        }

        $current_version = self::get_db_version();

        if (version_compare($current_version, PE_DB_VERSION, '>=')) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Utiliser le charset/collation réel de l'installation WP (évite les conflits utf8mb4 vs utf8).
        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'b2b_';

        // Table companies
        $sql_companies = "CREATE TABLE IF NOT EXISTS {$prefix}companies (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            customer_code VARCHAR(50) NOT NULL DEFAULT '',
            siret VARCHAR(14) NOT NULL DEFAULT '',
            vat_number VARCHAR(20) NOT NULL DEFAULT '',
            naf_code VARCHAR(10) NOT NULL DEFAULT '',
            billing_address LONGTEXT NULL,
            shipping_address LONGTEXT NULL,
            phone VARCHAR(30) NOT NULL DEFAULT '',
            fax VARCHAR(30) NOT NULL DEFAULT '',
            contact_function VARCHAR(100) NOT NULL DEFAULT '',
            contact_first_name VARCHAR(100) NOT NULL DEFAULT '',
            contact_last_name VARCHAR(100) NOT NULL DEFAULT '',
            discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_terms INT UNSIGNED NOT NULL DEFAULT 30,
            payment_method_code VARCHAR(50) NOT NULL DEFAULT '',
            payment_method_label VARCHAR(255) NOT NULL DEFAULT '',
            category VARCHAR(100) NOT NULL DEFAULT '',
            activity VARCHAR(255) NOT NULL DEFAULT '',
            comments TEXT NULL,
            assigned_rep_id BIGINT UNSIGNED DEFAULT NULL,
            status ENUM('active','suspended') NOT NULL DEFAULT 'active',
            modules_enabled LONGTEXT NULL,
            budget_monthly DECIMAL(12,2) DEFAULT NULL,
            budget_annual DECIMAL(12,2) DEFAULT NULL,
            budget_block_enabled TINYINT(1) NOT NULL DEFAULT 1,
            tdw_profile_slug VARCHAR(100) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY assigned_rep_id (assigned_rep_id),
            KEY customer_code (customer_code),
            KEY client_id (client_id)
        ) ENGINE=InnoDB {$charset_collate};";

        // Table clients — identité client, indépendante des sociétés (un client peut
        // exister avant même d'avoir une société créée).
        $sql_clients = "CREATE TABLE IF NOT EXISTS {$prefix}clients (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_code VARCHAR(50) NOT NULL DEFAULT '',
            name VARCHAR(255) NOT NULL,
            billing_address LONGTEXT NULL,
            shipping_address LONGTEXT NULL,
            phone VARCHAR(30) NOT NULL DEFAULT '',
            fax VARCHAR(30) NOT NULL DEFAULT '',
            contact_function VARCHAR(100) NOT NULL DEFAULT '',
            contact_first_name VARCHAR(100) NOT NULL DEFAULT '',
            contact_last_name VARCHAR(100) NOT NULL DEFAULT '',
            discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_terms INT UNSIGNED NOT NULL DEFAULT 30,
            payment_method_code VARCHAR(50) NOT NULL DEFAULT '',
            payment_method_label VARCHAR(255) NOT NULL DEFAULT '',
            category VARCHAR(100) NOT NULL DEFAULT '',
            activity VARCHAR(255) NOT NULL DEFAULT '',
            comments TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY customer_code (customer_code)
        ) ENGINE=InnoDB {$charset_collate};";

        // Table agencies
        $sql_agencies = "CREATE TABLE IF NOT EXISTS {$prefix}agencies (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            address LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY company_id (company_id)
        ) ENGINE=InnoDB {$charset_collate};";

        // Table user_company
        $sql_user_company = "CREATE TABLE IF NOT EXISTS {$prefix}user_company (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            company_id BIGINT UNSIGNED NOT NULL,
            agency_id BIGINT UNSIGNED DEFAULT NULL,
            role ENUM('company_admin','purchase_manager','buyer','requester','accountant') NOT NULL DEFAULT 'buyer',
            budget_monthly DECIMAL(12,2) DEFAULT NULL,
            budget_annual DECIMAL(12,2) DEFAULT NULL,
            budget_per_order DECIMAL(12,2) DEFAULT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_company_primary (user_id, company_id),
            KEY company_id (company_id),
            KEY user_id (user_id),
            KEY agency_id (agency_id)
        ) ENGINE=InnoDB {$charset_collate};";

        // Table cost_centers
        $sql_cost_centers = "CREATE TABLE IF NOT EXISTS {$prefix}cost_centers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            code VARCHAR(50) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY company_id (company_id)
        ) ENGINE=InnoDB {$charset_collate};";

        // Table budget_usage
        $sql_budget_usage = "CREATE TABLE IF NOT EXISTS {$prefix}budget_usage (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            company_id BIGINT UNSIGNED NOT NULL,
            period_month CHAR(6) NOT NULL,
            period_year CHAR(4) NOT NULL,
            amount_spent DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            order_count INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_period (user_id, company_id, period_month),
            KEY company_id (company_id),
            KEY user_id (user_id),
            KEY period_month (period_month)
        ) ENGINE=InnoDB {$charset_collate};";

        // Table approval_rules
        $sql_approval_rules = "CREATE TABLE IF NOT EXISTS {$prefix}approval_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            agency_id BIGINT UNSIGNED DEFAULT NULL,
            threshold_min DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            threshold_max DECIMAL(12,2) DEFAULT NULL,
            approver_roles LONGTEXT NULL,
            delay_hours INT UNSIGNED NOT NULL DEFAULT 24,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY company_id (company_id),
            KEY agency_id (agency_id)
        ) ENGINE=InnoDB {$charset_collate};";

        // Table approval_requests
        $sql_approval_requests = "CREATE TABLE IF NOT EXISTS {$prefix}approval_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            company_id BIGINT UNSIGNED NOT NULL,
            requester_id BIGINT UNSIGNED NOT NULL,
            approver_id BIGINT UNSIGNED DEFAULT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            cost_center_id BIGINT UNSIGNED DEFAULT NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY company_id (company_id),
            KEY requester_id (requester_id),
            KEY approver_id (approver_id),
            KEY status (status)
        ) ENGINE=InnoDB {$charset_collate};";

        // Table approval_tokens (Magic Link)
        $sql_approval_tokens = "CREATE TABLE IF NOT EXISTS {$prefix}approval_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id BIGINT UNSIGNED NOT NULL,
            approver_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            action VARCHAR(20) NOT NULL DEFAULT 'decision',
            status ENUM('active','used','expired','revoked') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            used_action VARCHAR(20) DEFAULT NULL,
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            user_agent VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            UNIQUE KEY token_hash (token_hash),
            KEY request_id (request_id),
            KEY approver_id (approver_id),
            KEY status (status)
        ) ENGINE=InnoDB {$charset_collate};";

        // Table audit_log
        $sql_audit_log = "CREATE TABLE IF NOT EXISTS {$prefix}audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            company_id BIGINT UNSIGNED DEFAULT NULL,
            action VARCHAR(100) NOT NULL,
            object_type VARCHAR(100) NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            data LONGTEXT NULL,
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY company_id (company_id),
            KEY action (action),
            KEY object_type (object_type),
            KEY created_at (created_at)
        ) ENGINE=InnoDB {$charset_collate};";

        $tables = [
            $sql_companies,
            $sql_clients,
            $sql_agencies,
            $sql_user_company,
            $sql_cost_centers,
            $sql_budget_usage,
            $sql_approval_rules,
            $sql_approval_requests,
            $sql_approval_tokens,
            $sql_audit_log,
        ];

        $errors = [];
        foreach ($tables as $sql) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query($sql);
            if ($wpdb->last_error) {
                $errors[] = $wpdb->last_error;
            }
        }

        if (!empty($errors)) {
            error_log('[Portail Entreprises] Erreurs création tables : ' . implode(' | ', $errors));
            if (!self::tables_exist()) {
                return;
            }
        }

        // Migrate: add company budget columns if missing (v1.2.0)
        $col = $wpdb->get_var("SHOW COLUMNS FROM {$prefix}companies LIKE 'budget_monthly'");
        if (!$col) {
            $wpdb->query("ALTER TABLE {$prefix}companies ADD COLUMN budget_monthly DECIMAL(12,2) DEFAULT NULL");
            $wpdb->query("ALTER TABLE {$prefix}companies ADD COLUMN budget_annual DECIMAL(12,2) DEFAULT NULL");
            $wpdb->query("ALTER TABLE {$prefix}companies ADD COLUMN budget_block_enabled TINYINT(1) NOT NULL DEFAULT 1");
        }

        // Migrate: add tdw_profile_slug column if missing (v1.3.0)
        $col_profile = $wpdb->get_var("SHOW COLUMNS FROM {$prefix}companies LIKE 'tdw_profile_slug'");
        if (!$col_profile) {
            $wpdb->query("ALTER TABLE {$prefix}companies ADD COLUMN tdw_profile_slug VARCHAR(100) DEFAULT NULL");
        }

        // Migrate: add shipping_address column if missing (v1.5.0)
        $col_shipping = $wpdb->get_var("SHOW COLUMNS FROM {$prefix}companies LIKE 'shipping_address'");
        if (!$col_shipping) {
            $wpdb->query("ALTER TABLE {$prefix}companies ADD COLUMN shipping_address LONGTEXT NULL AFTER billing_address");
        }

        // Migrate: add "fiche client" columns if missing (v1.6.0)
        $col_customer_code = $wpdb->get_var("SHOW COLUMNS FROM {$prefix}companies LIKE 'customer_code'");
        if (!$col_customer_code) {
            $wpdb->query("ALTER TABLE {$prefix}companies ADD COLUMN customer_code VARCHAR(50) NOT NULL DEFAULT '' AFTER name");
            $wpdb->query("ALTER TABLE {$prefix}companies ADD KEY customer_code (customer_code)");
            $wpdb->query("ALTER TABLE {$prefix}companies ADD COLUMN naf_code VARCHAR(10) NOT NULL DEFAULT '' AFTER vat_number");
            $wpdb->query("ALTER TABLE {$prefix}companies ADD COLUMN phone VARCHAR(30) NOT NULL DEFAULT '' AFTER shipping_address");
            $wpdb->query("ALTER TABLE {$prefix}companies ADD COLUMN fax VARCHAR(30) NOT NULL DEFAULT '' AFTER phone");
            $wpdb->query("ALTER TABLE {$prefix}companies ADD COLUMN contact_function VARCHAR(100) NOT NULL DEFAULT '' AFTER fax");
            $wpdb->query("ALTER TABLE {$prefix}companies ADD COLUMN contact_first_name VARCHAR(100) NOT NULL DEFAULT '' AFTER contact_function");
            $wpdb->query("ALTER TABLE {$prefix}companies ADD COLUMN contact_last_name VARCHAR(100) NOT NULL DEFAULT '' AFTER contact_first_name");
            $wpdb->query("ALTER TABLE {$prefix}companies ADD COLUMN payment_method_code VARCHAR(50) NOT NULL DEFAULT '' AFTER payment_terms");
            $wpdb->query("ALTER TABLE {$prefix}companies ADD COLUMN payment_method_label VARCHAR(255) NOT NULL DEFAULT '' AFTER payment_method_code");
            $wpdb->query("ALTER TABLE {$prefix}companies ADD COLUMN category VARCHAR(100) NOT NULL DEFAULT '' AFTER payment_method_label");
            $wpdb->query("ALTER TABLE {$prefix}companies ADD COLUMN activity VARCHAR(255) NOT NULL DEFAULT '' AFTER category");
            $wpdb->query("ALTER TABLE {$prefix}companies ADD COLUMN comments TEXT NULL AFTER activity");
        }

        // Migrate: add client_id column if missing (v1.7.0) — rattachement d'une société
        // à une fiche client indépendante (le client peut exister sans société).
        $col_client_id = $wpdb->get_var("SHOW COLUMNS FROM {$prefix}companies LIKE 'client_id'");
        if (!$col_client_id) {
            $wpdb->query("ALTER TABLE {$prefix}companies ADD COLUMN client_id BIGINT UNSIGNED DEFAULT NULL AFTER id");
            $wpdb->query("ALTER TABLE {$prefix}companies ADD KEY client_id (client_id)");
        }

        self::update_db_version();
    }

    public static function get_db_version(): string {
        return (string) get_option('pe_db_version', '0.0.0');
    }

    public static function update_db_version(): void {
        update_option('pe_db_version', PE_DB_VERSION, false);
    }
}

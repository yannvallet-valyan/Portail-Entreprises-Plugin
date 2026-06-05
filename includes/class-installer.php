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

        $charset_collate = "DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        $prefix = $wpdb->prefix . 'b2b_';

        // Table companies
        $sql_companies = "CREATE TABLE {$prefix}companies (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            siret VARCHAR(14) NOT NULL DEFAULT '',
            vat_number VARCHAR(20) NOT NULL DEFAULT '',
            billing_address LONGTEXT NOT NULL DEFAULT '{}',
            discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_terms INT UNSIGNED NOT NULL DEFAULT 30,
            assigned_rep_id BIGINT UNSIGNED DEFAULT NULL,
            status ENUM('active','suspended') NOT NULL DEFAULT 'active',
            modules_enabled LONGTEXT NOT NULL DEFAULT '[]',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY assigned_rep_id (assigned_rep_id)
        ) ENGINE=InnoDB {$charset_collate};";

        // Table agencies
        $sql_agencies = "CREATE TABLE {$prefix}agencies (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            address LONGTEXT NOT NULL DEFAULT '{}',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY company_id (company_id)
        ) ENGINE=InnoDB {$charset_collate};";

        // Table user_company
        $sql_user_company = "CREATE TABLE {$prefix}user_company (
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
        $sql_cost_centers = "CREATE TABLE {$prefix}cost_centers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            code VARCHAR(50) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY company_id (company_id)
        ) ENGINE=InnoDB {$charset_collate};";

        // Table budget_usage
        $sql_budget_usage = "CREATE TABLE {$prefix}budget_usage (
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
        $sql_approval_rules = "CREATE TABLE {$prefix}approval_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            agency_id BIGINT UNSIGNED DEFAULT NULL,
            threshold_min DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            threshold_max DECIMAL(12,2) DEFAULT NULL,
            approver_roles LONGTEXT NOT NULL DEFAULT '[]',
            delay_hours INT UNSIGNED NOT NULL DEFAULT 24,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY company_id (company_id),
            KEY agency_id (agency_id)
        ) ENGINE=InnoDB {$charset_collate};";

        // Table approval_requests
        $sql_approval_requests = "CREATE TABLE {$prefix}approval_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            company_id BIGINT UNSIGNED NOT NULL,
            requester_id BIGINT UNSIGNED NOT NULL,
            approver_id BIGINT UNSIGNED DEFAULT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            cost_center_id BIGINT UNSIGNED DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY company_id (company_id),
            KEY requester_id (requester_id),
            KEY approver_id (approver_id),
            KEY status (status)
        ) ENGINE=InnoDB {$charset_collate};";

        // Table audit_log
        $sql_audit_log = "CREATE TABLE {$prefix}audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            company_id BIGINT UNSIGNED DEFAULT NULL,
            action VARCHAR(100) NOT NULL,
            object_type VARCHAR(100) NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            data LONGTEXT NOT NULL DEFAULT '{}',
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY company_id (company_id),
            KEY action (action),
            KEY object_type (object_type),
            KEY created_at (created_at)
        ) ENGINE=InnoDB {$charset_collate};";

        dbDelta($sql_companies);
        dbDelta($sql_agencies);
        dbDelta($sql_user_company);
        dbDelta($sql_cost_centers);
        dbDelta($sql_budget_usage);
        dbDelta($sql_approval_rules);
        dbDelta($sql_approval_requests);
        dbDelta($sql_audit_log);

        self::update_db_version();
    }

    public static function get_db_version(): string {
        return (string) get_option('pe_db_version', '0.0.0');
    }

    public static function update_db_version(): void {
        update_option('pe_db_version', PE_DB_VERSION, false);
    }
}

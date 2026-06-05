<?php
/**
 * Journal d'audit B2B.
 */

defined('ABSPATH') || exit;

class PE_Audit_Log {

    private static ?PE_Audit_Log $instance = null;

    private function __construct() {}

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Enregistre une action dans le journal d'audit.
     */
    public function log(
        int $user_id,
        ?int $company_id,
        string $action,
        string $object_type,
        int $object_id,
        array $data = []
    ): void {
        global $wpdb;

        $ip_raw = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        $wpdb->insert(
            $wpdb->prefix . 'b2b_audit_log',
            [
                'user_id'     => $user_id,
                'company_id'  => $company_id,
                'action'      => substr($action, 0, 100),
                'object_type' => substr($object_type, 0, 100),
                'object_id'   => $object_id,
                'data'        => wp_json_encode($data),
                'ip_address'  => substr($ip_raw, 0, 45),
                'created_at'  => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );
    }

    /**
     * Récupère les entrées du journal avec filtres optionnels.
     *
     * @param array{
     *   company_id?: int,
     *   user_id?: int,
     *   action?: string,
     *   date_from?: string,
     *   date_to?: string,
     *   per_page?: int,
     *   page?: int
     * } $filters
     */
    public function get_logs(array $filters = []): array {
        global $wpdb;

        $where  = [];
        $params = [];

        if (!empty($filters['company_id'])) {
            $where[]  = 'company_id = %d';
            $params[] = (int) $filters['company_id'];
        }

        if (!empty($filters['user_id'])) {
            $where[]  = 'user_id = %d';
            $params[] = (int) $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $where[]  = 'action = %s';
            $params[] = $filters['action'];
        }

        if (!empty($filters['date_from'])) {
            $where[]  = 'created_at >= %s';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[]  = 'created_at <= %s';
            $params[] = $filters['date_to'];
        }

        $per_page = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 50;
        $page     = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;
        $offset   = ($page - 1) * $per_page;

        $sql = "SELECT * FROM {$wpdb->prefix}b2b_audit_log";

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY created_at DESC LIMIT %d OFFSET %d';

        $params[] = $per_page;
        $params[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($wpdb->prepare($sql, $params)) ?: [];
    }

    /**
     * Compte le total des entrées avec les mêmes filtres.
     */
    public function count_logs(array $filters = []): int {
        global $wpdb;

        $where  = [];
        $params = [];

        if (!empty($filters['company_id'])) {
            $where[]  = 'company_id = %d';
            $params[] = (int) $filters['company_id'];
        }

        if (!empty($filters['user_id'])) {
            $where[]  = 'user_id = %d';
            $params[] = (int) $filters['user_id'];
        }

        if (!empty($filters['action'])) {
            $where[]  = 'action = %s';
            $params[] = $filters['action'];
        }

        if (!empty($filters['date_from'])) {
            $where[]  = 'created_at >= %s';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[]  = 'created_at <= %s';
            $params[] = $filters['date_to'];
        }

        $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}b2b_audit_log";

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        if (!empty($params)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var($sql);
    }
}

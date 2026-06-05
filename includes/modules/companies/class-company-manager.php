<?php
/**
 * Gestionnaire des entreprises B2B.
 */

defined('ABSPATH') || exit;

class PE_Company_Manager {

    private static ?PE_Company_Manager $instance = null;

    private function __construct() {}

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Crée une nouvelle entreprise.
     *
     * @param array $data Données de l'entreprise.
     * @return int|WP_Error ID de l'entreprise créée ou erreur.
     */
    public function create_company(array $data): int|\WP_Error {
        global $wpdb;

        if (empty($data['name'])) {
            return new \WP_Error('missing_name', __('Le nom de l\'entreprise est requis.', 'portail-entreprises'));
        }

        $insert_data = [
            'name'            => sanitize_text_field($data['name']),
            'siret'           => sanitize_text_field($data['siret'] ?? ''),
            'vat_number'      => sanitize_text_field($data['vat_number'] ?? ''),
            'billing_address' => wp_json_encode($data['billing_address'] ?? []),
            'discount_rate'   => (float) ($data['discount_rate'] ?? 0),
            'credit_limit'    => (float) ($data['credit_limit'] ?? 0),
            'payment_terms'   => (int) ($data['payment_terms'] ?? 30),
            'status'          => in_array($data['status'] ?? 'active', ['active', 'suspended'], true) ? $data['status'] : 'active',
            'modules_enabled' => wp_json_encode($data['modules_enabled'] ?? []),
        ];
        $formats = ['%s', '%s', '%s', '%s', '%f', '%f', '%d', '%s', '%s'];

        // assigned_rep_id est nullable — on l'insère seulement si fourni pour éviter NULL+%d.
        if (!empty($data['assigned_rep_id'])) {
            $insert_data['assigned_rep_id'] = (int) $data['assigned_rep_id'];
            $formats[]                       = '%d';
        }

        $result = $wpdb->insert(
            $wpdb->prefix . 'b2b_companies',
            $insert_data,
            $formats
        );

        if (false === $result) {
            $db_error = $wpdb->last_error ?: __('Erreur inconnue.', 'portail-entreprises');
            return new \WP_Error('db_error', sprintf(
                /* translators: %s: database error message */
                __('Erreur lors de la création de l\'entreprise : %s', 'portail-entreprises'),
                $db_error
            ));
        }

        $company_id = (int) $wpdb->insert_id;

        PE_Audit_Log::get_instance()->log(
            get_current_user_id(),
            $company_id,
            'create_company',
            'company',
            $company_id,
            ['name' => $insert_data['name']]
        );

        return $company_id;
    }

    /**
     * Récupère une entreprise par son ID.
     */
    public function get_company(int $id): ?object {
        global $wpdb;

        $cache_key = 'pe_company_' . $id;
        $cached = wp_cache_get($cache_key, 'portail-entreprises');

        if (false !== $cached) {
            return $cached ?: null;
        }

        $company = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}b2b_companies WHERE id = %d LIMIT 1",
                $id
            )
        );

        wp_cache_set($cache_key, $company ?? false, 'portail-entreprises', 300);

        return $company ?: null;
    }

    /**
     * Récupère l'entreprise d'un utilisateur.
     */
    public function get_company_by_user(int $user_id): ?object {
        return PE_Permissions::get_user_company($user_id);
    }

    /**
     * Met à jour une entreprise.
     */
    public function update_company(int $id, array $data): bool {
        global $wpdb;

        $update_data   = [];
        $update_format = [];

        if (isset($data['name'])) {
            $update_data['name'] = sanitize_text_field($data['name']);
            $update_format[]     = '%s';
        }
        if (isset($data['siret'])) {
            $update_data['siret'] = sanitize_text_field($data['siret']);
            $update_format[]      = '%s';
        }
        if (isset($data['vat_number'])) {
            $update_data['vat_number'] = sanitize_text_field($data['vat_number']);
            $update_format[]           = '%s';
        }
        if (isset($data['billing_address'])) {
            $update_data['billing_address'] = wp_json_encode($data['billing_address']);
            $update_format[]                = '%s';
        }
        if (isset($data['discount_rate'])) {
            $update_data['discount_rate'] = (float) $data['discount_rate'];
            $update_format[]              = '%f';
        }
        if (isset($data['credit_limit'])) {
            $update_data['credit_limit'] = (float) $data['credit_limit'];
            $update_format[]             = '%f';
        }
        if (isset($data['payment_terms'])) {
            $update_data['payment_terms'] = (int) $data['payment_terms'];
            $update_format[]              = '%d';
        }
        if (array_key_exists('assigned_rep_id', $data)) {
            $update_data['assigned_rep_id'] = !empty($data['assigned_rep_id']) ? (int) $data['assigned_rep_id'] : null;
            $update_format[]                = '%d';
        }
        if (isset($data['status']) && in_array($data['status'], ['active', 'suspended'], true)) {
            $update_data['status'] = $data['status'];
            $update_format[]       = '%s';
        }
        if (isset($data['modules_enabled'])) {
            $update_data['modules_enabled'] = wp_json_encode($data['modules_enabled']);
            $update_format[]                = '%s';
        }

        if (empty($update_data)) {
            return false;
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'b2b_companies',
            $update_data,
            ['id' => $id],
            $update_format,
            ['%d']
        );

        if (false !== $result) {
            wp_cache_delete('pe_company_' . $id, 'portail-entreprises');

            PE_Audit_Log::get_instance()->log(
                get_current_user_id(),
                $id,
                'update_company',
                'company',
                $id,
                $update_data
            );
        }

        return false !== $result;
    }

    /**
     * Récupère les utilisateurs d'une entreprise.
     */
    public function get_company_users(int $company_id): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT uc.*, u.user_email, u.display_name
                 FROM {$wpdb->prefix}b2b_user_company uc
                 INNER JOIN {$wpdb->users} u ON u.ID = uc.user_id
                 WHERE uc.company_id = %d
                 ORDER BY u.display_name ASC",
                $company_id
            )
        ) ?: [];
    }

    /**
     * Ajoute un utilisateur à une entreprise.
     */
    public function add_user_to_company(int $user_id, int $company_id, string $role, array $opts = []): bool {
        global $wpdb;

        $allowed_roles = array_keys(PE_Permissions::get_roles());
        if (!in_array($role, $allowed_roles, true)) {
            return false;
        }

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}b2b_user_company WHERE user_id = %d AND company_id = %d LIMIT 1",
                $user_id,
                $company_id
            )
        );

        $insert_data = [
            'user_id'          => $user_id,
            'company_id'       => $company_id,
            'agency_id'        => !empty($opts['agency_id']) ? (int) $opts['agency_id'] : null,
            'role'             => $role,
            'budget_monthly'   => isset($opts['budget_monthly']) ? (float) $opts['budget_monthly'] : null,
            'budget_annual'    => isset($opts['budget_annual']) ? (float) $opts['budget_annual'] : null,
            'budget_per_order' => isset($opts['budget_per_order']) ? (float) $opts['budget_per_order'] : null,
            'is_primary'       => (int) ($opts['is_primary'] ?? 1),
        ];

        if ($existing) {
            $result = $wpdb->update(
                $wpdb->prefix . 'b2b_user_company',
                $insert_data,
                ['user_id' => $user_id, 'company_id' => $company_id],
                ['%d', '%d', '%d', '%s', '%f', '%f', '%f', '%d'],
                ['%d', '%d']
            );
        } else {
            $result = $wpdb->insert(
                $wpdb->prefix . 'b2b_user_company',
                $insert_data,
                ['%d', '%d', '%d', '%s', '%f', '%f', '%f', '%d']
            );
        }

        if (false !== $result) {
            PE_Permissions::flush_user_cache($user_id);

            PE_Audit_Log::get_instance()->log(
                get_current_user_id(),
                $company_id,
                'add_user_to_company',
                'user',
                $user_id,
                ['role' => $role]
            );
        }

        return false !== $result;
    }

    /**
     * Retire un utilisateur d'une entreprise.
     */
    public function remove_user_from_company(int $user_id, int $company_id): bool {
        global $wpdb;

        $result = $wpdb->delete(
            $wpdb->prefix . 'b2b_user_company',
            ['user_id' => $user_id, 'company_id' => $company_id],
            ['%d', '%d']
        );

        if (false !== $result) {
            PE_Permissions::flush_user_cache($user_id);

            PE_Audit_Log::get_instance()->log(
                get_current_user_id(),
                $company_id,
                'remove_user_from_company',
                'user',
                $user_id,
                []
            );
        }

        return false !== $result;
    }

    /**
     * Récupère les agences d'une entreprise.
     */
    public function get_agencies(int $company_id): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}b2b_agencies WHERE company_id = %d ORDER BY name ASC",
                $company_id
            )
        ) ?: [];
    }

    /**
     * Crée une agence pour une entreprise.
     */
    public function create_agency(int $company_id, string $name, array $address): int {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'b2b_agencies',
            [
                'company_id' => $company_id,
                'name'       => sanitize_text_field($name),
                'address'    => wp_json_encode($address),
            ],
            ['%d', '%s', '%s']
        );

        $agency_id = (int) $wpdb->insert_id;

        if ($agency_id > 0) {
            PE_Audit_Log::get_instance()->log(
                get_current_user_id(),
                $company_id,
                'create_agency',
                'agency',
                $agency_id,
                ['name' => $name]
            );
        }

        return $agency_id;
    }

    /**
     * Récupère toutes les entreprises (pour l'admin).
     */
    public function get_all_companies(array $args = []): array {
        global $wpdb;

        $per_page = isset($args['per_page']) ? max(1, (int) $args['per_page']) : 20;
        $page     = isset($args['page']) ? max(1, (int) $args['page']) : 1;
        $offset   = ($page - 1) * $per_page;
        $status   = $args['status'] ?? '';
        $search   = $args['search'] ?? '';

        $where  = [];
        $params = [];

        if ($status && in_array($status, ['active', 'suspended'], true)) {
            $where[]  = 'status = %s';
            $params[] = $status;
        }

        if ($search) {
            $where[]  = '(name LIKE %s OR siret LIKE %s)';
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT c.*, (SELECT COUNT(*) FROM {$wpdb->prefix}b2b_user_company uc WHERE uc.company_id = c.id) as user_count
                FROM {$wpdb->prefix}b2b_companies c";

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY c.name ASC LIMIT %d OFFSET %d';

        $params[] = $per_page;
        $params[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results($wpdb->prepare($sql, $params)) ?: [];
    }

    /**
     * Compte le total des entreprises.
     */
    public function count_companies(array $args = []): int {
        global $wpdb;

        $status = $args['status'] ?? '';
        $search = $args['search'] ?? '';
        $where  = [];
        $params = [];

        if ($status && in_array($status, ['active', 'suspended'], true)) {
            $where[]  = 'status = %s';
            $params[] = $status;
        }

        if ($search) {
            $where[]  = '(name LIKE %s OR siret LIKE %s)';
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}b2b_companies";

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

    /**
     * Récupère les centres de coût d'une entreprise.
     */
    public function get_cost_centers(int $company_id): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}b2b_cost_centers WHERE company_id = %d ORDER BY name ASC",
                $company_id
            )
        ) ?: [];
    }

    /**
     * Crée un centre de coût.
     */
    public function create_cost_center(int $company_id, string $name, string $code = ''): int {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'b2b_cost_centers',
            [
                'company_id' => $company_id,
                'name'       => sanitize_text_field($name),
                'code'       => sanitize_text_field($code),
            ],
            ['%d', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Récupère les règles d'approbation d'une entreprise.
     */
    public function get_approval_rules(int $company_id): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}b2b_approval_rules WHERE company_id = %d ORDER BY threshold_min ASC",
                $company_id
            )
        ) ?: [];
    }

    /**
     * Crée ou met à jour une règle d'approbation.
     */
    public function save_approval_rule(int $company_id, array $rule_data): int|\WP_Error {
        global $wpdb;

        $data = [
            'company_id'     => $company_id,
            'agency_id'      => !empty($rule_data['agency_id']) ? (int) $rule_data['agency_id'] : null,
            'threshold_min'  => (float) ($rule_data['threshold_min'] ?? 0),
            'threshold_max'  => isset($rule_data['threshold_max']) ? (float) $rule_data['threshold_max'] : null,
            'approver_roles' => wp_json_encode($rule_data['approver_roles'] ?? []),
            'delay_hours'    => (int) ($rule_data['delay_hours'] ?? 24),
        ];

        $format = ['%d', '%d', '%f', '%f', '%s', '%d'];

        if (!empty($rule_data['id'])) {
            $result = $wpdb->update(
                $wpdb->prefix . 'b2b_approval_rules',
                $data,
                ['id' => (int) $rule_data['id']],
                $format,
                ['%d']
            );
            return false !== $result ? (int) $rule_data['id'] : new \WP_Error('db_error', __('Erreur de mise à jour.', 'portail-entreprises'));
        }

        $result = $wpdb->insert($wpdb->prefix . 'b2b_approval_rules', $data, $format);
        return false !== $result ? (int) $wpdb->insert_id : new \WP_Error('db_error', __('Erreur de création.', 'portail-entreprises'));
    }

    /**
     * Supprime une règle d'approbation.
     */
    public function delete_approval_rule(int $rule_id, int $company_id): bool {
        global $wpdb;

        $result = $wpdb->delete(
            $wpdb->prefix . 'b2b_approval_rules',
            ['id' => $rule_id, 'company_id' => $company_id],
            ['%d', '%d']
        );

        return false !== $result;
    }
}

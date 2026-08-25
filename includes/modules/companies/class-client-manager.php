<?php
/**
 * Gestionnaire des clients B2B.
 *
 * Un client est une identité indépendante des sociétés : il peut exister
 * (code client, coordonnées) avant même qu'une société lui soit rattachée.
 * Une ou plusieurs sociétés (b2b_companies) peuvent ensuite être créées et
 * liées à un même client via companies.client_id.
 */

defined('ABSPATH') || exit;

class PE_Client_Manager {

    private static ?PE_Client_Manager $instance = null;

    private function __construct() {}

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Crée un nouveau client.
     *
     * @param array $data Données du client.
     * @return int|WP_Error ID du client créé ou erreur.
     */
    public function create_client(array $data): int|\WP_Error {
        global $wpdb;

        if (empty($data['name'])) {
            return new \WP_Error('missing_name', __('Le libellé du client est requis.', 'portail-entreprises'));
        }

        $insert_data = [
            'customer_code'         => sanitize_text_field($data['customer_code'] ?? ''),
            'name'                  => sanitize_text_field($data['name']),
            'billing_address'       => wp_json_encode($data['billing_address'] ?? []),
            'shipping_address'      => wp_json_encode($data['shipping_address'] ?? []),
            'phone'                 => sanitize_text_field($data['phone'] ?? ''),
            'fax'                   => sanitize_text_field($data['fax'] ?? ''),
            'contact_function'      => sanitize_text_field($data['contact_function'] ?? ''),
            'contact_first_name'    => sanitize_text_field($data['contact_first_name'] ?? ''),
            'contact_last_name'     => sanitize_text_field($data['contact_last_name'] ?? ''),
            'discount_rate'         => (float) ($data['discount_rate'] ?? 0),
            'credit_limit'          => (float) ($data['credit_limit'] ?? 0),
            'payment_terms'         => (int) ($data['payment_terms'] ?? 30),
            'payment_method_code'   => sanitize_text_field($data['payment_method_code'] ?? ''),
            'payment_method_label'  => sanitize_text_field($data['payment_method_label'] ?? ''),
            'category'              => sanitize_text_field($data['category'] ?? ''),
            'activity'              => sanitize_text_field($data['activity'] ?? ''),
            'comments'              => sanitize_textarea_field($data['comments'] ?? ''),
        ];
        $formats = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%d', '%s', '%s', '%s', '%s', '%s'];

        $result = $wpdb->insert($wpdb->prefix . 'b2b_clients', $insert_data, $formats);

        if (false === $result) {
            $db_error = $wpdb->last_error ?: __('Erreur inconnue.', 'portail-entreprises');
            return new \WP_Error('db_error', sprintf(
                /* translators: %s: database error message */
                __('Erreur lors de la création du client : %s', 'portail-entreprises'),
                $db_error
            ));
        }

        $client_id = (int) $wpdb->insert_id;

        PE_Audit_Log::get_instance()->log(
            get_current_user_id(),
            null,
            'create_client',
            'client',
            $client_id,
            ['name' => $insert_data['name']]
        );

        return $client_id;
    }

    /**
     * Récupère un client par son ID.
     */
    public function get_client(int $id): ?object {
        global $wpdb;

        $client = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}b2b_clients WHERE id = %d LIMIT 1", $id)
        );

        return $client ?: null;
    }

    /**
     * Met à jour un client.
     */
    public function update_client(int $id, array $data): bool {
        global $wpdb;

        $update_data   = [];
        $update_format = [];

        $string_fields = [
            'customer_code', 'name', 'phone', 'fax', 'contact_function',
            'contact_first_name', 'contact_last_name', 'payment_method_code',
            'payment_method_label', 'category', 'activity',
        ];
        foreach ($string_fields as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = sanitize_text_field($data[$field]);
                $update_format[]     = '%s';
            }
        }

        if (isset($data['comments'])) {
            $update_data['comments'] = sanitize_textarea_field($data['comments']);
            $update_format[]         = '%s';
        }
        if (isset($data['billing_address'])) {
            $update_data['billing_address'] = wp_json_encode($data['billing_address']);
            $update_format[]                = '%s';
        }
        if (isset($data['shipping_address'])) {
            $update_data['shipping_address'] = wp_json_encode($data['shipping_address']);
            $update_format[]                 = '%s';
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

        if (empty($update_data)) {
            return false;
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'b2b_clients',
            $update_data,
            ['id' => $id],
            $update_format,
            ['%d']
        );

        if (false !== $result) {
            PE_Audit_Log::get_instance()->log(
                get_current_user_id(),
                null,
                'update_client',
                'client',
                $id,
                []
            );
        }

        return false !== $result;
    }

    /**
     * Supprime un client. Refuse la suppression si des sociétés y sont encore rattachées.
     */
    public function delete_client(int $client_id): bool|\WP_Error {
        global $wpdb;

        $client = $this->get_client($client_id);
        if (!$client) {
            return new \WP_Error('not_found', __('Client introuvable.', 'portail-entreprises'));
        }

        $companies_count = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}b2b_companies WHERE client_id = %d", $client_id)
        );

        if ($companies_count > 0) {
            return new \WP_Error(
                'has_companies',
                __('Ce client a encore des sociétés rattachées : détachez-les d\'abord.', 'portail-entreprises')
            );
        }

        $result = $wpdb->delete($wpdb->prefix . 'b2b_clients', ['id' => $client_id], ['%d']);

        if (false === $result || 0 === $result) {
            return new \WP_Error('db_error', __('Erreur lors de la suppression du client.', 'portail-entreprises'));
        }

        PE_Audit_Log::get_instance()->log(
            get_current_user_id(),
            null,
            'delete_client',
            'client',
            $client_id,
            ['name' => $client->name]
        );

        return true;
    }

    /**
     * Récupère tous les clients (pour l'admin).
     */
    public function get_all_clients(array $args = []): array {
        global $wpdb;

        $per_page = isset($args['per_page']) ? max(1, (int) $args['per_page']) : 20;
        $page     = isset($args['page']) ? max(1, (int) $args['page']) : 1;
        $offset   = ($page - 1) * $per_page;
        $search   = $args['search'] ?? '';

        $where  = [];
        $params = [];

        if ($search) {
            $where[]  = '(name LIKE %s OR customer_code LIKE %s)';
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT c.*, (SELECT COUNT(*) FROM {$wpdb->prefix}b2b_companies co WHERE co.client_id = c.id) as company_count
                FROM {$wpdb->prefix}b2b_clients c";

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
     * Compte le total des clients.
     */
    public function count_clients(array $args = []): int {
        global $wpdb;

        $search = $args['search'] ?? '';
        $where  = [];
        $params = [];

        if ($search) {
            $where[]  = '(name LIKE %s OR customer_code LIKE %s)';
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}b2b_clients";

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
     * Recherche des clients (nom ou code client) — utilisé pour rattacher une société
     * à un client existant, qu'il ait déjà une société ou non.
     */
    public function search_clients(string $search, int $limit = 10): array {
        global $wpdb;

        $like = '%' . $wpdb->esc_like($search) . '%';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, customer_code
                 FROM {$wpdb->prefix}b2b_clients
                 WHERE name LIKE %s OR customer_code LIKE %s
                 ORDER BY name ASC
                 LIMIT %d",
                $like,
                $like,
                $limit
            )
        ) ?: [];
    }

    /**
     * Récupère les sociétés rattachées à un client.
     */
    public function get_client_companies(int $client_id): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}b2b_companies WHERE client_id = %d ORDER BY name ASC",
                $client_id
            )
        ) ?: [];
    }
}

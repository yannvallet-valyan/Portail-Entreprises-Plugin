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

    /** Cache de la remise entreprise de l'utilisateur courant (évite les requêtes répétées). */
    private ?float $current_user_discount = null;
    private bool $discount_resolved = false;
    /** Bypass temporaire du filtre de prix B2B (pour lire le prix externe). */
    private bool $bypass_discount = false;

    /**
     * Branche les filtres de prix B2B.
     * Priorité tardive (999) pour s'exécuter APRÈS les plugins de remise tiers
     * (ex : Taxonomy/Term and Role-based Discounts) : si une remise existe déjà,
     * la remise entreprise B2B ne s'applique pas.
     */
    public function init_pricing(): void {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }
        add_filter('woocommerce_product_get_price', [$this, 'apply_b2b_discount'], 999, 2);
        add_filter('woocommerce_product_variation_get_price', [$this, 'apply_b2b_discount'], 999, 2);
        add_filter('woocommerce_variation_prices_price', [$this, 'apply_b2b_discount_variation'], 999, 3);

        // Inclure la remise dans le hash de cache des prix de variation (prix par utilisateur).
        add_filter('woocommerce_get_variation_prices_hash', [$this, 'add_discount_to_price_hash'], 999);
    }

    /**
     * Ajoute la remise B2B au hash de cache des prix de variation.
     */
    public function add_discount_to_price_hash(array $hash): array {
        $rate = $this->get_current_user_discount_rate();
        if ($rate > 0) {
            $hash['pe_b2b_discount'] = $rate;
        }
        return $hash;
    }

    /* =========================================================
       API PUBLIQUE — intégration plugins tiers (ex : module de devis)
       ========================================================= */

    /**
     * Taux de remise (%) de l'entreprise de l'utilisateur courant (ou indiqué), ou 0.
     * À utiliser par un plugin de devis pour afficher la remise en ligne séparée.
     *
     * @param int|null $user_id Utilisateur cible. Null = utilisateur courant.
     */
    public function get_discount_rate(?int $user_id = null): float {
        if (null === $user_id) {
            return $this->get_current_user_discount_rate();
        }
        if (!class_exists('PE_Permissions')) {
            return 0.0;
        }
        $company = PE_Permissions::get_user_company($user_id);
        return ($company && (float) $company->discount_rate > 0) ? (float) $company->discount_rate : 0.0;
    }

    /**
     * Calcule la remise B2B applicable à un produit, en respectant la règle
     * « pas de remise B2B si une remise existe déjà ».
     *
     * Retourne un tableau prêt à l'emploi pour afficher une LIGNE DE REMISE séparée :
     *   [
     *     'applies'         => bool,   // true si la remise B2B s'applique
     *     'rate'            => float,  // taux en %
     *     'regular_price'   => float,  // prix régulier (avant remise)
     *     'discounted_price'=> float,  // prix après remise B2B
     *     'discount_amount' => float,  // montant remisé par unité (regular - discounted)
     *   ]
     *
     * @param \WC_Product $product Produit (ou variation).
     * @param int|null    $user_id Utilisateur cible. Null = utilisateur courant.
     */
    public function get_product_discount(\WC_Product $product, ?int $user_id = null): array {
        $rate    = $this->get_discount_rate($user_id);
        $regular = (float) $product->get_regular_price();
        $result  = [
            'applies'          => false,
            'rate'             => $rate,
            'regular_price'    => $regular,
            'discounted_price' => $regular,
            'discount_amount'  => 0.0,
        ];

        if ($rate <= 0 || $regular <= 0) {
            return $result;
        }

        // Prix actif tel que calculé par WooCommerce + plugins tiers (Taxonomy Discount, promos…),
        // MAIS sans notre propre remise B2B (bypass temporaire).
        $this->bypass_discount = true;
        $external = (float) $product->get_price();
        $this->bypass_discount = false;

        // Une remise est déjà active (promo ou plugin tiers) → pas de remise B2B.
        if ($external > 0 && $external < $regular - 0.0001) {
            $result['discounted_price'] = $external;
            return $result;
        }

        $discounted = round($regular * (1 - $rate / 100), wc_get_price_decimals());
        $result['applies']          = true;
        $result['discounted_price'] = $discounted;
        $result['discount_amount']  = round($regular - $discounted, wc_get_price_decimals());
        return $result;
    }

    /**
     * Retourne le taux de remise (%) de l'entreprise de l'utilisateur courant, ou 0.
     */
    private function get_current_user_discount_rate(): float {
        if ($this->discount_resolved) {
            return (float) $this->current_user_discount;
        }
        $this->discount_resolved = true;
        $this->current_user_discount = 0.0;

        $user_id = get_current_user_id();
        if (!$user_id || !class_exists('PE_Permissions')) {
            return 0.0;
        }
        $company = PE_Permissions::get_user_company($user_id);
        if ($company && (float) $company->discount_rate > 0) {
            $this->current_user_discount = (float) $company->discount_rate;
        }
        return (float) $this->current_user_discount;
    }

    /**
     * Applique la remise entreprise sur le prix d'un produit simple/variation.
     * Ne s'applique que si aucune remise n'est déjà active (prix == prix régulier).
     *
     * @param mixed       $price   Prix courant (string|float).
     * @param \WC_Product $product Produit.
     * @return mixed
     */
    public function apply_b2b_discount($price, $product) {
        if ($this->bypass_discount || '' === $price || null === $price) {
            return $price;
        }
        $rate = $this->get_current_user_discount_rate();
        if ($rate <= 0) {
            return $price;
        }

        $regular = (float) $product->get_regular_price();
        $current = (float) $price;
        if ($regular <= 0) {
            return $price;
        }

        // Une remise existe déjà (promo WooCommerce ou plugin tiers) → ne pas écraser.
        if ($current < $regular - 0.0001) {
            return $price;
        }

        return round($regular * (1 - $rate / 100), wc_get_price_decimals());
    }

    /**
     * Variante pour le filtre woocommerce_variation_prices_price (3 args).
     */
    public function apply_b2b_discount_variation($price, $variation, $product) {
        return $this->apply_b2b_discount($price, $variation);
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
     * Supprime une entreprise et toutes ses données associées.
     *
     * @param int $company_id ID de l'entreprise.
     * @return bool|WP_Error
     */
    public function delete_company(int $company_id): bool|\WP_Error {
        global $wpdb;

        $company = $this->get_company($company_id);
        if (!$company) {
            return new \WP_Error('not_found', __('Société introuvable.', 'portail-entreprises'));
        }

        $prefix = $wpdb->prefix . 'b2b_';

        // Détacher les utilisateurs (retirer le flag B2B des utilisateurs rattachés).
        $user_ids = $wpdb->get_col(
            $wpdb->prepare("SELECT user_id FROM {$prefix}user_company WHERE company_id = %d", $company_id)
        );
        foreach ($user_ids as $uid) {
            PE_Permissions::flush_user_cache((int) $uid);
        }

        // Supprimer toutes les données liées à l'entreprise.
        $wpdb->delete($prefix . 'user_company',       ['company_id' => $company_id], ['%d']);
        $wpdb->delete($prefix . 'agencies',           ['company_id' => $company_id], ['%d']);
        $wpdb->delete($prefix . 'cost_centers',       ['company_id' => $company_id], ['%d']);
        $wpdb->delete($prefix . 'budget_usage',       ['company_id' => $company_id], ['%d']);
        $wpdb->delete($prefix . 'approval_rules',     ['company_id' => $company_id], ['%d']);
        $wpdb->delete($prefix . 'approval_requests',  ['company_id' => $company_id], ['%d']);

        $result = $wpdb->delete($prefix . 'companies', ['id' => $company_id], ['%d']);

        if (false === $result || 0 === $result) {
            return new \WP_Error('db_error', __('Erreur lors de la suppression de la société.', 'portail-entreprises'));
        }

        wp_cache_delete('pe_company_' . $company_id, 'portail-entreprises');

        PE_Audit_Log::get_instance()->log(
            get_current_user_id(),
            $company_id,
            'delete_company',
            'company',
            $company_id,
            ['name' => $company->name]
        );

        return true;
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

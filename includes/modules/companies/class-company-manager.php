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
            'name'                  => sanitize_text_field($data['name']),
            'customer_code'         => sanitize_text_field($data['customer_code'] ?? ''),
            'siret'                 => sanitize_text_field($data['siret'] ?? ''),
            'vat_number'            => sanitize_text_field($data['vat_number'] ?? ''),
            'naf_code'              => sanitize_text_field($data['naf_code'] ?? ''),
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
            'status'                => in_array($data['status'] ?? 'active', ['active', 'suspended'], true) ? $data['status'] : 'active',
            'modules_enabled'       => wp_json_encode($data['modules_enabled'] ?? []),
        ];
        $formats = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

        // assigned_rep_id est nullable — on l'insère seulement si fourni pour éviter NULL+%d.
        if (!empty($data['assigned_rep_id'])) {
            $insert_data['assigned_rep_id'] = (int) $data['assigned_rep_id'];
            $formats[]                       = '%d';
        }

        // tdw_profile_slug : profil de remise TDW optionnel.
        if (array_key_exists('tdw_profile_slug', $data)) {
            $insert_data['tdw_profile_slug'] = !empty($data['tdw_profile_slug']) ? sanitize_key((string) $data['tdw_profile_slug']) : null;
            $formats[] = '%s';
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

        do_action( 'pe_company_created', $company_id );

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
        if (isset($data['customer_code'])) {
            $update_data['customer_code'] = sanitize_text_field($data['customer_code']);
            $update_format[]              = '%s';
        }
        if (isset($data['siret'])) {
            $update_data['siret'] = sanitize_text_field($data['siret']);
            $update_format[]      = '%s';
        }
        if (isset($data['vat_number'])) {
            $update_data['vat_number'] = sanitize_text_field($data['vat_number']);
            $update_format[]           = '%s';
        }
        if (isset($data['naf_code'])) {
            $update_data['naf_code'] = sanitize_text_field($data['naf_code']);
            $update_format[]         = '%s';
        }
        if (isset($data['phone'])) {
            $update_data['phone'] = sanitize_text_field($data['phone']);
            $update_format[]      = '%s';
        }
        if (isset($data['fax'])) {
            $update_data['fax'] = sanitize_text_field($data['fax']);
            $update_format[]    = '%s';
        }
        if (isset($data['contact_function'])) {
            $update_data['contact_function'] = sanitize_text_field($data['contact_function']);
            $update_format[]                 = '%s';
        }
        if (isset($data['contact_first_name'])) {
            $update_data['contact_first_name'] = sanitize_text_field($data['contact_first_name']);
            $update_format[]                   = '%s';
        }
        if (isset($data['contact_last_name'])) {
            $update_data['contact_last_name'] = sanitize_text_field($data['contact_last_name']);
            $update_format[]                  = '%s';
        }
        if (isset($data['payment_method_code'])) {
            $update_data['payment_method_code'] = sanitize_text_field($data['payment_method_code']);
            $update_format[]                    = '%s';
        }
        if (isset($data['payment_method_label'])) {
            $update_data['payment_method_label'] = sanitize_text_field($data['payment_method_label']);
            $update_format[]                     = '%s';
        }
        if (isset($data['category'])) {
            $update_data['category'] = sanitize_text_field($data['category']);
            $update_format[]         = '%s';
        }
        if (isset($data['activity'])) {
            $update_data['activity'] = sanitize_text_field($data['activity']);
            $update_format[]         = '%s';
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
        if (array_key_exists('tdw_profile_slug', $data)) {
            $update_data['tdw_profile_slug'] = !empty($data['tdw_profile_slug']) ? sanitize_key((string) $data['tdw_profile_slug']) : null;
            $update_format[]                 = '%s';
        }

        if (empty($update_data)) {
            return false;
        }

        $company_before = $this->get_company($id);

        $result = $wpdb->update(
            $wpdb->prefix . 'b2b_companies',
            $update_data,
            ['id' => $id],
            $update_format,
            ['%d']
        );

        if (false !== $result) {
            wp_cache_delete('pe_company_' . $id, 'portail-entreprises');

            $changes = [];
            foreach ($update_data as $field => $new_val) {
                $old_raw = ($company_before && property_exists($company_before, $field)) ? $company_before->$field : null;

                if (in_array($field, ['billing_address', 'shipping_address', 'modules_enabled'], true)) {
                    if (json_decode((string) $old_raw, true) !== json_decode((string) $new_val, true)) {
                        $changes[$field] = ['from' => $old_raw, 'to' => $new_val];
                    }
                } elseif (in_array($field, ['discount_rate', 'credit_limit'], true)) {
                    if (abs((float) $old_raw - (float) $new_val) > 0.001) {
                        $changes[$field] = ['from' => (string) $old_raw, 'to' => (string) $new_val];
                    }
                } elseif ($field === 'payment_terms') {
                    if ((int) $old_raw !== (int) $new_val) {
                        $changes[$field] = ['from' => (string) (int) $old_raw, 'to' => (string) (int) $new_val];
                    }
                } elseif ($field === 'assigned_rep_id') {
                    if ((int) $old_raw !== (int) $new_val) {
                        $changes[$field] = ['from' => $old_raw ? (string) (int) $old_raw : '', 'to' => $new_val ? (string) (int) $new_val : ''];
                    }
                } else {
                    if ((string) $old_raw !== (string) $new_val) {
                        $changes[$field] = ['from' => (string) $old_raw, 'to' => (string) $new_val];
                    }
                }
            }

            if (!empty($changes)) {
                PE_Audit_Log::get_instance()->log(
                    get_current_user_id(),
                    $id,
                    'update_company',
                    'company',
                    $id,
                    $changes
                );
            }

            do_action( 'pe_company_updated', $id );

            if (isset($update_data['billing_address']) || isset($update_data['shipping_address'])) {
                $this->sync_company_addresses_to_members($id);
            }

            // Le profil de remise pilote le rôle WordPress des membres : on
            // resynchronise les rôles à chaque enregistrement du profil.
            if (array_key_exists('tdw_profile_slug', $update_data)) {
                $this->sync_profile_role_to_members($id);
            }
        }

        return false !== $result;
    }

    /**
     * Synchronise les adresses (facturation + livraison) de la société dans le profil WooCommerce d'un membre.
     */
    public function sync_company_addresses_to_user(int $user_id, int $company_id): void {
        $company = $this->get_company($company_id);
        if (!$company) {
            return;
        }

        $billing  = (array) json_decode($company->billing_address, true);
        $shipping = (array) json_decode($company->shipping_address ?? '', true);

        $meta_map = [
            'billing_address_1'  => $billing['address_1'] ?? '',
            'billing_address_2'  => $billing['address_2'] ?? '',
            'billing_city'       => $billing['city'] ?? '',
            'billing_postcode'   => $billing['postcode'] ?? '',
            'billing_country'    => $billing['country'] ?? 'FR',
            'billing_company'    => $company->name,
            'shipping_address_1' => $shipping['address_1'] ?? '',
            'shipping_address_2' => $shipping['address_2'] ?? '',
            'shipping_city'      => $shipping['city'] ?? '',
            'shipping_postcode'  => $shipping['postcode'] ?? '',
            'shipping_country'   => $shipping['country'] ?? 'FR',
            'shipping_company'   => $company->name,
        ];

        foreach ($meta_map as $meta_key => $meta_value) {
            update_user_meta($user_id, $meta_key, $meta_value);
        }
    }

    /**
     * Synchronise les adresses de la société vers tous ses membres.
     */
    public function sync_company_addresses_to_members(int $company_id): void {
        $users = $this->get_company_users($company_id);
        foreach ($users as $user) {
            $this->sync_company_addresses_to_user((int) $user->user_id, $company_id);
        }
    }

    /**
     * Remonte l'adresse WooCommerce (facturation ou livraison) qu'un membre vient
     * d'enregistrer dans son compte vers la fiche société, puis la repropage à
     * tous les autres membres afin que l'adresse reste identique pour toute la société.
     *
     * @param int    $user_id ID de l'utilisateur ayant modifié son adresse.
     * @param string $type    'billing' ou 'shipping'.
     */
    public function sync_member_address_to_company(int $user_id, string $type): void {
        if (!in_array($type, ['billing', 'shipping'], true)) {
            return;
        }

        $company = PE_Permissions::get_user_company($user_id);
        if (!$company) {
            return;
        }

        $customer = new \WC_Customer($user_id);

        if ('billing' === $type) {
            $address = [
                'address_1' => $customer->get_billing_address_1(),
                'address_2' => $customer->get_billing_address_2(),
                'city'      => $customer->get_billing_city(),
                'postcode'  => $customer->get_billing_postcode(),
                'country'   => $customer->get_billing_country(),
            ];
        } else {
            $address = [
                'address_1' => $customer->get_shipping_address_1(),
                'address_2' => $customer->get_shipping_address_2(),
                'city'      => $customer->get_shipping_city(),
                'postcode'  => $customer->get_shipping_postcode(),
                'country'   => $customer->get_shipping_country(),
            ];
        }

        $this->update_company((int) $company->id, [$type . '_address' => $address]);
    }

    /**
     * Résout le slug du rôle WordPress correspondant à un profil de remise.
     *
     * Par défaut on associe le profil au rôle WordPress portant le même
     * identifiant (slug). À défaut, on recherche le rôle dont le nom
     * correspond à celui du profil (ex. profil « Membre OR » → rôle
     * « Membre OR »). Le filtre `pe_profile_role_slug` permet de surcharger
     * cette correspondance.
     *
     * @param string $profile_slug Slug du profil de remise.
     * @return string|null Slug du rôle WordPress, ou null si aucun ne correspond.
     */
    public function get_profile_role_slug(string $profile_slug): ?string {
        $profile_slug = sanitize_key($profile_slug);
        $wp_roles     = wp_roles();
        $role_slug    = null;

        if ('' !== $profile_slug) {
            // 1. Correspondance directe par identifiant.
            if ($wp_roles->is_role($profile_slug)) {
                $role_slug = $profile_slug;
            } else {
                // 2. Correspondance par nom de profil ↔ nom de rôle.
                $profile_name = '';
                if (class_exists('TDW_B2B_Taxonomies')) {
                    foreach (TDW_B2B_Taxonomies::get_profiles() as $profile) {
                        if (sanitize_key((string) ($profile['slug'] ?? '')) === $profile_slug) {
                            $profile_name = (string) ($profile['name'] ?? '');
                            break;
                        }
                    }
                }

                if ('' !== $profile_name) {
                    foreach ($wp_roles->roles as $slug => $role) {
                        if (0 === strcasecmp((string) ($role['name'] ?? ''), $profile_name)) {
                            $role_slug = $slug;
                            break;
                        }
                    }
                }
            }
        }

        /**
         * Filtre le rôle WordPress associé à un profil de remise.
         *
         * @param string|null $role_slug    Slug du rôle résolu (null si aucun).
         * @param string      $profile_slug Slug du profil de remise.
         */
        $role_slug = apply_filters('pe_profile_role_slug', $role_slug, $profile_slug);

        return (!empty($role_slug) && $wp_roles->is_role($role_slug)) ? $role_slug : null;
    }

    /**
     * Retourne l'ensemble des slugs de rôles WordPress gérés par les profils de remise.
     *
     * Utilisé pour retirer les anciens rôles de profil d'un utilisateur sans
     * toucher à ses autres rôles (ex. « customer »).
     *
     * @return string[]
     */
    public function get_all_profile_role_slugs(): array {
        $roles = [];

        if (class_exists('TDW_B2B_Taxonomies')) {
            foreach (TDW_B2B_Taxonomies::get_profiles() as $profile) {
                $role_slug = $this->get_profile_role_slug((string) ($profile['slug'] ?? ''));
                if ($role_slug) {
                    $roles[$role_slug] = true;
                }
            }
        }

        return array_keys($roles);
    }

    /**
     * Applique à un utilisateur le rôle WordPress correspondant à un profil de remise.
     *
     * Les autres rôles de profil sont retirés afin qu'un utilisateur ne porte
     * que le rôle de son profil courant. Les rôles hors profil (« customer »…)
     * sont préservés.
     *
     * @param int         $user_id      ID de l'utilisateur.
     * @param string|null $profile_slug Slug du profil cible, ou null/'' pour aucun.
     */
    public function sync_profile_role_to_user(int $user_id, ?string $profile_slug): void {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return;
        }

        $target_role   = !empty($profile_slug) ? $this->get_profile_role_slug($profile_slug) : null;
        $profile_roles = $this->get_all_profile_role_slugs();
        $current_roles = (array) $user->roles;

        // Retire les rôles de profil obsolètes (tous sauf le rôle cible).
        foreach ($profile_roles as $role_slug) {
            if ($role_slug !== $target_role && in_array($role_slug, $current_roles, true)) {
                $user->remove_role($role_slug);
            }
        }

        // Ajoute le rôle cible s'il n'est pas déjà présent.
        if ($target_role && !in_array($target_role, $current_roles, true)) {
            $user->add_role($target_role);
        }
    }

    /**
     * Synchronise le rôle de profil d'un utilisateur d'après sa société principale.
     *
     * @param int $user_id ID de l'utilisateur.
     */
    public function sync_user_profile_role(int $user_id): void {
        PE_Permissions::flush_user_cache($user_id);

        $company = PE_Permissions::get_user_company($user_id);
        $profile = $company ? (string) ($company->tdw_profile_slug ?? '') : '';

        $this->sync_profile_role_to_user($user_id, $profile);
    }

    /**
     * Synchronise le rôle de profil de tous les membres d'une société.
     *
     * @param int $company_id ID de la société.
     */
    public function sync_profile_role_to_members(int $company_id): void {
        foreach ($this->get_company_users($company_id) as $user) {
            $this->sync_user_profile_role((int) $user->user_id);
        }
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
     * Compte les membres d'une entreprise (requête COUNT, sans hydratation).
     */
    public function count_company_users(int $company_id): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}b2b_user_company WHERE company_id = %d",
                $company_id
            )
        );
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
            $this->sync_company_addresses_to_user($user_id, $company_id);
            $this->sync_user_profile_role($user_id);

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
            // Réévalue le rôle de profil d'après la société principale restante
            // (retire le rôle si l'utilisateur n'a plus de société à profil).
            $this->sync_user_profile_role($user_id);

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
            $where[]  = '(name LIKE %s OR siret LIKE %s OR customer_code LIKE %s)';
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
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
            $where[]  = '(name LIKE %s OR siret LIKE %s OR customer_code LIKE %s)';
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
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

        $user_ids = $wpdb->get_col(
            $wpdb->prepare("SELECT user_id FROM {$prefix}user_company WHERE company_id = %d", $company_id)
        );
        foreach ($user_ids as $uid) {
            PE_Permissions::flush_user_cache((int) $uid);
        }

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

        // Supprime la configuration de visibilité des commandes de l'entreprise.
        delete_option('pe_order_visibility_' . $company_id);

        // Réévalue le rôle de profil des anciens membres (suppression du rôle
        // si l'utilisateur n'appartient plus à une société à profil).
        foreach ($user_ids as $uid) {
            $this->sync_user_profile_role((int) $uid);
        }

        PE_Audit_Log::get_instance()->log(
            get_current_user_id(),
            $company_id,
            'delete_company',
            'company',
            $company_id,
            ['name' => $company->name]
        );

        do_action( 'pe_company_deleted', $company_id );

        return true;
    }

    /**
     * Recherche des sociétés existantes pour la pré-saisie d'une nouvelle fiche client
     * (rattachement d'une nouvelle entité à un client déjà connu).
     */
    public function search_companies_for_autofill(string $search, int $limit = 10): array {
        global $wpdb;

        $like = '%' . $wpdb->esc_like($search) . '%';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, customer_code, siret
                 FROM {$wpdb->prefix}b2b_companies
                 WHERE name LIKE %s OR customer_code LIKE %s OR siret LIKE %s
                 ORDER BY name ASC
                 LIMIT %d",
                $like,
                $like,
                $like,
                $limit
            )
        ) ?: [];
    }

    /**
     * Date de la dernière commande de la société (au format Y-m-d H:i:s), ou null si aucune.
     */
    public function get_last_order_date(int $company_id): ?string {
        if (!class_exists('PE_Core') || !function_exists('wc_get_orders')) {
            return null;
        }

        $orders = PE_Core::get_company_orders($company_id, 1);
        if (empty($orders)) {
            return null;
        }

        $date = $orders[0]->get_date_created();
        return $date ? $date->date('Y-m-d H:i:s') : null;
    }

    /**
     * Date du dernier devis de la société, ou null si aucun.
     *
     * Le module devis n'est pas géré nativement par ce plugin : cette méthode
     * délègue à un éventuel plugin de devis (ex. « Transformer en devis ») via
     * le filtre `pe_company_last_quote_date`.
     */
    public function get_last_quote_date(int $company_id): ?string {
        /**
         * Filtre la date du dernier devis d'une société.
         *
         * @param string|null $date       Date (Y-m-d H:i:s) ou null si aucun devis / aucun plugin de devis actif.
         * @param int         $company_id ID de la société.
         */
        return apply_filters('pe_company_last_quote_date', null, $company_id);
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

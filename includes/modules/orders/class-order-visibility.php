<?php
/**
 * Module : Visibilité des commandes au niveau entreprise.
 *
 * Par défaut, tous les membres d'une entreprise voient l'ensemble des commandes
 * de leur société. Les administrateurs d'entreprise peuvent restreindre cette
 * visibilité par rôle, par utilisateur, exclure certains membres de la
 * visibilité globale, ou bloquer des paires « ce membre ne peut pas voir les
 * commandes de cet autre membre ».
 *
 * Règles de priorité (de la plus forte à la plus faible) :
 *   1. Restriction utilisateur spécifique (blocages par paire + portée par utilisateur).
 *   2. Restriction par rôle.
 *   3. Règle générale de l'entreprise.
 *
 * La configuration est stockée dans la métadonnée d'entreprise
 * (colonne `order_visibility` de la table b2b_companies, au format JSON).
 */

defined('ABSPATH') || exit;

class PE_Order_Visibility {

    private static ?PE_Order_Visibility $instance = null;

    /** Cache par requête : config par entreprise. */
    private array $config_cache = [];

    /** Cache par requête : IDs visibles par utilisateur. */
    private array $visible_cache = [];

    private function __construct() {}

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        // Liste des commandes « Mon compte » : élargit/restreint selon la visibilité.
        add_filter('woocommerce_my_account_my_orders_query', [$this, 'filter_my_orders_query']);

        // Sécurité : autorise la consultation des commandes de l'entreprise visibles
        // (accès direct via URL view-order / pay-for-order).
        add_filter('map_meta_cap', [$this, 'grant_order_view_cap'], 99, 4);
    }

    /* =========================================================
       Configuration (métadonnée d'entreprise)
       ========================================================= */

    /**
     * Valeurs de portée valides.
     */
    private function valid_scopes(): array {
        return ['all', 'own'];
    }

    /**
     * Retourne la configuration de visibilité d'une entreprise (avec valeurs par défaut).
     *
     * @return array{default:string,roles:array,users:array,excluded:int[],blocks:array}
     */
    public function get_config(int $company_id): array {
        if (isset($this->config_cache[$company_id])) {
            return $this->config_cache[$company_id];
        }

        global $wpdb;
        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT order_visibility FROM {$wpdb->prefix}b2b_companies WHERE id = %d LIMIT 1",
                $company_id
            )
        );

        $config = $this->normalize_config(json_decode((string) $raw, true));
        $this->config_cache[$company_id] = $config;

        return $config;
    }

    /**
     * Normalise une configuration brute (issue d'un POST ou de la base).
     */
    public function normalize_config($raw): array {
        $raw = is_array($raw) ? $raw : [];

        $default = (isset($raw['default']) && in_array($raw['default'], $this->valid_scopes(), true))
            ? $raw['default'] : 'all';

        $roles = [];
        if (!empty($raw['roles']) && is_array($raw['roles'])) {
            foreach ($raw['roles'] as $role => $scope) {
                if (in_array($scope, $this->valid_scopes(), true)) {
                    $roles[sanitize_key((string) $role)] = $scope;
                }
            }
        }

        $users = [];
        if (!empty($raw['users']) && is_array($raw['users'])) {
            foreach ($raw['users'] as $uid => $scope) {
                if (in_array($scope, $this->valid_scopes(), true)) {
                    $users[(int) $uid] = $scope;
                }
            }
        }

        $excluded = [];
        if (!empty($raw['excluded']) && is_array($raw['excluded'])) {
            $excluded = array_values(array_unique(array_map('intval', $raw['excluded'])));
        }

        $blocks = [];
        if (!empty($raw['blocks']) && is_array($raw['blocks'])) {
            foreach ($raw['blocks'] as $viewer => $owners) {
                if (!is_array($owners)) {
                    continue;
                }
                $owner_ids = array_values(array_unique(array_map('intval', $owners)));
                if (!empty($owner_ids)) {
                    $blocks[(int) $viewer] = $owner_ids;
                }
            }
        }

        return [
            'default'  => $default,
            'roles'    => $roles,
            'users'    => $users,
            'excluded' => $excluded,
            'blocks'   => $blocks,
        ];
    }

    /**
     * Enregistre la configuration de visibilité d'une entreprise.
     */
    public function save_config(int $company_id, array $config): bool {
        global $wpdb;

        $config = $this->normalize_config($config);

        $result = $wpdb->update(
            $wpdb->prefix . 'b2b_companies',
            ['order_visibility' => wp_json_encode($config)],
            ['id' => $company_id],
            ['%s'],
            ['%d']
        );

        // Invalide les caches.
        unset($this->config_cache[$company_id]);
        $this->visible_cache = [];
        wp_cache_delete('pe_company_' . $company_id, 'portail-entreprises');
        foreach ($this->get_company_member_ids($company_id) as $uid) {
            PE_Permissions::flush_user_cache($uid);
        }

        if (false !== $result) {
            PE_Audit_Log::get_instance()->log(
                get_current_user_id(),
                $company_id,
                'update_order_visibility',
                'company',
                $company_id,
                $config
            );
        }

        return false !== $result;
    }

    /* =========================================================
       Décision de visibilité
       ========================================================= */

    /**
     * IDs des membres d'une entreprise.
     *
     * @return int[]
     */
    public function get_company_member_ids(int $company_id): array {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}b2b_user_company WHERE company_id = %d",
                $company_id
            )
        );

        return array_map('intval', $ids ?: []);
    }

    /**
     * Résout la portée applicable à un utilisateur (priorité : utilisateur > rôle > général).
     */
    private function resolve_scope(array $config, int $viewer_id, ?string $viewer_role): string {
        if (isset($config['users'][$viewer_id]) && in_array($config['users'][$viewer_id], $this->valid_scopes(), true)) {
            return $config['users'][$viewer_id];
        }
        if ($viewer_role && isset($config['roles'][$viewer_role]) && in_array($config['roles'][$viewer_role], $this->valid_scopes(), true)) {
            return $config['roles'][$viewer_role];
        }
        return $config['default'];
    }

    /**
     * Retourne les IDs d'utilisateurs dont les commandes sont visibles par $viewer_id
     * (toujours au sein de sa propre entreprise, et incluant toujours lui-même).
     *
     * @return int[]
     */
    public function get_visible_user_ids(int $viewer_id): array {
        if (isset($this->visible_cache[$viewer_id])) {
            return $this->visible_cache[$viewer_id];
        }

        $company = PE_Permissions::get_user_company($viewer_id);
        if (!$company) {
            $this->visible_cache[$viewer_id] = [$viewer_id];
            return [$viewer_id];
        }

        $company_id = (int) $company->id;
        $members    = $this->get_company_member_ids($company_id);
        $role       = PE_Permissions::get_user_b2b_role($viewer_id);

        // L'administrateur d'entreprise voit toujours toutes les commandes.
        if ('company_admin' === $role) {
            $this->visible_cache[$viewer_id] = $members;
            return $members;
        }

        $config = $this->get_config($company_id);
        $scope  = $this->resolve_scope($config, $viewer_id, $role);

        // Portée « ses propres commandes uniquement ».
        if ('own' === $scope) {
            $this->visible_cache[$viewer_id] = [$viewer_id];
            return [$viewer_id];
        }

        // Portée « toutes » : on retire les membres exclus et les blocages spécifiques.
        $blocks   = $config['blocks'][$viewer_id] ?? [];
        $excluded = $config['excluded'] ?? [];

        $visible = [];
        foreach ($members as $member_id) {
            if ($member_id === $viewer_id) {
                $visible[] = $member_id; // Toujours voir ses propres commandes.
                continue;
            }
            if (in_array($member_id, $blocks, true)) {
                continue; // Restriction utilisateur spécifique (priorité 1).
            }
            if (in_array($member_id, $excluded, true)) {
                continue; // Membre exclu de la visibilité globale.
            }
            $visible[] = $member_id;
        }

        if (!in_array($viewer_id, $visible, true)) {
            $visible[] = $viewer_id;
        }

        $this->visible_cache[$viewer_id] = $visible;
        return $visible;
    }

    /**
     * Indique si $viewer_id peut consulter une commande appartenant à $owner_id.
     */
    public function can_view(int $viewer_id, int $owner_id): bool {
        if ($viewer_id === $owner_id) {
            return true;
        }
        return in_array($owner_id, $this->get_visible_user_ids($viewer_id), true);
    }

    /* =========================================================
       Application (listes, sécurité)
       ========================================================= */

    /**
     * Élargit/restreint la requête de la liste « Commandes » du compte client.
     */
    public function filter_my_orders_query(array $args): array {
        $user_id = get_current_user_id();
        if (!$user_id || !PE_Permissions::is_b2b_user($user_id)) {
            return $args;
        }

        $args['customer'] = $this->get_visible_user_ids($user_id);

        return $args;
    }

    /**
     * Autorise la consultation/paiement d'une commande d'un membre de l'entreprise
     * dont la visibilité est accordée (sécurise l'accès direct par URL).
     */
    public function grant_order_view_cap(array $caps, string $cap, int $user_id, array $args): array {
        if ('view_order' !== $cap && 'pay_for_order' !== $cap) {
            return $caps;
        }
        if (!$user_id || empty($args[0]) || !PE_Permissions::is_b2b_user($user_id)) {
            return $caps;
        }

        $order = wc_get_order((int) $args[0]);
        if (!$order) {
            return $caps;
        }

        $owner_id = (int) $order->get_customer_id();
        if ($owner_id && $this->can_view($user_id, $owner_id)) {
            // L'utilisateur dispose de la capacité « read » : on accorde l'accès.
            return ['read'];
        }

        return $caps;
    }
}

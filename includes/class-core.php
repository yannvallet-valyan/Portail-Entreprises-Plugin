<?php
/**
 * Cœur du plugin — chargement des modules et enregistrement des hooks principaux.
 */

defined('ABSPATH') || exit;

class PE_Core {

    private static ?PE_Core $instance = null;

    private function __construct() {
        $this->init_hooks();
    }

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function init_hooks(): void {
        // Enregistrement du statut de commande personnalisé
        add_action('init', [$this, 'register_custom_order_status']);

        // Enregistrement des endpoints My Account
        add_action('init', [$this, 'register_endpoints']);

        // Déclarer les endpoints auprès de WooCommerce (méthode canonique).
        add_filter('woocommerce_get_query_vars', [$this, 'add_wc_query_vars']);

        // Titres des endpoints
        add_filter('woocommerce_endpoint_b2b-dashboard_title', fn() => __('Tableau de bord B2B', 'portail-entreprises'));
        add_filter('woocommerce_endpoint_b2b-company_title', fn() => __('Mon entreprise', 'portail-entreprises'));
        add_filter('woocommerce_endpoint_b2b-users_title', fn() => __('Utilisateurs', 'portail-entreprises'));
        add_filter('woocommerce_endpoint_b2b-budgets_title', fn() => __('Budgets', 'portail-entreprises'));
        add_filter('woocommerce_endpoint_b2b-approvals_title', fn() => __('Approbations', 'portail-entreprises'));

        // Flush différé des rewrite rules si la version a changé.
        add_action('init', [$this, 'maybe_flush_rewrite_rules'], 99);

        // Ajout des éléments de menu My Account pour les utilisateurs B2B
        add_filter('woocommerce_account_menu_items', [$this, 'add_b2b_menu_items']);

        // Rendu des templates des endpoints
        add_action('woocommerce_account_b2b-dashboard_endpoint', [$this, 'render_dashboard']);
        add_action('woocommerce_account_b2b-company_endpoint', [$this, 'render_company']);
        add_action('woocommerce_account_b2b-users_endpoint', [$this, 'render_users']);
        add_action('woocommerce_account_b2b-budgets_endpoint', [$this, 'render_budgets']);
        add_action('woocommerce_account_b2b-approvals_endpoint', [$this, 'render_approvals']);

        // Enqueue des assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets'], 20);

        // Ajout du champ centre de coût au checkout
        add_filter('woocommerce_checkout_fields', [$this, 'add_cost_center_field']);

        // Sauvegarde du centre de coût sur la commande
        add_action('woocommerce_checkout_create_order', [$this, 'save_cost_center_to_order'], 10, 2);

        // Initialisation des modules
        PE_Budget_Manager::get_instance()->init();
        PE_Approval_Manager::get_instance()->init();

        // Admin
        if (is_admin()) {
            require_once PE_PATH . 'includes/admin/class-admin.php';
            PE_Admin::get_instance();
        }
    }

    public function register_custom_order_status(): void {
        register_post_status('wc-pending-approval', [
            'label'                     => _x('En attente de validation', 'Order status', 'portail-entreprises'),
            'public'                    => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'exclude_from_search'       => false,
            /* translators: %s: number of orders */
            'label_count'               => _n_noop('En attente de validation <span class="count">(%s)</span>', 'En attente de validation <span class="count">(%s)</span>', 'portail-entreprises'),
        ]);

        add_filter('wc_order_statuses', function (array $statuses): array {
            $statuses['wc-pending-approval'] = _x('En attente de validation', 'Order status', 'portail-entreprises');
            return $statuses;
        });
    }

    /**
     * Liste des endpoints B2B (slug => query var).
     */
    public static function get_b2b_endpoints(): array {
        return [
            'b2b-dashboard',
            'b2b-company',
            'b2b-users',
            'b2b-budgets',
            'b2b-approvals',
        ];
    }

    public function register_endpoints(): void {
        foreach (self::get_b2b_endpoints() as $endpoint) {
            add_rewrite_endpoint($endpoint, EP_ROOT | EP_PAGES);
        }
    }

    /**
     * Déclare les endpoints comme query vars WooCommerce (essentiel pour
     * que WC les reconnaisse comme endpoints de la page "Mon compte").
     */
    public function add_wc_query_vars(array $vars): array {
        foreach (self::get_b2b_endpoints() as $endpoint) {
            $vars[$endpoint] = $endpoint;
        }
        return $vars;
    }

    /**
     * Flush automatique des rewrite rules quand la version du plugin change
     * ou lors de la première activation (sans intervention manuelle).
     */
    public function maybe_flush_rewrite_rules(): void {
        $flushed_version = get_option('pe_rewrite_version');

        if ($flushed_version !== PE_VERSION || get_option('pe_flush_rewrite_rules')) {
            flush_rewrite_rules();
            update_option('pe_rewrite_version', PE_VERSION, false);
            delete_option('pe_flush_rewrite_rules');
        }
    }

    public function add_b2b_menu_items(array $items): array {
        $user_id = get_current_user_id();

        if (!$user_id || !PE_Permissions::is_b2b_user($user_id)) {
            return $items;
        }

        $b2b_items = [
            'b2b-dashboard'  => __('Tableau de bord B2B', 'portail-entreprises'),
            'b2b-company'    => __('Mon entreprise', 'portail-entreprises'),
            'b2b-users'      => __('Utilisateurs', 'portail-entreprises'),
            'b2b-budgets'    => __('Budgets', 'portail-entreprises'),
            'b2b-approvals'  => __('Approbations', 'portail-entreprises'),
        ];

        // Insérer avant "logout"
        $logout = false;
        if (isset($items['customer-logout'])) {
            $logout = $items['customer-logout'];
            unset($items['customer-logout']);
        }

        foreach ($b2b_items as $key => $label) {
            $items[$key] = $label;
        }

        if ($logout) {
            $items['customer-logout'] = $logout;
        }

        return $items;
    }

    private function render_template(string $template): void {
        $file = PE_PATH . 'templates/myaccount/' . $template . '.php';
        if (file_exists($file)) {
            include $file;
        }
    }

    public function render_dashboard(): void {
        $user_id = get_current_user_id();
        if (!PE_Permissions::is_b2b_user($user_id)) {
            wp_die(esc_html__('Accès refusé.', 'portail-entreprises'));
        }
        $this->render_template('b2b-dashboard');
    }

    public function render_company(): void {
        $user_id = get_current_user_id();
        if (!PE_Permissions::is_b2b_user($user_id)) {
            wp_die(esc_html__('Accès refusé.', 'portail-entreprises'));
        }
        $this->render_template('b2b-company');
    }

    public function render_users(): void {
        $user_id = get_current_user_id();
        if (!PE_Permissions::is_b2b_user($user_id)) {
            wp_die(esc_html__('Accès refusé.', 'portail-entreprises'));
        }
        $this->render_template('b2b-users');
    }

    public function render_budgets(): void {
        $user_id = get_current_user_id();
        if (!PE_Permissions::is_b2b_user($user_id)) {
            wp_die(esc_html__('Accès refusé.', 'portail-entreprises'));
        }
        $this->render_template('b2b-budgets');
    }

    public function render_approvals(): void {
        $user_id = get_current_user_id();
        if (!PE_Permissions::is_b2b_user($user_id)) {
            wp_die(esc_html__('Accès refusé.', 'portail-entreprises'));
        }
        $this->render_template('b2b-approvals');
    }

    public function enqueue_frontend_assets(): void {
        if (!is_account_page()) {
            return;
        }

        $user_id = get_current_user_id();
        if (!$user_id || !PE_Permissions::is_b2b_user($user_id)) {
            return;
        }

        wp_enqueue_style(
            'pe-b2b-portal',
            PE_URL . 'assets/css/b2b-portal.css',
            [],
            PE_VERSION
        );

        wp_enqueue_script(
            'pe-b2b-portal',
            PE_URL . 'assets/js/b2b-portal.js',
            ['jquery'],
            PE_VERSION,
            true
        );

        wp_localize_script('pe-b2b-portal', 'peB2B', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('pe_b2b_ajax'),
            'i18n'    => [
                'confirmApprove'  => __('Confirmer l\'approbation de cette demande ?', 'portail-entreprises'),
                'confirmReject'   => __('Confirmer le rejet de cette demande ?', 'portail-entreprises'),
                'confirmDelete'   => __('Êtes-vous sûr de vouloir effectuer cette action ?', 'portail-entreprises'),
                'processing'      => __('Traitement en cours...', 'portail-entreprises'),
                'error'           => __('Une erreur est survenue. Veuillez réessayer.', 'portail-entreprises'),
            ],
        ]);
    }

    public function add_cost_center_field(array $fields): array {
        $user_id = get_current_user_id();

        if (!$user_id || !PE_Permissions::is_b2b_user($user_id)) {
            return $fields;
        }

        $company = PE_Permissions::get_user_company($user_id);
        if (!$company) {
            return $fields;
        }

        global $wpdb;
        $cost_centers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, code FROM {$wpdb->prefix}b2b_cost_centers WHERE company_id = %d ORDER BY name ASC",
                (int) $company->id
            )
        );

        if (empty($cost_centers)) {
            return $fields;
        }

        $options = ['' => __('-- Sélectionner un centre de coût --', 'portail-entreprises')];
        foreach ($cost_centers as $cc) {
            $label = esc_html($cc->name);
            if ($cc->code) {
                $label .= ' (' . esc_html($cc->code) . ')';
            }
            $options[(int) $cc->id] = $label;
        }

        $fields['order']['b2b_cost_center'] = [
            'type'     => 'select',
            'label'    => __('Centre de coût', 'portail-entreprises'),
            'required' => false,
            'class'    => ['form-row-wide'],
            'options'  => $options,
            'priority' => 5,
        ];

        return $fields;
    }

    public function save_cost_center_to_order(\WC_Order $order, array $data): void {
        $cost_center_id = isset($_POST['b2b_cost_center']) ? absint($_POST['b2b_cost_center']) : 0;
        if ($cost_center_id > 0) {
            $order->update_meta_data('_b2b_cost_center_id', $cost_center_id);
        }
    }
}

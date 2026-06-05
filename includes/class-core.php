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

        // Affichage des infos B2B (centre de coût + référence) dans la commande.
        add_filter('woocommerce_get_order_item_totals', [$this, 'add_b2b_order_totals_rows'], 10, 3);
        add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'display_b2b_order_meta_admin']);

        // AJAX : édition de centre de coût + référence par les managers.
        add_action('wp_ajax_pe_update_order_b2b_meta', [$this, 'ajax_update_order_b2b_meta']);

        // Initialisation des modules
        PE_Budget_Manager::get_instance()->init();
        PE_Approval_Manager::get_instance()->init();
        PE_Magic_Link_Manager::get_instance()->init();

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
        $user_id = get_current_user_id();
        if (!$user_id || !PE_Permissions::is_b2b_user($user_id)) {
            return;
        }

        $on_b2b_page = is_account_page() || is_cart() || is_checkout();
        if (!$on_b2b_page) {
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

        // Checkout blocking data for JS (WoodMart compatibility).
        $checkout_blocked       = false;
        $checkout_block_reason  = '';
        $approval_button_html   = '';

        if ((is_cart() || is_checkout()) && class_exists('PE_Budget_Manager')) {
            $reason = PE_Budget_Manager::get_instance()->get_checkout_block_reason();
            if (true !== $reason) {
                $checkout_blocked      = true;
                $checkout_block_reason = $reason;
                if (class_exists('PE_Approval_Manager')) {
                    $approval_button_html = PE_Approval_Manager::get_instance()->get_approval_button_html('cart');
                }
            }
        }

        wp_localize_script('pe-b2b-portal', 'peB2B', [
            'ajaxUrl'            => admin_url('admin-ajax.php'),
            'nonce'              => wp_create_nonce('pe_b2b_ajax'),
            'checkoutBlocked'    => $checkout_blocked,
            'checkoutBlockMsg'   => $checkout_block_reason,
            'approvalButtonHtml' => $approval_button_html,
            'i18n'               => [
                'confirmApprove'           => __('Confirmer l\'approbation de cette demande ?', 'portail-entreprises'),
                'confirmReject'            => __('Confirmer le rejet de cette demande ?', 'portail-entreprises'),
                'confirmDelete'            => __('Êtes-vous sûr de vouloir effectuer cette action ?', 'portail-entreprises'),
                'confirmDeleteCompany'     => __('Êtes-vous sûr de vouloir supprimer la société « %s » ?', 'portail-entreprises'),
                'confirmDeleteCompanyFinal' => __('ATTENTION : cette action est irréversible. Toutes les données associées (utilisateurs, budgets, agences, approbations) seront supprimées. Confirmer définitivement ?', 'portail-entreprises'),
                'processing'               => __('Traitement en cours...', 'portail-entreprises'),
                'error'                    => __('Une erreur est survenue. Veuillez réessayer.', 'portail-entreprises'),
            ],
        ]);
    }

    public function add_cost_center_field(array $fields): array {
        $user_id = get_current_user_id();

        if (!$user_id || !PE_Permissions::is_b2b_user($user_id)) {
            return $fields;
        }

        // Les requester ne peuvent pas passer en checkout — leurs champs sont dans le
        // formulaire "Demander une approbation", ne pas les dupliquer ici.
        if ('requester' === PE_Permissions::get_user_b2b_role($user_id)) {
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

        // Centre de coût (si l'entreprise en a défini).
        if (!empty($cost_centers)) {
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
        }

        // Référence personnelle.
        $fields['order']['b2b_personal_reference'] = [
            'type'        => 'text',
            'label'       => __('Votre référence', 'portail-entreprises'),
            'placeholder' => __('Bon de commande, référence interne…', 'portail-entreprises'),
            'required'    => false,
            'class'       => ['form-row-wide'],
            'priority'    => 6,
        ];

        return $fields;
    }

    public function save_cost_center_to_order(\WC_Order $order, array $data): void {
        $cost_center_id = isset($_POST['b2b_cost_center']) ? absint($_POST['b2b_cost_center']) : 0;
        if ($cost_center_id > 0) {
            $order->update_meta_data('_b2b_cost_center_id', $cost_center_id);

            // Stocke aussi le libellé lisible pour l'affichage.
            global $wpdb;
            $cc = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT name, code FROM {$wpdb->prefix}b2b_cost_centers WHERE id = %d LIMIT 1",
                    $cost_center_id
                )
            );
            if ($cc) {
                $label = $cc->name . ($cc->code ? ' (' . $cc->code . ')' : '');
                $order->update_meta_data('_b2b_cost_center_label', $label);
            }
        }

        $reference = isset($_POST['b2b_personal_reference'])
            ? sanitize_text_field(wp_unslash($_POST['b2b_personal_reference']))
            : '';
        if ('' !== $reference) {
            $order->update_meta_data('_b2b_personal_reference', $reference);
        }
    }

    /**
     * Ajoute les lignes "Centre de coût" et "Votre référence" dans le récapitulatif
     * de commande (Mon Compte, page commande, emails).
     */
    public function add_b2b_order_totals_rows(array $total_rows, \WC_Order $order, $tax_display = ''): array {
        $cost_center = $order->get_meta('_b2b_cost_center_label');
        $reference   = $order->get_meta('_b2b_personal_reference');

        if (empty($cost_center) && empty($reference)) {
            return $total_rows;
        }

        $new_rows = [];
        foreach ($total_rows as $key => $row) {
            // Insère nos lignes juste avant le total final.
            if ('order_total' === $key) {
                if (!empty($cost_center)) {
                    $new_rows['b2b_cost_center'] = [
                        'label' => __('Centre de coût :', 'portail-entreprises'),
                        'value' => esc_html($cost_center),
                    ];
                }
                if (!empty($reference)) {
                    $new_rows['b2b_personal_reference'] = [
                        'label' => __('Votre référence :', 'portail-entreprises'),
                        'value' => esc_html($reference),
                    ];
                }
            }
            $new_rows[$key] = $row;
        }

        return $new_rows;
    }

    /**
     * Affiche les infos B2B dans l'écran d'édition de commande (admin).
     */
    public function display_b2b_order_meta_admin(\WC_Order $order): void {
        $cost_center = $order->get_meta('_b2b_cost_center_label');
        $reference   = $order->get_meta('_b2b_personal_reference');

        if (empty($cost_center) && empty($reference)) {
            return;
        }

        echo '<div class="pe-admin-order-meta" style="margin-top:12px;">';
        echo '<h4>' . esc_html__('Informations B2B', 'portail-entreprises') . '</h4>';
        if (!empty($cost_center)) {
            echo '<p><strong>' . esc_html__('Centre de coût :', 'portail-entreprises') . '</strong> ' . esc_html($cost_center) . '</p>';
        }
        if (!empty($reference)) {
            echo '<p><strong>' . esc_html__('Référence client :', 'portail-entreprises') . '</strong> ' . esc_html($reference) . '</p>';
        }
        echo '</div>';
    }

    /**
     * AJAX : met à jour le centre de coût et la référence sur une commande.
     * Réservé aux company_admin et purchase_manager.
     */
    public function ajax_update_order_b2b_meta(): void {
        check_ajax_referer('pe_b2b_ajax', 'nonce');

        $user_id  = get_current_user_id();
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;

        if (!$user_id || !$order_id) {
            wp_send_json_error(['message' => __('Paramètres invalides.', 'portail-entreprises')]);
        }

        $role = PE_Permissions::get_user_b2b_role($user_id);
        if (!in_array($role, ['company_admin', 'purchase_manager'], true)) {
            wp_send_json_error(['message' => __('Permission refusée.', 'portail-entreprises')]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => __('Commande introuvable.', 'portail-entreprises')]);
        }

        // Vérifier que la commande appartient à l'entreprise du manager.
        $company    = PE_Permissions::get_user_company($user_id);
        $order_user = (int) $order->get_customer_id();
        if (!$company || !PE_Permissions::user_belongs_to_company($order_user, (int) $company->id)) {
            wp_send_json_error(['message' => __('Cette commande n\'appartient pas à votre entreprise.', 'portail-entreprises')]);
        }

        $reference      = isset($_POST['reference']) ? sanitize_text_field(wp_unslash($_POST['reference'])) : '';
        $cost_center_id = isset($_POST['cost_center_id']) ? absint($_POST['cost_center_id']) : 0;

        $order->update_meta_data('_b2b_personal_reference', $reference);

        if ($cost_center_id > 0) {
            global $wpdb;
            $cc = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT name, code FROM {$wpdb->prefix}b2b_cost_centers WHERE id = %d AND company_id = %d LIMIT 1",
                    $cost_center_id,
                    (int) $company->id
                )
            );
            if ($cc) {
                $label = $cc->name . ($cc->code ? ' (' . $cc->code . ')' : '');
                $order->update_meta_data('_b2b_cost_center_id', $cost_center_id);
                $order->update_meta_data('_b2b_cost_center_label', $label);
            }
        } else {
            $order->delete_meta_data('_b2b_cost_center_id');
            $order->delete_meta_data('_b2b_cost_center_label');
        }

        $order->save();

        // Mettre à jour aussi la demande d'approbation si elle existe.
        global $wpdb;
        if ($cost_center_id > 0) {
            $wpdb->update(
                $wpdb->prefix . 'b2b_approval_requests',
                ['cost_center_id' => $cost_center_id, 'updated_at' => current_time('mysql')],
                ['order_id' => $order_id],
                ['%d', '%s'],
                ['%d']
            );
        }

        wp_send_json_success(['message' => __('Informations mises à jour.', 'portail-entreprises')]);
    }

    /**
     * Retourne les commandes WooCommerce de tous les membres d'une entreprise.
     */
    public static function get_company_orders(int $company_id, int $limit = 50): array {
        global $wpdb;

        $user_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}b2b_user_company WHERE company_id = %d",
                $company_id
            )
        );

        if (empty($user_ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_status, p.post_date, pm_customer.meta_value AS customer_id
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm_customer ON pm_customer.post_id = p.ID AND pm_customer.meta_key = '_customer_user'
                 WHERE p.post_type = 'shop_order'
                   AND CAST(pm_customer.meta_value AS UNSIGNED) IN ($placeholders)
                 ORDER BY p.post_date DESC
                 LIMIT %d",
                ...array_merge(array_map('intval', $user_ids), [$limit])
            )
        );

        $order_ids = array_column($rows ?: [], 'ID');

        return array_filter(array_map('wc_get_order', $order_ids));
    }
}

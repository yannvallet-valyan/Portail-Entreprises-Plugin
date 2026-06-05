<?php
/**
 * Interface d'administration B2B.
 */

defined('ABSPATH') || exit;

class PE_Admin {

    private static ?PE_Admin $instance = null;

    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menus']);
        add_action('show_user_profile', [$this, 'add_user_b2b_fields']);
        add_action('edit_user_profile', [$this, 'add_user_b2b_fields']);
        add_action('personal_options_update', [$this, 'save_user_b2b_fields']);
        add_action('edit_user_profile_update', [$this, 'save_user_b2b_fields']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_pe_admin_add_user_to_company', [$this, 'ajax_admin_add_user_to_company']);
        add_action('wp_ajax_pe_admin_remove_user_from_company', [$this, 'ajax_admin_remove_user_from_company']);
        add_action('wp_ajax_pe_admin_create_cost_center', [$this, 'ajax_admin_create_cost_center']);
        add_action('wp_ajax_pe_admin_delete_approval_rule', [$this, 'ajax_admin_delete_approval_rule']);
        add_action('admin_post_pe_save_approval_rule', [$this, 'handle_save_approval_rule']);
    }

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function add_admin_menus(): void {
        add_menu_page(
            __('Portail B2B', 'portail-entreprises'),
            __('Portail B2B', 'portail-entreprises'),
            'manage_woocommerce',
            'portail-b2b',
            [$this, 'render_companies_page'],
            'dashicons-building',
            56
        );

        add_submenu_page(
            'portail-b2b',
            __('Sociétés', 'portail-entreprises'),
            __('Sociétés', 'portail-entreprises'),
            'manage_woocommerce',
            'portail-b2b',
            [$this, 'render_companies_page']
        );

        add_submenu_page(
            'portail-b2b',
            __('Paramètres', 'portail-entreprises'),
            __('Paramètres', 'portail-entreprises'),
            'manage_woocommerce',
            'portail-b2b-settings',
            [$this, 'render_settings_page']
        );
    }

    public function enqueue_admin_assets(string $hook): void {
        $b2b_pages = ['toplevel_page_portail-b2b', 'portail-b2b_page_portail-b2b-settings'];

        if (!in_array($hook, $b2b_pages, true) && 'user-edit.php' !== $hook && 'profile.php' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'pe-admin',
            PE_URL . 'assets/css/b2b-portal.css',
            [],
            PE_VERSION
        );

        wp_enqueue_script(
            'pe-admin-js',
            PE_URL . 'assets/js/b2b-portal.js',
            ['jquery'],
            PE_VERSION,
            true
        );

        wp_localize_script('pe-admin-js', 'peB2B', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('pe_b2b_ajax'),
            'i18n'    => [
                'confirmDelete' => __('Êtes-vous sûr de vouloir effectuer cette action ?', 'portail-entreprises'),
                'processing'    => __('Traitement en cours...', 'portail-entreprises'),
                'error'         => __('Une erreur est survenue.', 'portail-entreprises'),
            ],
        ]);
    }

    public function render_companies_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Accès refusé.', 'portail-entreprises'));
        }

        $action     = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';
        $company_id = isset($_GET['company_id']) ? absint($_GET['company_id']) : 0;

        if ('edit' === $action && $company_id > 0) {
            $this->handle_company_edit_form($company_id);
            include PE_PATH . 'admin/views/company-edit.php';
        } elseif ('new' === $action) {
            $this->handle_company_new_form();
            include PE_PATH . 'admin/views/company-edit.php';
        } else {
            include PE_PATH . 'admin/views/companies.php';
        }
    }

    private function handle_company_edit_form(int $company_id): void {
        if (!isset($_POST['pe_save_company']) || !isset($_POST['_wpnonce'])) {
            return;
        }

        check_admin_referer('pe_save_company_' . $company_id, '_wpnonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Accès refusé.', 'portail-entreprises'));
        }

        $data = [
            'name'            => sanitize_text_field(wp_unslash($_POST['company_name'] ?? '')),
            'siret'           => sanitize_text_field(wp_unslash($_POST['siret'] ?? '')),
            'vat_number'      => sanitize_text_field(wp_unslash($_POST['vat_number'] ?? '')),
            'discount_rate'   => (float) ($_POST['discount_rate'] ?? 0),
            'credit_limit'    => (float) ($_POST['credit_limit'] ?? 0),
            'payment_terms'   => (int) ($_POST['payment_terms'] ?? 30),
            'status'          => sanitize_key($_POST['status'] ?? 'active'),
            'modules_enabled' => array_map('sanitize_key', (array) ($_POST['modules_enabled'] ?? [])),
            'billing_address' => [
                'address_1' => sanitize_text_field(wp_unslash($_POST['billing_address_1'] ?? '')),
                'address_2' => sanitize_text_field(wp_unslash($_POST['billing_address_2'] ?? '')),
                'city'      => sanitize_text_field(wp_unslash($_POST['billing_city'] ?? '')),
                'postcode'  => sanitize_text_field(wp_unslash($_POST['billing_postcode'] ?? '')),
                'country'   => sanitize_text_field(wp_unslash($_POST['billing_country'] ?? '')),
            ],
        ];

        $manager = PE_Company_Manager::get_instance();
        $result  = $manager->update_company($company_id, $data);

        if ($result) {
            add_settings_error('pe_messages', 'pe_updated', __('Entreprise mise à jour avec succès.', 'portail-entreprises'), 'updated');
        } else {
            add_settings_error('pe_messages', 'pe_error', __('Erreur lors de la mise à jour.', 'portail-entreprises'), 'error');
        }
    }

    private function handle_company_new_form(): void {
        if (!isset($_POST['pe_save_company']) || !isset($_POST['_wpnonce'])) {
            return;
        }

        check_admin_referer('pe_create_company', '_wpnonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Accès refusé.', 'portail-entreprises'));
        }

        $data = [
            'name'          => sanitize_text_field(wp_unslash($_POST['company_name'] ?? '')),
            'siret'         => sanitize_text_field(wp_unslash($_POST['siret'] ?? '')),
            'vat_number'    => sanitize_text_field(wp_unslash($_POST['vat_number'] ?? '')),
            'discount_rate' => (float) ($_POST['discount_rate'] ?? 0),
            'credit_limit'  => (float) ($_POST['credit_limit'] ?? 0),
            'payment_terms' => (int) ($_POST['payment_terms'] ?? 30),
            'status'        => sanitize_key($_POST['status'] ?? 'active'),
        ];

        $manager = PE_Company_Manager::get_instance();
        $result  = $manager->create_company($data);

        if (is_wp_error($result)) {
            add_settings_error('pe_messages', 'pe_error', $result->get_error_message(), 'error');
        } else {
            wp_safe_redirect(admin_url('admin.php?page=portail-b2b&action=edit&company_id=' . $result . '&created=1'));
            exit;
        }
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Accès refusé.', 'portail-entreprises'));
        }

        if (isset($_POST['pe_save_settings']) && isset($_POST['_wpnonce'])) {
            check_admin_referer('pe_save_settings', '_wpnonce');

            $settings = [
                'require_approval_all'  => isset($_POST['require_approval_all']) ? 1 : 0,
                'default_payment_terms' => (int) ($_POST['default_payment_terms'] ?? 30),
            ];

            update_option('pe_settings', $settings);
            add_settings_error('pe_messages', 'pe_updated', __('Paramètres enregistrés.', 'portail-entreprises'), 'updated');
        }

        $settings = get_option('pe_settings', []);
        settings_errors('pe_messages');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Paramètres du Portail B2B', 'portail-entreprises'); ?></h1>
            <form method="post" action="">
                <?php wp_nonce_field('pe_save_settings', '_wpnonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Délai de paiement par défaut (jours)', 'portail-entreprises'); ?></th>
                        <td>
                            <input type="number" name="default_payment_terms"
                                   value="<?php echo esc_attr($settings['default_payment_terms'] ?? 30); ?>"
                                   min="0" max="365" class="small-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Approbation systématique', 'portail-entreprises'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="require_approval_all"
                                       value="1" <?php checked($settings['require_approval_all'] ?? 0, 1); ?> />
                                <?php esc_html_e('Toutes les commandes B2B nécessitent une approbation', 'portail-entreprises'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="pe_save_settings" class="button-primary"
                           value="<?php esc_attr_e('Enregistrer les paramètres', 'portail-entreprises'); ?>" />
                </p>
            </form>
        </div>
        <?php
    }

    public function add_user_b2b_fields(\WP_User $user): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $is_b2b    = PE_Permissions::is_b2b_user((int) $user->ID);
        $b2b_role  = PE_Permissions::get_user_b2b_role((int) $user->ID);
        $company   = PE_Permissions::get_user_company((int) $user->ID);
        $all_roles = PE_Permissions::get_roles();
        $manager   = PE_Company_Manager::get_instance();

        global $wpdb;
        $all_companies = $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}b2b_companies WHERE status = 'active' ORDER BY name ASC"
        );

        include PE_PATH . 'admin/views/user-edit.php';
    }

    public function save_user_b2b_fields(int $user_id): void {
        if (!current_user_can('manage_woocommerce') || !isset($_POST['_wpnonce_b2b'])) {
            return;
        }

        check_admin_referer('pe_save_user_b2b_' . $user_id, '_wpnonce_b2b');

        $user_manager    = PE_User_Manager::get_instance();
        $company_manager = PE_Company_Manager::get_instance();

        $b2b_enabled = isset($_POST['b2b_enabled']) ? 1 : 0;

        if ($b2b_enabled) {
            $user_manager->enable_b2b($user_id);
        } else {
            $user_manager->disable_b2b($user_id);
        }

        $company_id = isset($_POST['b2b_company_id']) ? absint($_POST['b2b_company_id']) : 0;
        $role       = isset($_POST['b2b_role']) ? sanitize_key($_POST['b2b_role']) : 'buyer';

        if ($company_id > 0 && $b2b_enabled) {
            $opts = [
                'budget_monthly'   => isset($_POST['budget_monthly']) && $_POST['budget_monthly'] !== '' ? (float) $_POST['budget_monthly'] : null,
                'budget_annual'    => isset($_POST['budget_annual']) && $_POST['budget_annual'] !== '' ? (float) $_POST['budget_annual'] : null,
                'budget_per_order' => isset($_POST['budget_per_order']) && $_POST['budget_per_order'] !== '' ? (float) $_POST['budget_per_order'] : null,
            ];
            $company_manager->add_user_to_company($user_id, $company_id, $role, $opts);
        }
    }

    public function ajax_admin_add_user_to_company(): void {
        check_ajax_referer('pe_b2b_ajax', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Accès refusé.', 'portail-entreprises')]);
        }

        $user_id    = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $company_id = isset($_POST['company_id']) ? absint($_POST['company_id']) : 0;
        $role       = isset($_POST['role']) ? sanitize_key($_POST['role']) : 'buyer';

        if (!$user_id || !$company_id) {
            wp_send_json_error(['message' => __('Paramètres invalides.', 'portail-entreprises')]);
        }

        $result = PE_Company_Manager::get_instance()->add_user_to_company($user_id, $company_id, $role);

        if ($result) {
            PE_User_Manager::get_instance()->enable_b2b($user_id);
            wp_send_json_success(['message' => __('Utilisateur ajouté à l\'entreprise.', 'portail-entreprises')]);
        } else {
            wp_send_json_error(['message' => __('Erreur lors de l\'ajout.', 'portail-entreprises')]);
        }
    }

    public function ajax_admin_remove_user_from_company(): void {
        check_ajax_referer('pe_b2b_ajax', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Accès refusé.', 'portail-entreprises')]);
        }

        $user_id    = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $company_id = isset($_POST['company_id']) ? absint($_POST['company_id']) : 0;

        if (!$user_id || !$company_id) {
            wp_send_json_error(['message' => __('Paramètres invalides.', 'portail-entreprises')]);
        }

        $result = PE_Company_Manager::get_instance()->remove_user_from_company($user_id, $company_id);

        if ($result) {
            wp_send_json_success(['message' => __('Utilisateur retiré de l\'entreprise.', 'portail-entreprises')]);
        } else {
            wp_send_json_error(['message' => __('Erreur lors de la suppression.', 'portail-entreprises')]);
        }
    }

    public function ajax_admin_create_cost_center(): void {
        check_ajax_referer('pe_b2b_ajax', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Accès refusé.', 'portail-entreprises')]);
        }

        $company_id = isset($_POST['company_id']) ? absint($_POST['company_id']) : 0;
        $name       = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $code       = isset($_POST['code']) ? sanitize_text_field(wp_unslash($_POST['code'])) : '';

        if (!$company_id || !$name) {
            wp_send_json_error(['message' => __('Paramètres invalides.', 'portail-entreprises')]);
        }

        $id = PE_Company_Manager::get_instance()->create_cost_center($company_id, $name, $code);

        if ($id > 0) {
            wp_send_json_success(['id' => $id, 'message' => __('Centre de coût créé.', 'portail-entreprises')]);
        } else {
            wp_send_json_error(['message' => __('Erreur lors de la création.', 'portail-entreprises')]);
        }
    }

    public function handle_save_approval_rule(): void {
        if (!isset($_POST['_wpnonce_rule']) || !isset($_POST['company_id'])) {
            wp_die(esc_html__('Paramètres invalides.', 'portail-entreprises'));
        }

        $company_id = absint($_POST['company_id']);
        check_admin_referer('pe_save_approval_rule_' . $company_id, '_wpnonce_rule');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Accès refusé.', 'portail-entreprises'));
        }

        $rule_data = [
            'threshold_min'  => (float) ($_POST['threshold_min'] ?? 0),
            'threshold_max'  => isset($_POST['threshold_max']) && $_POST['threshold_max'] !== '' ? (float) $_POST['threshold_max'] : null,
            'approver_roles' => array_map('sanitize_key', (array) ($_POST['approver_roles'] ?? [])),
            'delay_hours'    => (int) ($_POST['delay_hours'] ?? 24),
        ];

        PE_Company_Manager::get_instance()->save_approval_rule($company_id, $rule_data);

        wp_safe_redirect(admin_url('admin.php?page=portail-b2b&action=edit&company_id=' . $company_id));
        exit;
    }

    public function ajax_admin_delete_approval_rule(): void {
        check_ajax_referer('pe_b2b_ajax', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Accès refusé.', 'portail-entreprises')]);
        }

        $rule_id    = isset($_POST['rule_id']) ? absint($_POST['rule_id']) : 0;
        $company_id = isset($_POST['company_id']) ? absint($_POST['company_id']) : 0;

        if (!$rule_id || !$company_id) {
            wp_send_json_error(['message' => __('Paramètres invalides.', 'portail-entreprises')]);
        }

        $result = PE_Company_Manager::get_instance()->delete_approval_rule($rule_id, $company_id);

        if ($result) {
            wp_send_json_success(['message' => __('Règle supprimée.', 'portail-entreprises')]);
        } else {
            wp_send_json_error(['message' => __('Erreur lors de la suppression.', 'portail-entreprises')]);
        }
    }
}

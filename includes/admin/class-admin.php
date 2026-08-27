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
        add_action('wp_ajax_pe_admin_delete_company', [$this, 'ajax_admin_delete_company']);
        add_action('wp_ajax_pe_admin_search_users', [$this, 'ajax_admin_search_users']);
        add_action('wp_ajax_pe_admin_get_customer_prefill', [$this, 'ajax_admin_get_customer_prefill']);
        add_action('wp_ajax_pe_admin_invite_user_to_company', [$this, 'ajax_admin_invite_user_to_company']);
        add_action('admin_post_pe_save_approval_rule', [$this, 'handle_save_approval_rule']);
        add_action('admin_post_pe_create_company', [$this, 'handle_post_create_company']);
        add_action('admin_post_pe_update_company', [$this, 'handle_post_update_company']);
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

        add_submenu_page(
            'portail-b2b',
            __('Magic Links', 'portail-entreprises'),
            __('Magic Links', 'portail-entreprises'),
            'manage_woocommerce',
            'portail-b2b-magic-links',
            [$this, 'render_magic_links_page']
        );
    }

    public function enqueue_admin_assets(string $hook): void {
        $b2b_pages = ['toplevel_page_portail-b2b', 'portail-b2b_page_portail-b2b-settings', 'portail-b2b_page_portail-b2b-magic-links'];

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
                'confirmDelete'             => __('Êtes-vous sûr de vouloir effectuer cette action ?', 'portail-entreprises'),
                'confirmDeleteCompany'      => __('Êtes-vous sûr de vouloir supprimer la société « %s » ?', 'portail-entreprises'),
                'confirmDeleteCompanyFinal' => __('ATTENTION : cette action est irréversible. Toutes les données associées (utilisateurs, budgets, agences, approbations) seront supprimées. Confirmer définitivement ?', 'portail-entreprises'),
                'processing'                => __('Traitement en cours...', 'portail-entreprises'),
                'error'                     => __('Une erreur est survenue.', 'portail-entreprises'),
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
            include PE_PATH . 'admin/views/company-edit.php';
        } elseif ('new' === $action) {
            include PE_PATH . 'admin/views/company-edit.php';
        } else {
            include PE_PATH . 'admin/views/companies.php';
        }
    }

    /**
     * Traitement POST création société — déclenché via admin_post_ avant envoi des headers.
     */
    public function handle_post_create_company(): void {
        check_admin_referer('pe_create_company', '_wpnonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Accès refusé.', 'portail-entreprises'));
        }

        $data = [
            'name'                  => sanitize_text_field(wp_unslash($_POST['company_name'] ?? '')),
            'customer_code'         => sanitize_text_field(wp_unslash($_POST['customer_code'] ?? '')),
            'siret'                 => sanitize_text_field(wp_unslash($_POST['siret'] ?? '')),
            'vat_number'            => sanitize_text_field(wp_unslash($_POST['vat_number'] ?? '')),
            'naf_code'              => sanitize_text_field(wp_unslash($_POST['naf_code'] ?? '')),
            'phone'                 => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
            'fax'                   => sanitize_text_field(wp_unslash($_POST['fax'] ?? '')),
            'contact_function'      => sanitize_text_field(wp_unslash($_POST['contact_function'] ?? '')),
            'contact_first_name'    => sanitize_text_field(wp_unslash($_POST['contact_first_name'] ?? '')),
            'contact_last_name'     => sanitize_text_field(wp_unslash($_POST['contact_last_name'] ?? '')),
            'payment_method_code'   => sanitize_text_field(wp_unslash($_POST['payment_method_code'] ?? '')),
            'payment_method_label'  => sanitize_text_field(wp_unslash($_POST['payment_method_label'] ?? '')),
            'category'              => sanitize_text_field(wp_unslash($_POST['category'] ?? '')),
            'activity'              => sanitize_text_field(wp_unslash($_POST['activity'] ?? '')),
            'comments'              => sanitize_textarea_field(wp_unslash($_POST['comments'] ?? '')),
            'discount_rate'         => (float) ($_POST['discount_rate'] ?? 0),
            'credit_limit'          => (float) ($_POST['credit_limit'] ?? 0),
            'payment_terms'         => (int) ($_POST['payment_terms'] ?? 30),
            'status'                => sanitize_key($_POST['status'] ?? 'active'),
            'orders_blocked'        => isset($_POST['orders_blocked']) ? 1 : 0,
            'modules_enabled'       => array_map('sanitize_key', (array) ($_POST['modules_enabled'] ?? [])),
            'billing_address' => [
                'address_1' => sanitize_text_field(wp_unslash($_POST['billing_address_1'] ?? '')),
                'address_2' => sanitize_text_field(wp_unslash($_POST['billing_address_2'] ?? '')),
                'city'      => sanitize_text_field(wp_unslash($_POST['billing_city'] ?? '')),
                'postcode'  => sanitize_text_field(wp_unslash($_POST['billing_postcode'] ?? '')),
                'country'   => sanitize_text_field(wp_unslash($_POST['billing_country'] ?? '')),
            ],
            'shipping_address' => [
                'address_1' => sanitize_text_field(wp_unslash($_POST['shipping_address_1'] ?? '')),
                'address_2' => sanitize_text_field(wp_unslash($_POST['shipping_address_2'] ?? '')),
                'city'      => sanitize_text_field(wp_unslash($_POST['shipping_city'] ?? '')),
                'postcode'  => sanitize_text_field(wp_unslash($_POST['shipping_postcode'] ?? '')),
                'country'   => sanitize_text_field(wp_unslash($_POST['shipping_country'] ?? '')),
            ],
        ];

        if (class_exists('TDW_B2B_Taxonomies')) {
            $data['tdw_profile_slug'] = sanitize_key(wp_unslash($_POST['tdw_profile_slug'] ?? ''));
        }

        $result = PE_Company_Manager::get_instance()->create_company($data);

        if (is_wp_error($result)) {
            $error_msg = urlencode($result->get_error_message());
            wp_safe_redirect(admin_url('admin.php?page=portail-b2b&action=new&pe_error=' . $error_msg));
        } else {
            wp_safe_redirect(admin_url('admin.php?page=portail-b2b&action=edit&company_id=' . $result . '&pe_notice=created'));
        }
        exit;
    }

    /**
     * Traitement POST mise à jour société — déclenché via admin_post_ avant envoi des headers.
     */
    public function handle_post_update_company(): void {
        $company_id = isset($_POST['company_id']) ? absint($_POST['company_id']) : 0;

        if (!$company_id) {
            wp_die(esc_html__('ID de société invalide.', 'portail-entreprises'));
        }

        check_admin_referer('pe_save_company_' . $company_id, '_wpnonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Accès refusé.', 'portail-entreprises'));
        }

        $data = [
            'name'                  => sanitize_text_field(wp_unslash($_POST['company_name'] ?? '')),
            'customer_code'         => sanitize_text_field(wp_unslash($_POST['customer_code'] ?? '')),
            'siret'                 => sanitize_text_field(wp_unslash($_POST['siret'] ?? '')),
            'vat_number'            => sanitize_text_field(wp_unslash($_POST['vat_number'] ?? '')),
            'naf_code'              => sanitize_text_field(wp_unslash($_POST['naf_code'] ?? '')),
            'phone'                 => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
            'fax'                   => sanitize_text_field(wp_unslash($_POST['fax'] ?? '')),
            'contact_function'      => sanitize_text_field(wp_unslash($_POST['contact_function'] ?? '')),
            'contact_first_name'    => sanitize_text_field(wp_unslash($_POST['contact_first_name'] ?? '')),
            'contact_last_name'     => sanitize_text_field(wp_unslash($_POST['contact_last_name'] ?? '')),
            'payment_method_code'   => sanitize_text_field(wp_unslash($_POST['payment_method_code'] ?? '')),
            'payment_method_label'  => sanitize_text_field(wp_unslash($_POST['payment_method_label'] ?? '')),
            'category'              => sanitize_text_field(wp_unslash($_POST['category'] ?? '')),
            'activity'              => sanitize_text_field(wp_unslash($_POST['activity'] ?? '')),
            'comments'              => sanitize_textarea_field(wp_unslash($_POST['comments'] ?? '')),
            'discount_rate'         => (float) ($_POST['discount_rate'] ?? 0),
            'credit_limit'          => (float) ($_POST['credit_limit'] ?? 0),
            'payment_terms'         => (int) ($_POST['payment_terms'] ?? 30),
            'status'                => sanitize_key($_POST['status'] ?? 'active'),
            'orders_blocked'        => isset($_POST['orders_blocked']) ? 1 : 0,
            'modules_enabled'       => array_map('sanitize_key', (array) ($_POST['modules_enabled'] ?? [])),
            'billing_address' => [
                'address_1' => sanitize_text_field(wp_unslash($_POST['billing_address_1'] ?? '')),
                'address_2' => sanitize_text_field(wp_unslash($_POST['billing_address_2'] ?? '')),
                'city'      => sanitize_text_field(wp_unslash($_POST['billing_city'] ?? '')),
                'postcode'  => sanitize_text_field(wp_unslash($_POST['billing_postcode'] ?? '')),
                'country'   => sanitize_text_field(wp_unslash($_POST['billing_country'] ?? '')),
            ],
            'shipping_address' => [
                'address_1' => sanitize_text_field(wp_unslash($_POST['shipping_address_1'] ?? '')),
                'address_2' => sanitize_text_field(wp_unslash($_POST['shipping_address_2'] ?? '')),
                'city'      => sanitize_text_field(wp_unslash($_POST['shipping_city'] ?? '')),
                'postcode'  => sanitize_text_field(wp_unslash($_POST['shipping_postcode'] ?? '')),
                'country'   => sanitize_text_field(wp_unslash($_POST['shipping_country'] ?? '')),
            ],
        ];

        if (class_exists('TDW_B2B_Taxonomies')) {
            $data['tdw_profile_slug'] = sanitize_key(wp_unslash($_POST['tdw_profile_slug'] ?? ''));
        }

        $result = PE_Company_Manager::get_instance()->update_company($company_id, $data);

        if ($result) {
            wp_safe_redirect(admin_url('admin.php?page=portail-b2b&action=edit&company_id=' . $company_id . '&pe_notice=updated'));
        } else {
            wp_safe_redirect(admin_url('admin.php?page=portail-b2b&action=edit&company_id=' . $company_id . '&pe_error=' . urlencode(__('Erreur lors de la mise à jour.', 'portail-entreprises'))));
        }
        exit;
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Accès refusé.', 'portail-entreprises'));
        }

        if (isset($_POST['pe_save_settings']) && isset($_POST['_wpnonce'])) {
            check_admin_referer('pe_save_settings', '_wpnonce');

            $ml_validity = sanitize_key($_POST['magic_link_validity'] ?? '7d');
            if (!array_key_exists($ml_validity, PE_Magic_Link_Manager::DURATIONS) && 'custom' !== $ml_validity) {
                $ml_validity = '7d';
            }

            $from_email = sanitize_email($_POST['from_email'] ?? '');

            $settings = [
                'require_approval_all'             => isset($_POST['require_approval_all']) ? 1 : 0,
                'default_payment_terms'            => (int) ($_POST['default_payment_terms'] ?? 30),
                'from_email'                       => is_email($from_email) ? $from_email : '',
                'magic_link_enabled'               => isset($_POST['magic_link_enabled']) ? 1 : 0,
                'magic_link_validity'              => $ml_validity,
                'magic_link_validity_custom_hours' => max(1, (int) ($_POST['magic_link_validity_custom_hours'] ?? 168)),
                'delete_data_on_uninstall'         => isset($_POST['delete_data_on_uninstall']) ? 1 : 0,
            ];

            update_option('pe_settings', $settings);
            add_settings_error('pe_messages', 'pe_updated', __('Paramètres enregistrés.', 'portail-entreprises'), 'updated');
        }

        $settings = get_option('pe_settings', []);
        settings_errors('pe_messages');
        $ml_validity = $settings['magic_link_validity'] ?? '7d';
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
                    <tr>
                        <th scope="row"><?php esc_html_e('Adresse e-mail d\'envoi', 'portail-entreprises'); ?></th>
                        <td>
                            <?php
                            // Adresse d'expéditeur native de WordPress (wordpress@<domaine du site>),
                            // utilisée par défaut lorsque le champ est laissé vide.
                            $site_host = wp_parse_url(network_home_url(), PHP_URL_HOST);
                            if (is_string($site_host) && 'www.' === substr($site_host, 0, 4)) {
                                $site_host = substr($site_host, 4);
                            }
                            $default_from = 'wordpress@' . (string) $site_host;
                            ?>
                            <input type="email" name="from_email"
                                   value="<?php echo esc_attr($settings['from_email'] ?? ''); ?>"
                                   placeholder="<?php echo esc_attr($default_from); ?>"
                                   class="regular-text" />
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: default sender email address on the site domain */
                                    esc_html__('Adresse utilisée comme expéditeur des e-mails du portail. Le nom d\'expéditeur affiché est celui du site. Laissez vide pour utiliser l\'adresse native de WordPress (%s).', 'portail-entreprises'),
                                    esc_html($default_from)
                                );
                                ?>
                            </p>
                            <p class="description" style="color:#b32d2e;">
                                <?php esc_html_e('Important : utilisez une adresse sur le domaine de votre site (ex. contact@accud-france.fr). Une adresse externe (Gmail, Outlook…) déclenche la mention « via … » dans la messagerie des destinataires et nuit à la délivrabilité, car votre serveur n\'est pas autorisé à envoyer au nom de ce domaine.', 'portail-entreprises'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Magic Links', 'portail-entreprises'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="magic_link_enabled"
                                       value="1" <?php checked($settings['magic_link_enabled'] ?? 1, 1); ?> />
                                <?php esc_html_e('Activer les liens de validation par e-mail (Magic Links)', 'portail-entreprises'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Permet aux approbateurs de valider ou refuser une demande directement depuis leur e-mail, sans se connecter.', 'portail-entreprises'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Durée de validité des liens', 'portail-entreprises'); ?></th>
                        <td>
                            <select name="magic_link_validity" id="pe-ml-validity">
                                <option value="24h" <?php selected($ml_validity, '24h'); ?>><?php esc_html_e('24 heures', 'portail-entreprises'); ?></option>
                                <option value="48h" <?php selected($ml_validity, '48h'); ?>><?php esc_html_e('48 heures', 'portail-entreprises'); ?></option>
                                <option value="7d"  <?php selected($ml_validity, '7d'); ?>><?php esc_html_e('7 jours', 'portail-entreprises'); ?></option>
                                <option value="14d" <?php selected($ml_validity, '14d'); ?>><?php esc_html_e('14 jours', 'portail-entreprises'); ?></option>
                                <option value="custom" <?php selected($ml_validity, 'custom'); ?>><?php esc_html_e('Personnalisé', 'portail-entreprises'); ?></option>
                            </select>
                            <span id="pe-ml-custom-wrap" style="<?php echo 'custom' === $ml_validity ? '' : 'display:none;'; ?> margin-left:10px;">
                                <input type="number" name="magic_link_validity_custom_hours"
                                       value="<?php echo esc_attr($settings['magic_link_validity_custom_hours'] ?? 168); ?>"
                                       min="1" max="8760" class="small-text" />
                                <?php esc_html_e('heures', 'portail-entreprises'); ?>
                            </span>
                            <script>
                            document.getElementById('pe-ml-validity').addEventListener('change', function() {
                                document.getElementById('pe-ml-custom-wrap').style.display = this.value === 'custom' ? '' : 'none';
                            });
                            </script>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Suppression des données', 'portail-entreprises'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="delete_data_on_uninstall"
                                       value="1" <?php checked($settings['delete_data_on_uninstall'] ?? 0, 1); ?> />
                                <?php esc_html_e('Supprimer toutes les données à la désinstallation du plugin', 'portail-entreprises'); ?>
                            </label>
                            <p class="description" style="color:#b32d2e;">
                                <?php esc_html_e('⚠️ Si cette case est DÉCOCHÉE (recommandé), vos sociétés, utilisateurs, budgets et historiques sont CONSERVÉS même si vous supprimez le plugin. Vous pourrez le réinstaller sans rien perdre. Ne cochez cette case que si vous voulez tout effacer définitivement.', 'portail-entreprises'); ?>
                            </p>
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

    public function render_magic_links_page(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Accès refusé.', 'portail-entreprises'));
        }

        if (!class_exists('PE_Magic_Link_Manager')) {
            echo '<div class="wrap"><p>' . esc_html__('Module Magic Links non disponible.', 'portail-entreprises') . '</p></div>';
            return;
        }

        $status_filter = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $tokens        = PE_Magic_Link_Manager::get_instance()->get_tokens($status_filter, 200);

        $status_labels = [
            'active'  => __('Actif', 'portail-entreprises'),
            'used'    => __('Utilisé', 'portail-entreprises'),
            'expired' => __('Expiré', 'portail-entreprises'),
            'revoked' => __('Révoqué', 'portail-entreprises'),
        ];
        $status_colors = [
            'active'  => '#28a745',
            'used'    => '#2d6ebd',
            'expired' => '#888',
            'revoked' => '#dc3545',
        ];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Magic Links — Jetons d\'approbation', 'portail-entreprises'); ?></h1>

            <ul class="subsubsub" style="margin-bottom:12px;">
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=portail-b2b-magic-links')); ?>" <?php echo '' === $status_filter ? 'class="current"' : ''; ?>><?php esc_html_e('Tous', 'portail-entreprises'); ?></a> |</li>
                <?php foreach ($status_labels as $s => $label) : ?>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=portail-b2b-magic-links&status=' . $s)); ?>" <?php echo $s === $status_filter ? 'class="current"' : ''; ?>><?php echo esc_html($label); ?></a><?php echo $s !== array_key_last($status_labels) ? ' |' : ''; ?></li>
                <?php endforeach; ?>
            </ul>

            <table class="wp-list-table widefat fixed striped" style="font-size:13px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Approbateur', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Demande', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Commande', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Statut', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Créé le', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Expire le', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Utilisé le', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Action effectuée', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Actions', 'portail-entreprises'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tokens)) : ?>
                    <tr><td colspan="10" style="text-align:center;"><?php esc_html_e('Aucun jeton trouvé.', 'portail-entreprises'); ?></td></tr>
                    <?php else : ?>
                    <?php foreach ($tokens as $token) : ?>
                    <tr data-token-id="<?php echo esc_attr((string) $token->id); ?>" data-request-id="<?php echo esc_attr((string) $token->request_id); ?>">
                        <td><?php echo esc_html((string) $token->id); ?></td>
                        <td><?php echo esc_html($token->approver_name ?: '—'); ?></td>
                        <td>#<?php echo esc_html((string) $token->request_id); ?></td>
                        <td><?php echo $token->order_id ? '#' . esc_html((string) $token->order_id) : '—'; ?></td>
                        <td><span style="color:<?php echo esc_attr($status_colors[$token->status] ?? '#333'); ?>;font-weight:600;"><?php echo esc_html($status_labels[$token->status] ?? $token->status); ?></span></td>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' H:i', strtotime($token->created_at))); ?></td>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' H:i', strtotime($token->expires_at))); ?></td>
                        <td><?php echo $token->used_at ? esc_html(date_i18n(get_option('date_format') . ' H:i', strtotime($token->used_at))) : '—'; ?></td>
                        <td><?php echo $token->used_action ? esc_html($token->used_action) : '—'; ?></td>
                        <td>
                            <?php if ('active' === $token->status) : ?>
                            <button class="button button-small pe-ml-revoke-btn"
                                    data-token-id="<?php echo esc_attr((string) $token->id); ?>">
                                <?php esc_html_e('Révoquer', 'portail-entreprises'); ?>
                            </button>
                            <?php endif; ?>
                            <?php if ('pending' === ($token->request_status ?? '') ) : ?>
                            <button class="button button-small pe-ml-resend-btn"
                                    data-request-id="<?php echo esc_attr((string) $token->request_id); ?>"
                                    style="margin-left:4px;">
                                <?php esc_html_e('Renvoyer l\'e-mail', 'portail-entreprises'); ?>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <script>
        jQuery(function($) {
            var nonce = '<?php echo esc_js(wp_create_nonce('pe_b2b_ajax')); ?>';

            $(document).on('click', '.pe-ml-revoke-btn', function() {
                if (!confirm('<?php esc_js(esc_html__('Révoquer ce jeton ?', 'portail-entreprises')); ?>')) return;
                var $btn = $(this), tokenId = $btn.data('token-id');
                $.post(ajaxurl, { action: 'pe_revoke_token', nonce: nonce, token_id: tokenId }, function(res) {
                    if (res.success) {
                        $btn.closest('tr').find('span').first().text('<?php echo esc_js(esc_html__('Révoqué', 'portail-entreprises')); ?>').css('color', '#dc3545');
                        $btn.remove();
                    } else {
                        alert(res.data.message);
                    }
                });
            });

            $(document).on('click', '.pe-ml-resend-btn', function() {
                var $btn = $(this), requestId = $btn.data('request-id');
                $btn.prop('disabled', true).text('<?php echo esc_js(esc_html__('Envoi…', 'portail-entreprises')); ?>');
                $.post(ajaxurl, { action: 'pe_resend_approval_email', nonce: nonce, request_id: requestId }, function(res) {
                    if (res.success) {
                        alert(res.data.message);
                        $btn.text('<?php echo esc_js(esc_html__('Renvoyer l\'e-mail', 'portail-entreprises')); ?>').prop('disabled', false);
                    } else {
                        alert(res.data.message);
                        $btn.prop('disabled', false).text('<?php echo esc_js(esc_html__('Renvoyer l\'e-mail', 'portail-entreprises')); ?>');
                    }
                });
            });
        });
        </script>
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

        $opts = [
            'budget_monthly'   => isset($_POST['budget_monthly']) && $_POST['budget_monthly'] !== '' ? (float) $_POST['budget_monthly'] : null,
            'budget_annual'    => isset($_POST['budget_annual']) && $_POST['budget_annual'] !== '' ? (float) $_POST['budget_annual'] : null,
            'budget_per_order' => isset($_POST['budget_per_order']) && $_POST['budget_per_order'] !== '' ? (float) $_POST['budget_per_order'] : null,
        ];

        $result = PE_Company_Manager::get_instance()->add_user_to_company($user_id, $company_id, $role, $opts);

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

    public function ajax_admin_delete_company(): void {
        check_ajax_referer('pe_b2b_ajax', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Accès refusé.', 'portail-entreprises')]);
        }

        $company_id = isset($_POST['company_id']) ? absint($_POST['company_id']) : 0;

        if (!$company_id) {
            wp_send_json_error(['message' => __('Société invalide.', 'portail-entreprises')]);
        }

        $result = PE_Company_Manager::get_instance()->delete_company($company_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message'  => __('Société supprimée avec succès.', 'portail-entreprises'),
            'redirect' => admin_url('admin.php?page=portail-b2b'),
        ]);
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

    public function ajax_admin_search_users(): void {
        check_ajax_referer('pe_b2b_ajax', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Accès refusé.', 'portail-entreprises')]);
        }

        $search     = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $company_id = isset($_POST['company_id']) ? absint($_POST['company_id']) : 0;

        if (strlen($search) < 2) {
            wp_send_json_success(['users' => []]);
        }

        $existing_ids = [];
        if ($company_id) {
            global $wpdb;
            $existing_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT user_id FROM {$wpdb->prefix}b2b_user_company WHERE company_id = %d",
                    $company_id
                )
            );
        }

        $users = get_users([
            'search'         => '*' . $search . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'exclude'        => array_map('intval', $existing_ids),
            'number'         => 10,
            'fields'         => ['ID', 'display_name', 'user_email'],
        ]);

        $results = array_map(fn($u) => [
            'id'    => (int) $u->ID,
            'name'  => $u->display_name,
            'email' => $u->user_email,
        ], $users);

        wp_send_json_success(['users' => array_values($results)]);
    }

    /**
     * Renvoie les coordonnées connues d'un client existant (utilisateur WordPress/WooCommerce)
     * afin de pré-remplir le contact et les adresses d'une société — utilisé lorsqu'on
     * rattache un client déjà connu au lieu de ressaisir ses informations.
     *
     * Si ce client est déjà membre d'une autre société (sa « fiche client »), les
     * informations propres à cette société (SIRET, TVA, code NAF, code client,
     * règlement, remise…) sont également renvoyées et priment sur celles de son
     * compte WooCommerce, plus complètes et déjà validées pour la facturation.
     */
    public function ajax_admin_get_customer_prefill(): void {
        check_ajax_referer('pe_b2b_ajax', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Accès refusé.', 'portail-entreprises')]);
        }

        $user_id             = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $exclude_company_id  = isset($_POST['exclude_company_id']) ? absint($_POST['exclude_company_id']) : 0;
        $user                = $user_id ? get_userdata($user_id) : null;

        if (!$user) {
            wp_send_json_error(['message' => __('Utilisateur introuvable.', 'portail-entreprises')]);
        }

        $billing_address  = ['address_1' => '', 'address_2' => '', 'city' => '', 'postcode' => '', 'country' => ''];
        $shipping_address = $billing_address;
        $phone            = '';
        $fax              = '';
        $company_name     = '';

        if (class_exists('WC_Customer')) {
            $customer = new \WC_Customer($user_id);

            $billing_address = [
                'address_1' => $customer->get_billing_address_1(),
                'address_2' => $customer->get_billing_address_2(),
                'city'      => $customer->get_billing_city(),
                'postcode'  => $customer->get_billing_postcode(),
                'country'   => $customer->get_billing_country(),
            ];
            $shipping_address = [
                'address_1' => $customer->get_shipping_address_1(),
                'address_2' => $customer->get_shipping_address_2(),
                'city'      => $customer->get_shipping_city(),
                'postcode'  => $customer->get_shipping_postcode(),
                'country'   => $customer->get_shipping_country(),
            ];
            $phone        = $customer->get_billing_phone();
            $company_name = $customer->get_billing_company();
        }

        $siret                = '';
        $vat_number            = '';
        $naf_code              = '';
        $customer_code         = '';
        $category              = '';
        $activity              = '';
        $payment_terms         = null;
        $payment_method_code   = '';
        $payment_method_label  = '';
        $discount_rate         = null;
        $credit_limit          = null;

        $existing_company = PE_Permissions::get_user_company($user_id);

        if ($existing_company && (int) $existing_company->id !== $exclude_company_id) {
            $company_name         = $existing_company->name ?: $company_name;
            $siret                = $existing_company->siret;
            $vat_number            = $existing_company->vat_number;
            $naf_code              = $existing_company->naf_code;
            $customer_code         = $existing_company->customer_code;
            $category              = $existing_company->category;
            $activity              = $existing_company->activity;
            $payment_terms         = $existing_company->payment_terms;
            $payment_method_code   = $existing_company->payment_method_code;
            $payment_method_label  = $existing_company->payment_method_label;
            $discount_rate         = $existing_company->discount_rate;
            $credit_limit          = $existing_company->credit_limit;
            $phone                 = $existing_company->phone ?: $phone;
            $fax                   = $existing_company->fax ?: $fax;

            $existing_billing  = array_filter((array) json_decode($existing_company->billing_address ?? '', true));
            $existing_shipping = array_filter((array) json_decode($existing_company->shipping_address ?? '', true));
            if ($existing_billing) {
                $billing_address = array_merge($billing_address, $existing_billing);
            }
            if ($existing_shipping) {
                $shipping_address = array_merge($shipping_address, $existing_shipping);
            }
        }

        wp_send_json_success([
            'first_name'            => $user->first_name,
            'last_name'             => $user->last_name,
            'phone'                 => $phone,
            'fax'                   => $fax,
            'company_name'          => $company_name,
            'siret'                 => $siret,
            'vat_number'            => $vat_number,
            'naf_code'              => $naf_code,
            'customer_code'         => $customer_code,
            'category'              => $category,
            'activity'              => $activity,
            'payment_terms'         => $payment_terms,
            'payment_method_code'   => $payment_method_code,
            'payment_method_label'  => $payment_method_label,
            'discount_rate'         => $discount_rate,
            'credit_limit'          => $credit_limit,
            'billing_address'       => $billing_address,
            'shipping_address'      => $shipping_address,
        ]);
    }

    public function ajax_admin_invite_user_to_company(): void {
        check_ajax_referer('pe_b2b_ajax', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Accès refusé.', 'portail-entreprises')]);
        }

        $company_id = isset($_POST['company_id']) ? absint($_POST['company_id']) : 0;
        $email      = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $role       = isset($_POST['role']) ? sanitize_key($_POST['role']) : 'buyer';

        if (!$company_id || !$email) {
            wp_send_json_error(['message' => __('Paramètres invalides.', 'portail-entreprises')]);
        }

        $data = [
            'email'            => $email,
            'first_name'       => isset($_POST['first_name']) ? sanitize_text_field(wp_unslash($_POST['first_name'])) : '',
            'last_name'        => isset($_POST['last_name']) ? sanitize_text_field(wp_unslash($_POST['last_name'])) : '',
            'budget_monthly'   => isset($_POST['budget_monthly']) && $_POST['budget_monthly'] !== '' ? (float) $_POST['budget_monthly'] : null,
            'budget_annual'    => isset($_POST['budget_annual']) && $_POST['budget_annual'] !== '' ? (float) $_POST['budget_annual'] : null,
            'budget_per_order' => isset($_POST['budget_per_order']) && $_POST['budget_per_order'] !== '' ? (float) $_POST['budget_per_order'] : null,
        ];

        $result = PE_User_Manager::get_instance()->create_sub_account($data, $company_id, $role);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Invitation envoyée. L\'utilisateur recevra ses identifiants par e-mail.', 'portail-entreprises'),
        ]);
    }
}

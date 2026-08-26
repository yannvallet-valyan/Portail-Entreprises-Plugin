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

        // Redirige la liste « Commandes » native vers le tableau de bord B2B (utilisateurs B2B).
        add_action('template_redirect', [$this, 'redirect_orders_to_b2b_dashboard']);

        // Rendu des templates des endpoints
        add_action('woocommerce_account_b2b-dashboard_endpoint', [$this, 'render_dashboard']);
        add_action('woocommerce_account_b2b-company_endpoint', [$this, 'render_company']);
        add_action('woocommerce_account_b2b-users_endpoint', [$this, 'render_users']);
        add_action('woocommerce_account_b2b-budgets_endpoint', [$this, 'render_budgets']);
        add_action('woocommerce_account_b2b-approvals_endpoint', [$this, 'render_approvals']);

        // Enqueue des assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets'], 20);

        // Ajout du champ centre de coût au checkout (rôles non-requester)
        add_filter('woocommerce_checkout_fields', [$this, 'add_cost_center_field']);

        // Sauvegarde du centre de coût sur la commande
        add_action('woocommerce_checkout_create_order', [$this, 'save_cost_center_to_order'], 10, 2);

        // Affichage des infos B2B (centre de coût + référence) — discret, sous le total
        // de la commande (page commande + e-mails WooCommerce).
        add_action('woocommerce_order_details_after_order_table', [$this, 'render_b2b_order_meta_below']);
        add_action('woocommerce_email_after_order_table', [$this, 'render_b2b_order_meta_below']);
        add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'display_b2b_order_meta_admin']);

        // AJAX : édition de centre de coût + référence par les managers.
        add_action('wp_ajax_pe_update_order_b2b_meta', [$this, 'ajax_update_order_b2b_meta']);

        // Rendu de l'endpoint dédié view-order pour les managers.
        add_action('woocommerce_account_b2b-order_endpoint', [$this, 'render_b2b_order']);
        add_filter('woocommerce_endpoint_b2b-order_title', fn() => __('Détail de la commande', 'portail-entreprises'));

        // Adresses (facturation + livraison) société : pré-remplissage checkout + protection du profil
        add_filter('woocommerce_checkout_get_value', [$this, 'prefill_checkout_billing_from_company'], 10, 2);
        add_action('woocommerce_checkout_update_customer', [$this, 'restore_company_billing_after_checkout'], 10, 2);

        // Un membre modifie son adresse dans « Mon compte » → remontée vers la société,
        // puis repropagée à tous les autres membres (adresse partagée par la société).
        add_action('woocommerce_customer_save_address', [$this, 'sync_member_address_to_company'], 10, 2);

        // E-mails sortants : expéditeur du portail via le système natif WordPress.
        // - wp_mail_from / wp_mail_from_name : définit l'adresse et le nom d'expéditeur
        //   (uniquement si une adresse est configurée ; sinon comportement natif WP).
        // - phpmailer_init : aligne le Return-Path (enveloppe SMTP) sur l'adresse From,
        //   ce qui évite la mention « via <serveur d'hébergement> » dans Gmail.
        add_filter('wp_mail_from', [$this, 'filter_mail_from']);
        add_filter('wp_mail_from_name', [$this, 'filter_mail_from_name']);
        add_action('phpmailer_init', [$this, 'align_mail_return_path']);

        // Devis (wc-quotes-woodmart) : les conditions de règlement configurées sur
        // la société du client (page « Modifier la société ») priment sur la valeur
        // par défaut du client / la valeur globale. Priorité 20 = exécuté après le
        // filtre du plugin wc-quotes-deadline-manager (priorité 10), afin de
        // pouvoir écraser sa valeur quand la société a une configuration propre.
        add_filter('wcq_payment_terms', [$this, 'filter_wcq_payment_terms'], 20, 2);
        add_filter('wcq_payment_days', [$this, 'filter_wcq_payment_days'], 20, 2);

        // Initialisation des modules
        PE_Budget_Manager::get_instance()->init();
        PE_Approval_Manager::get_instance()->init();
        PE_Magic_Link_Manager::get_instance()->init();
        PE_Order_Visibility::get_instance()->init();

        // Backfill unique des rôles de profil sur les membres existants.
        add_action('admin_init', [$this, 'maybe_backfill_profile_roles']);

        // Admin
        if (is_admin()) {
            require_once PE_PATH . 'includes/admin/class-admin.php';
            PE_Admin::get_instance();
        }
    }

    /**
     * Adresse e-mail d'expéditeur configurée dans les paramètres du portail.
     * Chaîne vide si aucune adresse valide n'est définie.
     */
    private function get_configured_from_email(): string {
        $settings   = get_option('pe_settings', []);
        $from_email = isset($settings['from_email']) ? trim((string) $settings['from_email']) : '';

        return is_email($from_email) ? $from_email : '';
    }

    /**
     * Filtre wp_mail_from : remplace l'adresse d'expéditeur par celle configurée.
     * Si aucune adresse n'est définie, conserve la valeur native de WordPress
     * (par défaut wordpress@<domaine-du-site>).
     *
     * @param string $from Adresse d'expéditeur native fournie par WordPress.
     */
    public function filter_mail_from(string $from): string {
        $configured = $this->get_configured_from_email();
        return '' !== $configured ? $configured : $from;
    }

    /**
     * Filtre wp_mail_from_name : utilise le nom du site comme nom d'expéditeur
     * lorsqu'une adresse d'envoi du portail est configurée. Sinon, conserve la
     * valeur native de WordPress.
     *
     * @param string $name Nom d'expéditeur natif fourni par WordPress.
     */
    public function filter_mail_from_name(string $name): string {
        if ('' === $this->get_configured_from_email()) {
            return $name;
        }

        $blogname = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
        return '' !== $blogname ? $blogname : $name;
    }

    /**
     * Aligne le Return-Path (expéditeur d'enveloppe SMTP) sur l'adresse From.
     *
     * Sans cet alignement, l'hébergeur (ex. OVH) force un Return-Path sur son
     * propre domaine de cluster, ce qui pousse Gmail à afficher « via
     * <serveur> ». En faisant correspondre l'enveloppe à l'adresse From, la
     * mention disparaît dès lors que le SPF du domaine autorise le serveur
     * d'envoi (cas normal lorsque le site et l'adresse sont sur le même domaine).
     *
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer Instance PHPMailer de WordPress.
     */
    public function align_mail_return_path($phpmailer): void {
        if (is_object($phpmailer) && !empty($phpmailer->From)) {
            $phpmailer->Sender = $phpmailer->From;
        }
    }

    /**
     * Filtre wcq_payment_terms (wc-quotes-woodmart) : si le client appartient à
     * une société dont le « Libellé règlement » est renseigné, ce libellé prime
     * sur la valeur par défaut (client / globale) pour l'affichage des devis.
     *
     * @param string $terms       Conditions de règlement déterminées en amont.
     * @param int    $customer_id ID de l'utilisateur client du devis.
     */
    public function filter_wcq_payment_terms(string $terms, int $customer_id = 0): string {
        if ($customer_id <= 0) {
            return $terms;
        }

        $company = PE_Permissions::get_user_company($customer_id);
        if ($company && !empty($company->payment_method_label)) {
            return $company->payment_method_label;
        }

        return $terms;
    }

    /**
     * Filtre wcq_payment_days (wc-quotes-woodmart) : si le client appartient à
     * une société avec un délai de paiement configuré, ce délai prime sur la
     * valeur par défaut (client / globale) pour le calcul de la date d'échéance.
     *
     * @param int $days        Nombre de jours déterminé en amont.
     * @param int $customer_id ID de l'utilisateur client du devis.
     */
    public function filter_wcq_payment_days(int $days, int $customer_id = 0): int {
        if ($customer_id <= 0) {
            return $days;
        }

        $company = PE_Permissions::get_user_company($customer_id);
        if ($company && isset($company->payment_terms) && (int) $company->payment_terms > 0) {
            return (int) $company->payment_terms;
        }

        return $days;
    }

    /**
     * Synchronise une seule fois le rôle WordPress des membres existants avec
     * le profil de remise de leur société. S'exécute en admin (tous les plugins
     * et rôles sont alors chargés) et ne se relance pas une fois terminé.
     */
    public function maybe_backfill_profile_roles(): void {
        if (get_option('pe_profile_roles_synced')) {
            return;
        }

        // Sans le plugin de profils, rien à synchroniser : on marque comme fait.
        if (!class_exists('TDW_B2B_Taxonomies')) {
            update_option('pe_profile_roles_synced', 1, false);
            return;
        }

        global $wpdb;
        $company_ids = $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}b2b_companies WHERE tdw_profile_slug IS NOT NULL AND tdw_profile_slug <> ''"
        );

        $manager = PE_Company_Manager::get_instance();
        foreach ($company_ids as $company_id) {
            $manager->sync_profile_role_to_members((int) $company_id);
        }

        update_option('pe_profile_roles_synced', 1, false);
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
            'b2b-order',
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

    /**
     * Redirige l'endpoint « Commandes » (mon-compte/orders) vers le tableau de bord B2B
     * pour les utilisateurs B2B, lorsque le plugin est actif.
     */
    public function redirect_orders_to_b2b_dashboard(): void {
        if (is_admin() || !function_exists('is_account_page') || !is_account_page()) {
            return;
        }
        if (!is_wc_endpoint_url('orders')) {
            return;
        }

        $user_id = get_current_user_id();
        if (!$user_id || !PE_Permissions::is_b2b_user($user_id)) {
            return;
        }

        // Atterrit directement sur la section « Commandes » du tableau de bord.
        wp_safe_redirect(wc_get_account_endpoint_url('b2b-dashboard') . '#pe-commandes');
        exit;
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

        // Pas de restriction de page : le mini-panier / panier flottant WoodMart
        // (et son blocage checkout) apparaît sur toutes les pages front, il faut
        // donc charger le CSS/JS partout pour les utilisateurs B2B.

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

        // Checkout blocking data for JS — evaluated on ALL pages so the mini-cart is also updated.
        $checkout_blocked      = false;
        $checkout_block_reason = '';
        $approval_button_html  = '';

        if (is_user_logged_in() && class_exists('PE_Budget_Manager')) {
            $reason = PE_Budget_Manager::get_instance()->get_checkout_block_reason();
            if (true !== $reason) {
                $checkout_blocked      = true;
                $checkout_block_reason = $reason;
                if (class_exists('PE_Approval_Manager')) {
                    $approval_button_html = PE_Approval_Manager::get_instance()->get_approval_button_html('cart');
                }
            }
        }

        // Nombre d'approbations en attente (pastille du menu « Approbations »).
        $pending_approvals = 0;
        if (class_exists('PE_Approval_Manager')) {
            $pending_approvals = PE_Approval_Manager::get_instance()->get_pending_count_for_user($user_id);
        }

        wp_localize_script('pe-b2b-portal', 'peB2B', [
            'ajaxUrl'            => admin_url('admin-ajax.php'),
            'nonce'              => wp_create_nonce('pe_b2b_ajax'),
            'checkoutBlocked'    => $checkout_blocked,
            'checkoutBlockMsg'   => $checkout_block_reason,
            'approvalButtonHtml' => $approval_button_html,
            'isCheckout'         => is_checkout(),
            'cartUrl'            => wc_get_cart_url(),
            'pendingApprovals'   => $pending_approvals,
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
     * Affiche le centre de coût et la référence de façon discrète, sous le tableau
     * de commande (page « Détails de la commande » et e-mails WooCommerce).
     *
     * @param mixed $order Commande (objet WC_Order attendu).
     */
    public function render_b2b_order_meta_below($order): void {
        if (!$order instanceof \WC_Order) {
            return;
        }

        $cost_center = $order->get_meta('_b2b_cost_center_label');
        $reference   = $order->get_meta('_b2b_personal_reference');

        if (empty($cost_center) && empty($reference)) {
            return;
        }

        // Styles inline (compatibles e-mails) : discret, petit, gris.
        echo '<div class="pe-order-b2b-meta" style="margin:6px 0 20px;font-size:12px;line-height:1.6;color:#999;">';
        if (!empty($cost_center)) {
            echo '<div><span style="color:#999;">' . esc_html__('Centre de coût :', 'portail-entreprises') . '</span> '
                . esc_html($cost_center) . '</div>';
        }
        if (!empty($reference)) {
            echo '<div><span style="color:#999;">' . esc_html__('Votre référence :', 'portail-entreprises') . '</span> '
                . esc_html($reference) . '</div>';
        }
        echo '</div>';
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
     * AJAX : met à jour le centre de coût (texte libre) et la référence d'une commande.
     * Autorisé au propriétaire de la commande, et aux managers pour les commandes
     * visibles de leur entreprise.
     */
    public function ajax_update_order_b2b_meta(): void {
        check_ajax_referer('pe_b2b_ajax', 'nonce');

        $user_id  = get_current_user_id();
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;

        if (!$user_id || !$order_id) {
            wp_send_json_error(['message' => __('Paramètres invalides.', 'portail-entreprises')]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => __('Commande introuvable.', 'portail-entreprises')]);
        }

        $order_user = (int) $order->get_customer_id();
        $role       = PE_Permissions::get_user_b2b_role($user_id);
        $is_manager = in_array($role, ['company_admin', 'purchase_manager'], true);
        $is_own     = ($order_user === $user_id);

        if (!$is_own && !$is_manager) {
            wp_send_json_error(['message' => __('Permission refusée.', 'portail-entreprises')]);
        }

        $company = PE_Permissions::get_user_company($user_id);
        if (!$company || !PE_Permissions::user_belongs_to_company($order_user, (int) $company->id)) {
            wp_send_json_error(['message' => __('Cette commande n\'appartient pas à votre entreprise.', 'portail-entreprises')]);
        }

        // Les managers restent soumis aux règles de visibilité (le propriétaire édite toujours la sienne).
        if (!$is_own && !PE_Order_Visibility::get_instance()->can_view($user_id, $order_user)) {
            wp_send_json_error(['message' => __('Vous n\'êtes pas autorisé à accéder à cette commande.', 'portail-entreprises')]);
        }

        $reference        = isset($_POST['reference']) ? sanitize_text_field(wp_unslash($_POST['reference'])) : '';
        $cost_center_text = isset($_POST['cost_center_text']) ? sanitize_text_field(wp_unslash($_POST['cost_center_text'])) : '';

        // Référence.
        if ('' !== $reference) {
            $order->update_meta_data('_b2b_personal_reference', $reference);
        } else {
            $order->delete_meta_data('_b2b_personal_reference');
        }

        // Centre de coût (texte libre, cohérent avec la saisie à la création).
        if ('' !== $cost_center_text) {
            $order->update_meta_data('_b2b_cost_center_label', $cost_center_text);
        } else {
            $order->delete_meta_data('_b2b_cost_center_label');
        }
        $order->delete_meta_data('_b2b_cost_center_id');

        $order->save();

        PE_Audit_Log::get_instance()->log(
            $user_id,
            (int) $company->id,
            'update_order_b2b_meta',
            'order',
            $order_id,
            ['cost_center' => $cost_center_text, 'reference' => $reference]
        );

        wp_send_json_success([
            'message'     => __('Informations mises à jour.', 'portail-entreprises'),
            'cost_center' => $cost_center_text,
            'reference'   => $reference,
        ]);
    }

    /**
     * Affiche la modale d'édition du centre de coût (texte libre) et de la référence.
     * Partagée entre la page Approbations et le Tableau de bord B2B.
     */
    public static function render_b2b_meta_modal(): void {
        ?>
        <div id="pe-edit-meta-modal" class="pe-modal" style="display:none;" aria-modal="true" role="dialog">
            <div class="pe-modal-overlay"></div>
            <div class="pe-modal-content">
                <h3><?php esc_html_e('Modifier les informations B2B', 'portail-entreprises'); ?></h3>
                <div class="pe-form-row" style="margin-bottom:12px;">
                    <label for="pe-edit-cc"><?php esc_html_e('Centre de coût', 'portail-entreprises'); ?></label>
                    <input type="text" id="pe-edit-cc" class="pe-input" style="width:100%;margin-top:4px;"
                           placeholder="<?php esc_attr_e('Service, projet, code interne…', 'portail-entreprises'); ?>" />
                </div>
                <div class="pe-form-row" style="margin-bottom:12px;">
                    <label for="pe-edit-ref"><?php esc_html_e('Référence', 'portail-entreprises'); ?></label>
                    <input type="text" id="pe-edit-ref" class="pe-input" style="width:100%;margin-top:4px;"
                           placeholder="<?php esc_attr_e('Bon de commande, référence interne…', 'portail-entreprises'); ?>" />
                </div>
                <div class="pe-modal-actions">
                    <button type="button" class="pe-btn pe-btn-primary" id="pe-save-meta"
                            data-nonce="<?php echo esc_attr(wp_create_nonce('pe_b2b_ajax')); ?>">
                        <?php esc_html_e('Enregistrer', 'portail-entreprises'); ?>
                    </button>
                    <button type="button" class="pe-btn pe-btn-secondary pe-modal-close">
                        <?php esc_html_e('Annuler', 'portail-entreprises'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Endpoint dédié /mon-compte/b2b-order/ID/ : permet aux managers de consulter
     * une commande d'un membre de leur entreprise via le template WooCommerce natif.
     * Compatible HPOS et stockage classique — aucune dépendance aux filtres internes WC.
     */
    public function render_b2b_order(): void {
        global $wp;
        $order_id = absint($wp->query_vars['b2b-order'] ?? 0);

        $user_id = get_current_user_id();
        $role    = PE_Permissions::get_user_b2b_role($user_id);

        if (!$order_id || !in_array($role, ['company_admin', 'purchase_manager'], true)) {
            wc_add_notice(__('Accès refusé.', 'portail-entreprises'), 'error');
            wp_safe_redirect(wc_get_account_endpoint_url('b2b-approvals'));
            exit;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Commande introuvable.', 'portail-entreprises'), 'error');
            wp_safe_redirect(wc_get_account_endpoint_url('b2b-approvals'));
            exit;
        }

        $company = PE_Permissions::get_user_company($user_id);
        if (!$company || !PE_Permissions::user_belongs_to_company((int) $order->get_customer_id(), (int) $company->id)) {
            wc_add_notice(__('Cette commande n\'appartient pas à votre entreprise.', 'portail-entreprises'), 'error');
            wp_safe_redirect(wc_get_account_endpoint_url('b2b-approvals'));
            exit;
        }

        // Respecte les règles de visibilité des commandes (un gestionnaire restreint
        // ne doit pas accéder à une commande qui lui est interdite).
        if (!PE_Order_Visibility::get_instance()->can_view($user_id, (int) $order->get_customer_id())) {
            wc_add_notice(__('Vous n\'êtes pas autorisé à consulter cette commande.', 'portail-entreprises'), 'error');
            wp_safe_redirect(wc_get_account_endpoint_url('b2b-approvals'));
            exit;
        }

        wc_get_template(
            'myaccount/view-order.php',
            [
                'order_id' => $order_id,
                'order'    => $order,
                'status'   => $order->get_status(),
                'notes'    => wc_get_order_notes(['order_id' => $order_id, 'type' => 'customer']),
            ]
        );
    }

    /**
     * Pré-remplit les champs de facturation et de livraison au checkout avec les
     * adresses de la société. Priorité sur l'adresse éventuellement stockée dans
     * le profil personnel du membre. Si l'adresse de livraison de la société n'est
     * pas renseignée, on retombe sur l'adresse de facturation.
     *
     * @param mixed  $value Valeur par défaut (null si aucune).
     * @param string $input Nom du champ checkout (ex : billing_address_1, shipping_address_1).
     * @return mixed
     */
    public function prefill_checkout_billing_from_company($value, string $input) {
        static $address_fields = [
            'billing_address_1',
            'billing_address_2',
            'billing_city',
            'billing_postcode',
            'billing_country',
            'billing_company',
            'shipping_address_1',
            'shipping_address_2',
            'shipping_city',
            'shipping_postcode',
            'shipping_country',
            'shipping_company',
        ];

        if (!in_array($input, $address_fields, true)) {
            return $value;
        }

        // Ne pas écraser les valeurs postées (rechargement après erreur de validation)
        if (!empty($_POST)) {
            return $value;
        }

        $user_id = get_current_user_id();
        if (!$user_id || !PE_Permissions::is_b2b_user($user_id)) {
            return $value;
        }

        $company = PE_Permissions::get_user_company($user_id);
        if (!$company) {
            return $value;
        }

        $billing  = (array) json_decode($company->billing_address, true);
        $shipping = (array) json_decode($company->shipping_address ?? '', true);
        // Retombe sur l'adresse de facturation si aucune adresse de livraison n'est définie.
        if (empty(array_filter($shipping))) {
            $shipping = $billing;
        }

        $field_map = [
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

        return $field_map[$input] ?? $value;
    }

    /**
     * Restaure les adresses de facturation et de livraison de la société dans le profil
     * WooCommerce du membre après un passage en caisse, afin que la commande garde
     * l'adresse saisie par le membre mais que le profil conserve toujours l'adresse
     * officielle de la société.
     *
     * Le hook s'exécute avant $customer->save() : WooCommerce enregistre alors l'adresse
     * société en user meta, tandis que la commande (créée plus tard depuis $_POST) conserve
     * l'adresse que le membre a réellement saisie.
     */
    public function restore_company_billing_after_checkout(\WC_Customer $customer, array $data): void {
        $user_id = $customer->get_id();
        if (!$user_id || !PE_Permissions::is_b2b_user($user_id)) {
            return;
        }

        $company = PE_Permissions::get_user_company($user_id);
        if (!$company) {
            return;
        }

        $billing  = (array) json_decode($company->billing_address, true);
        $shipping = (array) json_decode($company->shipping_address ?? '', true);
        if (empty(array_filter($shipping))) {
            $shipping = $billing;
        }

        $customer->set_billing_address_1($billing['address_1'] ?? '');
        $customer->set_billing_address_2($billing['address_2'] ?? '');
        $customer->set_billing_city($billing['city'] ?? '');
        $customer->set_billing_postcode($billing['postcode'] ?? '');
        $customer->set_billing_country($billing['country'] ?? 'FR');
        $customer->set_billing_company($company->name);

        $customer->set_shipping_address_1($shipping['address_1'] ?? '');
        $customer->set_shipping_address_2($shipping['address_2'] ?? '');
        $customer->set_shipping_city($shipping['city'] ?? '');
        $customer->set_shipping_postcode($shipping['postcode'] ?? '');
        $customer->set_shipping_country($shipping['country'] ?? 'FR');
        $customer->set_shipping_company($company->name);
    }

    /**
     * Remonte vers la société l'adresse qu'un membre vient d'enregistrer depuis
     * « Mon compte → Adresses », puis la repropage à tous les autres membres.
     *
     * @param int    $user_id      ID de l'utilisateur ayant modifié son adresse.
     * @param string $load_address 'billing' ou 'shipping'.
     */
    public function sync_member_address_to_company(int $user_id, string $load_address): void {
        if (!$user_id || !PE_Permissions::is_b2b_user($user_id)) {
            return;
        }

        PE_Company_Manager::get_instance()->sync_member_address_to_company($user_id, $load_address);
    }

    /**
     * Retourne les commandes WooCommerce des membres d'une entreprise.
     *
     * @param int      $company_id ID de l'entreprise.
     * @param int      $limit      Nombre maximum de commandes.
     * @param int|null $viewer_id  Si fourni, restreint aux commandes visibles par cet utilisateur.
     */
    public static function get_company_orders(int $company_id, int $limit = 50, ?int $viewer_id = null): array {
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

        $user_ids = array_map('intval', $user_ids);

        // Applique les règles de visibilité du point de vue de l'utilisateur courant.
        if (null !== $viewer_id && class_exists('PE_Order_Visibility')) {
            $visible  = PE_Order_Visibility::get_instance()->get_visible_user_ids($viewer_id);
            $user_ids = array_values(array_intersect($user_ids, $visible));
            if (empty($user_ids)) {
                return [];
            }
        }

        $orders = wc_get_orders([
            'customer' => $user_ids,
            'limit'    => $limit,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'type'     => 'shop_order',
        ]);

        return $orders ?: [];
    }
}

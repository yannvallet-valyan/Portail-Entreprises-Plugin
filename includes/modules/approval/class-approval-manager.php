<?php
/**
 * Gestionnaire du workflow d'approbation B2B.
 */

defined('ABSPATH') || exit;

class PE_Approval_Manager {

    private static ?PE_Approval_Manager $instance = null;

    private function __construct() {}

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialise les hooks WooCommerce.
     */
    public function init(): void {
        add_action('woocommerce_checkout_process', [$this, 'handle_checkout_approval'], 5);
        add_action('woocommerce_checkout_order_created', [$this, 'post_order_approval_check'], 10, 1);

        // AJAX handlers pour approbation/rejet
        add_action('wp_ajax_pe_approve_request', [$this, 'ajax_approve_request']);
        add_action('wp_ajax_pe_reject_request', [$this, 'ajax_reject_request']);

        // Soumission d'une demande d'approbation depuis le panier (bouton dédié).
        // Traité côté front-end (template_redirect) car WC()->cart et wc_add_notice
        // ne sont pas disponibles dans le contexte admin-post.php.
        add_action('template_redirect', [$this, 'handle_request_approval']);
    }

    /**
     * Génère le HTML du bouton "Demander une approbation".
     */
    public function get_approval_button_html(string $context = 'cart'): string {
        $class = 'cart' === $context ? 'button alt pe-request-approval-btn' : 'button wc-forward pe-request-approval-btn';

        // Champs centre de coût + référence personnelle.
        $extra_fields = $this->get_b2b_order_fields_html();

        return '<form method="post" action="' . esc_url(wc_get_cart_url()) . '" class="pe-approval-request-form" style="margin-top:10px;">'
            . wp_nonce_field('pe_request_approval', 'pe_nonce', true, false)
            . '<input type="hidden" name="pe_request_approval" value="1" />'
            . $extra_fields
            . '<button type="submit" class="' . esc_attr($class) . '">'
            . esc_html__('Demander une approbation', 'portail-entreprises')
            . '</button>'
            . '</form>';
    }

    /**
     * Génère les champs "Centre de coût" et "Votre référence" pour le formulaire d'approbation.
     */
    private function get_b2b_order_fields_html(): string {
        $user_id = get_current_user_id();
        $company = PE_Permissions::get_user_company($user_id);
        if (!$company) {
            return '';
        }

        // Champs masqués par défaut, révélés via le toggle « Plus d'options ».
        $html = '<details class="pe-approval-fields">';
        $html .= '<summary class="pe-approval-fields__toggle">'
            . esc_html__('Plus d\'options', 'portail-entreprises') . '</summary>';
        $html .= '<div class="pe-approval-fields__content" style="margin:10px 0;text-align:left;">';

        // Centre de coût — champ texte libre.
        $html .= '<p style="margin:0 0 8px;"><label style="display:block;font-size:0.85em;margin-bottom:4px;">'
            . esc_html__('Centre de coût', 'portail-entreprises') . '</label>'
            . '<input type="text" name="b2b_cost_center_text" style="width:100%;" '
            . 'placeholder="' . esc_attr__('Service, projet, code interne…', 'portail-entreprises') . '" /></p>';

        // Référence personnelle.
        $html .= '<p style="margin:0 0 8px;"><label style="display:block;font-size:0.85em;margin-bottom:4px;">'
            . esc_html__('Votre référence', 'portail-entreprises') . '</label>'
            . '<input type="text" name="b2b_personal_reference" style="width:100%;" '
            . 'placeholder="' . esc_attr__('Bon de commande, référence interne…', 'portail-entreprises') . '" /></p>';

        $html .= '</div>';
        $html .= '</details>';

        return $html;
    }

    /**
     * Traite la soumission du panier comme une demande d'approbation.
     * Crée une commande en statut "en attente de validation".
     */
    public function handle_request_approval(): void {
        if (!isset($_POST['pe_request_approval'])) {
            return;
        }

        if (!isset($_POST['pe_nonce']) || !wp_verify_nonce(sanitize_key($_POST['pe_nonce']), 'pe_request_approval')) {
            wp_die(esc_html__('Erreur de sécurité.', 'portail-entreprises'));
        }

        $user_id = get_current_user_id();
        if (!$user_id || !PE_Permissions::is_b2b_user($user_id)) {
            wp_die(esc_html__('Accès refusé.', 'portail-entreprises'));
        }

        if (!WC()->cart || WC()->cart->is_empty()) {
            wc_add_notice(__('Votre panier est vide.', 'portail-entreprises'), 'error');
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        $cost_center_text = isset($_POST['b2b_cost_center_text'])
            ? sanitize_text_field(wp_unslash($_POST['b2b_cost_center_text']))
            : '';
        $reference        = isset($_POST['b2b_personal_reference'])
            ? sanitize_text_field(wp_unslash($_POST['b2b_personal_reference']))
            : '';

        $order = $this->create_order_from_cart($user_id, $cost_center_text, $reference);

        if (!$order instanceof \WC_Order) {
            wc_add_notice(__('Impossible de créer la demande. Veuillez réessayer.', 'portail-entreprises'), 'error');
            wp_safe_redirect(wc_get_cart_url());
            exit;
        }

        $amount = (float) $order->get_total();
        $order->update_status('pending-approval', __('Commande soumise à validation par le demandeur.', 'portail-entreprises'));

        $this->create_approval_request((int) $order->get_id(), $user_id, $amount, null);

        // Vider le panier après soumission.
        WC()->cart->empty_cart();

        wc_add_notice(
            __('Votre demande a été soumise pour validation. Vous serez notifié dès qu\'un responsable l\'aura approuvée.', 'portail-entreprises'),
            'success'
        );

        wp_safe_redirect(wc_get_account_endpoint_url('orders'));
        exit;
    }

    /**
     * Construit une commande WooCommerce à partir du panier courant.
     */
    private function create_order_from_cart(int $user_id, string $cost_center_text = '', string $reference = ''): ?\WC_Order {
        $cart = WC()->cart;

        try {
            $order = wc_create_order(['customer_id' => $user_id]);

            if (is_wp_error($order)) {
                return null;
            }

            // Ajout des produits du panier.
            foreach ($cart->get_cart() as $cart_item) {
                $order->add_product(
                    $cart_item['data'],
                    $cart_item['quantity'],
                    [
                        'subtotal' => $cart_item['line_subtotal'],
                        'total'    => $cart_item['line_total'],
                    ]
                );
            }

            // Adresses depuis le profil client.
            $customer = new \WC_Customer($user_id);
            $order->set_address($customer->get_billing(), 'billing');
            $order->set_address($customer->get_shipping(), 'shipping');

            // Frais de port choisis (si disponibles).
            foreach ($cart->get_shipping_packages() as $package_key => $package) {
                $session  = WC()->session ? WC()->session->get('chosen_shipping_methods') : [];
                $chosen   = $session[$package_key] ?? '';
                $rates    = $package['rates'] ?? [];
                if ($chosen && isset($rates[$chosen])) {
                    $item = new \WC_Order_Item_Shipping();
                    $item->set_shipping_rate($rates[$chosen]);
                    $order->add_item($item);
                }
            }

            // Coupons éventuels.
            foreach ($cart->get_applied_coupons() as $coupon_code) {
                $order->apply_coupon($coupon_code);
            }

            $order->set_created_via('b2b-approval-request');
            $order->update_meta_data('_b2b_approval_request', 1);

            // Centre de coût (texte libre) + référence personnelle.
            if ('' !== $cost_center_text) {
                $order->update_meta_data('_b2b_cost_center_label', $cost_center_text);
            }
            if ('' !== $reference) {
                $order->update_meta_data('_b2b_personal_reference', $reference);
            }

            $order->calculate_totals();
            $order->save();

            return $order;
        } catch (\Exception $e) {
            return null;
        }
    }


    /**
     * Récupère la règle d'approbation applicable pour une entreprise et un montant.
     */
    public function get_applicable_rule(int $company_id, float $amount): ?object {
        global $wpdb;

        $rule = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}b2b_approval_rules
                 WHERE company_id = %d
                   AND threshold_min <= %f
                   AND (threshold_max IS NULL OR threshold_max >= %f)
                 ORDER BY threshold_min DESC
                 LIMIT 1",
                $company_id,
                $amount,
                $amount
            )
        );

        return $rule ?: null;
    }

    /**
     * Détermine si une commande nécessite une approbation.
     */
    public function requires_approval(int $user_id, float $amount): bool {
        $role = PE_Permissions::get_user_b2b_role($user_id);

        // Le rôle requester nécessite TOUJOURS une approbation
        if ('requester' === $role) {
            return true;
        }

        // Vérification via les règles d'approbation
        $company = PE_Permissions::get_user_company($user_id);
        if (!$company) {
            return false;
        }

        $rule = $this->get_applicable_rule((int) $company->id, $amount);
        return $rule !== null;
    }

    /**
     * Crée une demande d'approbation.
     */
    public function create_approval_request(int $order_id, int $user_id, float $amount, ?int $cost_center_id): int {
        global $wpdb;

        $company = PE_Permissions::get_user_company($user_id);
        $company_id = $company ? (int) $company->id : 0;

        $wpdb->insert(
            $wpdb->prefix . 'b2b_approval_requests',
            [
                'order_id'       => $order_id,
                'company_id'     => $company_id,
                'requester_id'   => $user_id,
                'approver_id'    => null,
                'status'         => 'pending',
                'amount'         => $amount,
                'cost_center_id' => $cost_center_id,
                'notes'          => null,
                'created_at'     => current_time('mysql'),
                'updated_at'     => current_time('mysql'),
            ],
            ['%d', '%d', '%d', '%d', '%s', '%f', '%d', '%s', '%s', '%s']
        );

        $request_id = (int) $wpdb->insert_id;

        if ($request_id > 0) {
            PE_Audit_Log::get_instance()->log(
                $user_id,
                $company_id,
                'create_approval_request',
                'approval_request',
                $request_id,
                ['order_id' => $order_id, 'amount' => $amount]
            );

            $this->notify_approvers($request_id, $company_id, $amount, $order_id, $user_id);
        }

        return $request_id;
    }

    /**
     * Approuve une demande.
     */
    public function approve_request(int $request_id, int $approver_id): bool {
        global $wpdb;

        $request = $this->get_request($request_id);
        if (!$request || 'pending' !== $request->status) {
            return false;
        }

        // Vérification que l'approbateur appartient à la même entreprise
        if (!PE_Permissions::user_belongs_to_company($approver_id, (int) $request->company_id)) {
            return false;
        }

        // Vérification du rôle
        $role = PE_Permissions::get_user_b2b_role($approver_id);
        if (!in_array($role, ['company_admin', 'purchase_manager'], true)) {
            return false;
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'b2b_approval_requests',
            [
                'status'      => 'approved',
                'approver_id' => $approver_id,
                'updated_at'  => current_time('mysql'),
            ],
            ['id' => $request_id],
            ['%s', '%d', '%s'],
            ['%d']
        );

        if (false !== $result) {
            $order = wc_get_order((int) $request->order_id);
            if ($order) {
                $order->update_status('processing', __('Commande approuvée par le responsable.', 'portail-entreprises'));

                // Record budget usage on approval
                $budget_mgr = PE_Budget_Manager::get_instance();
                $requester_id = (int) $request->requester_id;
                $req_company  = PE_Permissions::get_user_company($requester_id);
                if ($req_company) {
                    $budget_mgr->record_usage($requester_id, (int) $req_company->id, (float) $request->amount);
                }

                // Envoyer l'email WooCommerce "Commande en cours de traitement" au client.
                $wc_emails = WC()->mailer()->get_emails();
                if (isset($wc_emails['WC_Email_Customer_Processing_Order'])) {
                    $wc_emails['WC_Email_Customer_Processing_Order']->trigger((int) $request->order_id, $order);
                }

                // Notifier aussi les admins WP de la commande approuvée.
                if (isset($wc_emails['WC_Email_New_Order'])) {
                    $wc_emails['WC_Email_New_Order']->trigger((int) $request->order_id, $order);
                }
            }

            PE_Audit_Log::get_instance()->log(
                $approver_id,
                (int) $request->company_id,
                'approve_request',
                'approval_request',
                $request_id,
                ['order_id' => $request->order_id]
            );

            $this->notify_requester($request_id, 'approved');
        }

        return false !== $result;
    }

    /**
     * Rejette une demande.
     */
    public function reject_request(int $request_id, int $approver_id, string $reason): bool {
        global $wpdb;

        $request = $this->get_request($request_id);
        if (!$request || 'pending' !== $request->status) {
            return false;
        }

        // Vérification que l'approbateur appartient à la même entreprise
        if (!PE_Permissions::user_belongs_to_company($approver_id, (int) $request->company_id)) {
            return false;
        }

        $role = PE_Permissions::get_user_b2b_role($approver_id);
        if (!in_array($role, ['company_admin', 'purchase_manager'], true)) {
            return false;
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'b2b_approval_requests',
            [
                'status'      => 'rejected',
                'approver_id' => $approver_id,
                'notes'       => sanitize_textarea_field($reason),
                'updated_at'  => current_time('mysql'),
            ],
            ['id' => $request_id],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );

        if (false !== $result) {
            $order = wc_get_order((int) $request->order_id);
            if ($order) {
                $order->update_status('cancelled', __('Commande rejetée : ', 'portail-entreprises') . sanitize_text_field($reason));
            }

            PE_Audit_Log::get_instance()->log(
                $approver_id,
                (int) $request->company_id,
                'reject_request',
                'approval_request',
                $request_id,
                ['order_id' => $request->order_id, 'reason' => $reason]
            );

            $this->notify_requester($request_id, 'rejected', $reason);
        }

        return false !== $result;
    }

    /**
     * Hook checkout : bloque le checkout pour les requester et déclenche les demandes d'approbation.
     */
    public function handle_checkout_approval(): void {
        $user_id = get_current_user_id();

        if (!$user_id || !PE_Permissions::is_b2b_user($user_id)) {
            return;
        }

        $role = PE_Permissions::get_user_b2b_role($user_id);

        if ('requester' === $role) {
            wc_add_notice(
                __('En tant que demandeur, votre panier sera soumis à validation. Vous recevrez une notification lorsque votre commande sera approuvée.', 'portail-entreprises'),
                'notice'
            );
        }
    }

    /**
     * Après la création de la commande, convertit en demande d'approbation si nécessaire.
     */
    public function post_order_approval_check(\WC_Order $order): void {
        $user_id = (int) $order->get_customer_id();

        if (!$user_id || !PE_Permissions::is_b2b_user($user_id)) {
            return;
        }

        $amount = (float) $order->get_total();

        if (!$this->requires_approval($user_id, $amount)) {
            return;
        }

        // Mettre la commande en statut "en attente de validation"
        $order->update_status('pending-approval', __('Commande soumise à validation.', 'portail-entreprises'));

        $cost_center_id = (int) $order->get_meta('_b2b_cost_center_id');

        $this->create_approval_request(
            (int) $order->get_id(),
            $user_id,
            $amount,
            $cost_center_id > 0 ? $cost_center_id : null
        );
    }

    /**
     * Compte les demandes d'approbation « en attente » pertinentes pour un utilisateur.
     *
     * Pour les approbateurs (company_admin / purchase_manager) : toutes les demandes
     * en attente de leur entreprise. Pour les autres (demandeurs…) : leurs propres
     * demandes en attente.
     */
    public function get_pending_count_for_user(int $user_id): int {
        global $wpdb;

        if (PE_Permissions::user_can($user_id, 'approve_orders')) {
            $company = PE_Permissions::get_user_company($user_id);
            if (!$company) {
                return 0;
            }
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}b2b_approval_requests WHERE company_id = %d AND status = 'pending'",
                    (int) $company->id
                )
            );
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}b2b_approval_requests WHERE requester_id = %d AND status = 'pending'",
                $user_id
            )
        );
    }

    /**
     * Récupère une demande d'approbation par son ID.
     */
    public function get_request(int $request_id): ?object {
        global $wpdb;

        $request = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}b2b_approval_requests WHERE id = %d LIMIT 1",
                $request_id
            )
        );

        return $request ?: null;
    }

    /**
     * Récupère les demandes d'approbation pour une entreprise.
     */
    public function get_company_requests(int $company_id, string $status = ''): array {
        global $wpdb;

        if ($status && in_array($status, ['pending', 'approved', 'rejected'], true)) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ar.*, u.display_name as requester_name, u.user_email as requester_email
                     FROM {$wpdb->prefix}b2b_approval_requests ar
                     INNER JOIN {$wpdb->users} u ON u.ID = ar.requester_id
                     WHERE ar.company_id = %d AND ar.status = %s
                     ORDER BY ar.created_at DESC",
                    $company_id,
                    $status
                )
            ) ?: [];
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ar.*, u.display_name as requester_name, u.user_email as requester_email
                 FROM {$wpdb->prefix}b2b_approval_requests ar
                 INNER JOIN {$wpdb->users} u ON u.ID = ar.requester_id
                 WHERE ar.company_id = %d
                 ORDER BY ar.created_at DESC",
                $company_id
            )
        ) ?: [];
    }

    /**
     * Notifie les approbateurs B2B + l'admin WP/shop_manager d'une nouvelle demande.
     */
    private function notify_approvers(int $request_id, int $company_id, float $amount, int $order_id, int $requester_id = 0): void {
        global $wpdb;

        $approval_url = wc_get_account_endpoint_url('b2b-approvals');

        // Informations sur le demandeur (nom, prénom, fonction/rôle).
        $requester_label = '';
        if ($requester_id > 0) {
            $requester     = get_userdata($requester_id);
            $requester_role = PE_Permissions::get_user_b2b_role($requester_id);
            $roles_labels   = PE_Permissions::get_roles();
            $role_label     = $roles_labels[$requester_role] ?? $requester_role;

            if ($requester) {
                $full_name = trim($requester->first_name . ' ' . $requester->last_name);
                if ('' === $full_name) {
                    $full_name = $requester->display_name;
                }
                $requester_label = sprintf(
                    /* translators: 1: full name, 2: role/function */
                    __("Demandeur : %1\$s (%2\$s)", 'portail-entreprises'),
                    $full_name,
                    $role_label
                );
            }
        }

        // Approbateurs B2B de l'entreprise.
        $approvers = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT u.user_email, u.display_name
                 FROM {$wpdb->prefix}b2b_user_company uc
                 INNER JOIN {$wpdb->users} u ON u.ID = uc.user_id
                 WHERE uc.company_id = %d AND uc.role IN ('company_admin', 'purchase_manager')",
                $company_id
            )
        ) ?: [];

        $use_magic_links = class_exists('PE_Magic_Link_Manager') && PE_Magic_Link_Manager::get_instance()->is_enabled();

        foreach ($approvers as $approver) {
            $approver_user = get_user_by('email', $approver->user_email);
            $approver_id   = $approver_user ? (int) $approver_user->ID : 0;

            $magic_buttons = '';
            if ($use_magic_links && $approver_id > 0) {
                $magic_buttons = PE_Magic_Link_Manager::get_instance()->get_email_buttons_html($request_id, $approver_id);
            }

            if ($magic_buttons) {
                $headers = ['Content-Type: text/html; charset=UTF-8'];
                $body    = '<html><body style="font-family:sans-serif;color:#1a2744;">'
                    . '<p>' . sprintf(esc_html__('Bonjour %s,', 'portail-entreprises'), esc_html($approver->display_name)) . '</p>'
                    . '<p>' . esc_html__('Une nouvelle commande nécessite votre validation.', 'portail-entreprises') . '</p>'
                    . ('' !== $requester_label ? '<p>' . esc_html($requester_label) . '</p>' : '')
                    . '<p><strong>' . esc_html__('Montant :', 'portail-entreprises') . '</strong> ' . wp_kses_post(wc_price($amount)) . '</p>'
                    . '<p><strong>' . esc_html__('Commande n° :', 'portail-entreprises') . '</strong> ' . (int) $order_id . '</p>'
                    . $magic_buttons
                    . '<p style="font-size:13px;color:#666;">' . esc_html__('Vous pouvez aussi accéder à votre espace approbations :', 'portail-entreprises') . ' <a href="' . esc_url($approval_url) . '">' . esc_url($approval_url) . '</a></p>'
                    . '<p>' . esc_html__('Cordialement,', 'portail-entreprises') . '<br>' . esc_html__('Le portail B2B', 'portail-entreprises') . '</p>'
                    . '</body></html>';

                wp_mail(
                    $approver->user_email,
                    sprintf(__('[B2B] Nouvelle demande d\'approbation — Commande n° %d', 'portail-entreprises'), $order_id),
                    $body,
                    $headers
                );
            } else {
                wp_mail(
                    $approver->user_email,
                    sprintf(__('[B2B] Nouvelle demande d\'approbation — Commande n° %d', 'portail-entreprises'), $order_id),
                    sprintf(
                        __("Bonjour %1\$s,\n\nUne nouvelle commande nécessite votre validation.\n\n%2\$sMontant : %3\$s\nCommande n° : %4\$d\n\nApprouver ou rejeter :\n%5\$s\n\nCordialement,\nLe portail B2B", 'portail-entreprises'),
                        esc_html($approver->display_name),
                        '' !== $requester_label ? $requester_label . "\n" : '',
                        html_entity_decode(strip_tags(wc_price($amount))),
                        $order_id,
                        esc_url($approval_url)
                    )
                );
            }
        }

        // Note : les administrateurs WP / gestionnaires de boutique ne sont PAS
        // notifiés à ce stade. La commande est encore en attente de validation
        // (statut « pending-approval »). L'email WooCommerce « Nouvelle commande »
        // leur est envoyé uniquement lorsque la commande est approuvée et passe
        // « en cours » (voir approve_request()).
    }

    /**
     * Notifie le demandeur du résultat de sa demande.
     */
    private function notify_requester(int $request_id, string $status, string $reason = ''): void {
        $request = $this->get_request($request_id);
        if (!$request) {
            return;
        }

        $user = get_userdata((int) $request->requester_id);
        if (!$user) {
            return;
        }

        if ('approved' === $status) {
            $subject = __('Votre commande a été approuvée', 'portail-entreprises');
            $message = sprintf(
                /* translators: 1: name, 2: order ID */
                __("Bonjour %1\$s,\n\nVotre commande n° %2\$d a été approuvée et est en cours de traitement.\n\nCordialement,\nLe portail B2B", 'portail-entreprises'),
                esc_html($user->display_name),
                (int) $request->order_id
            );
        } else {
            $subject = __('Votre commande a été refusée', 'portail-entreprises');
            $message = sprintf(
                /* translators: 1: name, 2: order ID, 3: reason */
                __("Bonjour %1\$s,\n\nVotre commande n° %2\$d a été refusée.\n\nMotif : %3\$s\n\nCordialement,\nLe portail B2B", 'portail-entreprises'),
                esc_html($user->display_name),
                (int) $request->order_id,
                esc_html($reason)
            );
        }

        wp_mail($user->user_email, $subject, $message);
    }

    /**
     * Handler AJAX : approuver une demande.
     */
    public function ajax_approve_request(): void {
        check_ajax_referer('pe_b2b_ajax', 'nonce');

        $user_id    = get_current_user_id();
        $request_id = isset($_POST['request_id']) ? absint($_POST['request_id']) : 0;

        if (!$user_id || !$request_id) {
            wp_send_json_error(['message' => __('Paramètres invalides.', 'portail-entreprises')]);
        }

        if (!PE_Permissions::user_can($user_id, 'approve_orders')) {
            wp_send_json_error(['message' => __('Vous n\'avez pas la permission d\'approuver des commandes.', 'portail-entreprises')]);
        }

        // Vérification que la demande appartient à l'entreprise de l'approbateur
        $request = $this->get_request($request_id);
        if (!$request || !PE_Permissions::user_belongs_to_company($user_id, (int) $request->company_id)) {
            wp_send_json_error(['message' => __('Demande introuvable ou accès refusé.', 'portail-entreprises')]);
        }

        $result = $this->approve_request($request_id, $user_id);

        if ($result) {
            wp_send_json_success(['message' => __('Demande approuvée avec succès.', 'portail-entreprises')]);
        } else {
            wp_send_json_error(['message' => __('Impossible d\'approuver cette demande.', 'portail-entreprises')]);
        }
    }

    /**
     * Handler AJAX : rejeter une demande.
     */
    public function ajax_reject_request(): void {
        check_ajax_referer('pe_b2b_ajax', 'nonce');

        $user_id    = get_current_user_id();
        $request_id = isset($_POST['request_id']) ? absint($_POST['request_id']) : 0;
        $reason     = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';

        if (!$user_id || !$request_id) {
            wp_send_json_error(['message' => __('Paramètres invalides.', 'portail-entreprises')]);
        }

        if (!PE_Permissions::user_can($user_id, 'approve_orders')) {
            wp_send_json_error(['message' => __('Vous n\'avez pas la permission de rejeter des commandes.', 'portail-entreprises')]);
        }

        $request = $this->get_request($request_id);
        if (!$request || !PE_Permissions::user_belongs_to_company($user_id, (int) $request->company_id)) {
            wp_send_json_error(['message' => __('Demande introuvable ou accès refusé.', 'portail-entreprises')]);
        }

        $result = $this->reject_request($request_id, $user_id, $reason);

        if ($result) {
            wp_send_json_success(['message' => __('Demande rejetée.', 'portail-entreprises')]);
        } else {
            wp_send_json_error(['message' => __('Impossible de rejeter cette demande.', 'portail-entreprises')]);
        }
    }

    /**
     * Renvoie l'e-mail de notification aux approbateurs (utilisé par le Magic Link admin resend).
     */
    public function resend_approval_notification(int $request_id): void {
        global $wpdb;

        $request = $this->get_request($request_id);
        if (!$request) {
            return;
        }

        $this->notify_approvers(
            $request_id,
            (int) $request->company_id,
            (float) $request->amount,
            (int) $request->order_id,
            (int) $request->requester_id
        );
    }
}

<?php
/**
 * Module : Validation des demandes d'approbation par Magic Link sécurisé.
 *
 * Permet aux approbateurs de valider ou refuser une demande directement
 * depuis un e-mail, sans authentification WordPress.
 *
 * Module autonome : activable/désactivable via les réglages (pe_settings['magic_link_enabled']).
 */

defined('ABSPATH') || exit;

class PE_Magic_Link_Manager {

    private static ?PE_Magic_Link_Manager $instance = null;

    /** Durées de validité disponibles (en secondes). */
    public const DURATIONS = [
        '24h'  => DAY_IN_SECONDS,
        '48h'  => 2 * DAY_IN_SECONDS,
        '7d'   => 7 * DAY_IN_SECONDS,
        '14d'  => 14 * DAY_IN_SECONDS,
    ];

    private function __construct() {}

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialise les hooks (uniquement si le module est activé).
     */
    public function init(): void {
        if (!$this->is_enabled()) {
            return;
        }

        add_action('init', [$this, 'register_rewrite']);
        add_filter('query_vars', [$this, 'add_query_var']);
        add_action('template_redirect', [$this, 'maybe_handle_request'], 5);

        // Administration des tokens.
        add_action('wp_ajax_pe_revoke_token', [$this, 'ajax_revoke_token']);
        add_action('wp_ajax_pe_resend_approval_email', [$this, 'ajax_resend_email']);
    }

    /* =========================================================
       Réglages
       ========================================================= */

    public function is_enabled(): bool {
        $settings = get_option('pe_settings', []);
        // Activé par défaut.
        return !isset($settings['magic_link_enabled']) || (int) $settings['magic_link_enabled'] === 1;
    }

    /**
     * Durée de validité en secondes (selon le réglage admin).
     */
    public function get_validity_seconds(): int {
        $settings = get_option('pe_settings', []);
        $choice   = $settings['magic_link_validity'] ?? '7d';

        if ('custom' === $choice) {
            $hours = isset($settings['magic_link_validity_custom_hours'])
                ? max(1, (int) $settings['magic_link_validity_custom_hours'])
                : 168;
            return $hours * HOUR_IN_SECONDS;
        }

        return self::DURATIONS[$choice] ?? self::DURATIONS['7d'];
    }

    /* =========================================================
       Génération des tokens et des liens
       ========================================================= */

    /**
     * Génère un token sécurisé pour un approbateur et une demande, l'enregistre,
     * et retourne le token brut (à insérer dans l'URL).
     */
    public function generate_token(int $request_id, int $approver_id): ?string {
        global $wpdb;

        // 32 octets => 64 caractères hexadécimaux.
        $raw_token  = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $raw_token);

        $now     = current_time('timestamp');
        $expires = gmdate('Y-m-d H:i:s', $now + $this->get_validity_seconds());

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'b2b_approval_tokens',
            [
                'request_id'  => $request_id,
                'approver_id' => $approver_id,
                'token_hash'  => $token_hash,
                'action'      => 'decision',
                'status'      => 'active',
                'created_at'  => current_time('mysql'),
                'expires_at'  => $expires,
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        if (!$inserted) {
            return null;
        }

        return $raw_token;
    }

    /**
     * Construit les URLs de validation/refus pour un approbateur.
     *
     * @return array{approve:string,reject:string,view:string}|null
     */
    public function get_decision_links(int $request_id, int $approver_id): ?array {
        $raw_token = $this->generate_token($request_id, $approver_id);
        if (!$raw_token) {
            return null;
        }

        $base = home_url('/approval/');

        return [
            'approve' => add_query_arg(['action' => 'approve', 'request' => $request_id, 'token' => $raw_token], $base),
            'reject'  => add_query_arg(['action' => 'reject', 'request' => $request_id, 'token' => $raw_token], $base),
            'view'    => add_query_arg(['request' => $request_id, 'token' => $raw_token], $base),
        ];
    }

    /**
     * Retourne le bloc HTML (bouton) à insérer dans l'e-mail d'approbation.
     *
     * Un seul bouton « Voir la demande d'approbation » : la validation/le refus
     * s'effectuent sur la page dédiée (façon panier) pour éviter la redondance.
     */
    public function get_email_buttons_html(int $request_id, int $approver_id): string {
        $links = $this->get_decision_links($request_id, $approver_id);
        if (!$links) {
            return '';
        }

        $validity_label = $this->get_validity_human();

        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;">'
            . '<tr>'
            . '<td>'
            . '<a href="' . esc_url($links['view']) . '" '
            . 'style="display:inline-block;background:#2d6ebd;color:#ffffff;text-decoration:none;'
            . 'padding:14px 28px;border-radius:6px;font-weight:600;font-family:sans-serif;font-size:15px;">'
            . esc_html__('Voir la demande d\'approbation', 'portail-entreprises') . '</a>'
            . '</td>'
            . '</tr>'
            . '</table>'
            . '<p style="font-size:12px;color:#888;font-family:sans-serif;">'
            . sprintf(
                /* translators: %s: validity duration */
                esc_html__('Ce lien est valable %s et utilisable une seule fois.', 'portail-entreprises'),
                esc_html($validity_label)
            )
            . '</p>';
    }

    private function get_validity_human(): string {
        $settings = get_option('pe_settings', []);
        $choice   = $settings['magic_link_validity'] ?? '7d';
        $map      = [
            '24h' => __('24 heures', 'portail-entreprises'),
            '48h' => __('48 heures', 'portail-entreprises'),
            '7d'  => __('7 jours', 'portail-entreprises'),
            '14d' => __('14 jours', 'portail-entreprises'),
        ];
        if ('custom' === $choice) {
            $hours = (int) ($settings['magic_link_validity_custom_hours'] ?? 168);
            return sprintf(_n('%d heure', '%d heures', $hours, 'portail-entreprises'), $hours);
        }
        return $map[$choice] ?? $map['7d'];
    }

    /* =========================================================
       Endpoint public /approval
       ========================================================= */

    public function register_rewrite(): void {
        add_rewrite_rule('^approval/?$', 'index.php?pe_approval=1', 'top');
    }

    public function add_query_var(array $vars): array {
        $vars[] = 'pe_approval';
        return $vars;
    }

    /**
     * Détecte l'accès à la page de validation (via rewrite ou paramètre direct).
     */
    public function maybe_handle_request(): void {
        $is_approval_page = (int) get_query_var('pe_approval') === 1;

        // Repli si les rewrite rules ne sont pas encore flushées : /?pe_approval=1
        if (!$is_approval_page && isset($_GET['pe_approval'])) {
            $is_approval_page = true;
        }

        if (!$is_approval_page) {
            return;
        }

        $this->handle_page();
        exit;
    }

    /**
     * Gère l'affichage et le traitement de la page de validation.
     */
    private function handle_page(): void {
        // Empêcher la mise en cache de cette page.
        nocache_headers();

        $request_id = isset($_GET['request']) ? absint($_GET['request']) : 0;
        $raw_token  = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $action     = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';

        // Traitement de la soumission du formulaire (POST).
        $is_post = ('POST' === ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($is_post) {
            $request_id = isset($_POST['request']) ? absint($_POST['request']) : 0;
            $raw_token  = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
            $action     = isset($_POST['decision']) ? sanitize_key($_POST['decision']) : '';
        }

        $validation = $this->validate_token($raw_token, $request_id);

        if (is_wp_error($validation)) {
            $this->render_message(
                __('Lien invalide ou expiré.', 'portail-entreprises'),
                $validation->get_error_message(),
                'error',
                true
            );
            return;
        }

        $token   = $validation['token'];
        $request = $validation['request'];

        // Soumission d'une décision.
        if ($is_post && in_array($action, ['approve', 'reject'], true)) {
            if (!isset($_POST['pe_magic_nonce']) || !wp_verify_nonce(sanitize_key($_POST['pe_magic_nonce']), 'pe_magic_decision_' . $request_id)) {
                $this->render_message(
                    __('Erreur de sécurité.', 'portail-entreprises'),
                    __('Le formulaire a expiré, veuillez recharger la page.', 'portail-entreprises'),
                    'error'
                );
                return;
            }

            $reason = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';
            $this->process_decision($token, $request, $action, $reason);
            return;
        }

        // Affichage du récapitulatif + formulaire de décision.
        // Si action explicite dans l'URL (clic direct sur un bouton), pré-sélectionner.
        $this->render_decision_page($token, $request, $action);
    }

    /**
     * Vérifie la validité d'un token pour une demande.
     *
     * @return array{token:object,request:object}|\WP_Error
     */
    public function validate_token(string $raw_token, int $request_id) {
        global $wpdb;

        if ('' === $raw_token || strlen($raw_token) < 64 || $request_id <= 0) {
            return new \WP_Error('invalid', __('Lien invalide ou expiré.', 'portail-entreprises'));
        }

        $token_hash = hash('sha256', $raw_token);

        $token = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}b2b_approval_tokens WHERE token_hash = %s AND request_id = %d LIMIT 1",
                $token_hash,
                $request_id
            )
        );

        if (!$token) {
            return new \WP_Error('not_found', __('Lien invalide ou expiré.', 'portail-entreprises'));
        }

        if ('revoked' === $token->status) {
            return new \WP_Error('revoked', __('Ce lien a été révoqué.', 'portail-entreprises'));
        }

        if ('used' === $token->status) {
            return new \WP_Error('used', __('Cette demande a déjà été traitée.', 'portail-entreprises'));
        }

        // Expiration.
        if (strtotime($token->expires_at . ' UTC') < current_time('timestamp', true)) {
            // Marquer comme expiré.
            $wpdb->update(
                $wpdb->prefix . 'b2b_approval_tokens',
                ['status' => 'expired'],
                ['id' => (int) $token->id],
                ['%s'],
                ['%d']
            );
            return new \WP_Error('expired', __('Ce lien a expiré.', 'portail-entreprises'));
        }

        // La demande doit toujours être en attente.
        $request = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}b2b_approval_requests WHERE id = %d LIMIT 1",
                $request_id
            )
        );

        if (!$request) {
            return new \WP_Error('no_request', __('Demande introuvable.', 'portail-entreprises'));
        }

        if ('pending' !== $request->status) {
            return new \WP_Error('already_processed', __('Cette demande a déjà été traitée.', 'portail-entreprises'));
        }

        return ['token' => $token, 'request' => $request];
    }

    /**
     * Traite la décision (approve/reject), marque le token comme utilisé, journalise.
     */
    private function process_decision(object $token, object $request, string $action, string $reason): void {
        global $wpdb;

        $approver_id = (int) $token->approver_id;
        $request_id  = (int) $request->id;
        $approval_mgr = PE_Approval_Manager::get_instance();

        if ('approve' === $action) {
            $ok = $approval_mgr->approve_request($request_id, $approver_id);
        } else {
            $ok = $approval_mgr->reject_request($request_id, $approver_id, $reason);
        }

        if (!$ok) {
            $this->render_message(
                __('Action impossible', 'portail-entreprises'),
                __('Cette demande a déjà été traitée ou vous n\'êtes pas autorisé à la valider.', 'portail-entreprises'),
                'error',
                true
            );
            return;
        }

        // Marquer le token comme utilisé (usage unique + anti-replay).
        $ip_raw = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $ua_raw = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        $wpdb->update(
            $wpdb->prefix . 'b2b_approval_tokens',
            [
                'status'      => 'used',
                'used_at'     => current_time('mysql'),
                'used_action' => $action,
                'ip_address'  => substr($ip_raw, 0, 45),
                'user_agent'  => substr($ua_raw, 0, 255),
            ],
            ['id' => (int) $token->id],
            ['%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        // Révoquer les autres tokens actifs de la même demande (anti double-traitement).
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}b2b_approval_tokens SET status = 'revoked'
                 WHERE request_id = %d AND status = 'active'",
                $request_id
            )
        );

        // Journalisation détaillée (IP, User-Agent, token).
        PE_Audit_Log::get_instance()->log(
            $approver_id,
            (int) $request->company_id,
            'magic_link_' . $action,
            'approval_request',
            $request_id,
            [
                'order_id'    => (int) $request->order_id,
                'reason'      => $reason,
                'ip_address'  => $ip_raw,
                'user_agent'  => $ua_raw,
                'token_id'    => (int) $token->id,
                'token_hash'  => substr($token->token_hash, 0, 12) . '…',
            ]
        );

        if ('approve' === $action) {
            $this->render_message(
                __('Demande approuvée', 'portail-entreprises'),
                __('La demande a été approuvée. Le demandeur et les administrateurs ont été notifiés.', 'portail-entreprises'),
                'success'
            );
        } else {
            $this->render_message(
                __('Demande refusée', 'portail-entreprises'),
                __('La demande a été refusée. Le demandeur et les administrateurs ont été notifiés.', 'portail-entreprises'),
                'info'
            );
        }
    }

    /* =========================================================
       Rendu des pages publiques (autonomes, compatibles cache/mobile)
       ========================================================= */

    /**
     * Affiche la page de décision sous forme de panier (récap façon WooCommerce + boutons).
     */
    private function render_decision_page(object $token, object $request, string $preselect = ''): void {
        $order       = wc_get_order((int) $request->order_id);
        $requester   = get_userdata((int) $request->requester_id);
        $company     = null;
        global $wpdb;
        if ($request->company_id) {
            $company = $wpdb->get_row(
                $wpdb->prepare("SELECT name FROM {$wpdb->prefix}b2b_companies WHERE id = %d", (int) $request->company_id)
            );
        }

        $requester_name = $requester ? trim($requester->first_name . ' ' . $requester->last_name) : '';
        if ('' === $requester_name && $requester) {
            $requester_name = $requester->display_name;
        }

        $raw_token = isset($_REQUEST['token']) ? sanitize_text_field(wp_unslash($_REQUEST['token'])) : '';

        $customer_note = $order ? $order->get_customer_note() : '';
        $reference     = $order ? $order->get_meta('_b2b_personal_reference') : '';
        $cost_center   = $order ? $order->get_meta('_b2b_cost_center_label') : '';

        // Calcul des remises : pour chaque ligne, on compare le prix catalogue
        // (regular_price) au prix réellement facturé. La remise totale agrège ces
        // écarts ainsi que les éventuels coupons (sous-total vs total des lignes).
        $cart_subtotal = 0.0; // somme des prix catalogue (avant remise)
        $lines_paid    = 0.0; // somme des montants facturés
        $coupon_codes  = [];
        if ($order) {
            foreach ($order->get_items() as $line_item) {
                $line_product = $line_item->get_product();
                $line_qty     = (int) $line_item->get_quantity();
                $line_paid    = (float) $line_item->get_subtotal();
                $regular_unit = $line_product ? (float) $line_product->get_regular_price() : 0.0;
                $regular_line = $regular_unit > 0 ? $regular_unit * $line_qty : $line_paid;
                if ($regular_line < $line_paid) {
                    $regular_line = $line_paid;
                }
                $cart_subtotal += $regular_line;
                $lines_paid    += (float) $line_item->get_total();
            }
            $coupon_codes = $order->get_coupon_codes();
        }
        $discount = $cart_subtotal - $lines_paid;

        ob_start();
        ?>
        <div class="pe-approval woocommerce">
            <h1 class="pe-approval-title"><?php esc_html_e('Demande d\'approbation', 'portail-entreprises'); ?></h1>

            <p class="pe-approval-intro">
                <?php
                printf(
                    /* translators: 1: requester name, 2: company name */
                    esc_html__('Demande n° %1$s soumise par %2$s.', 'portail-entreprises'),
                    '<strong>#' . esc_html((string) $request->id) . '</strong>',
                    '<strong>' . esc_html($requester_name ?: '—') . ($company ? ' — ' . esc_html($company->name) : '') . '</strong>'
                );
                ?>
                <br />
                <span class="pe-approval-date"><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($request->created_at))); ?></span>
            </p>

            <div class="pe-approval-cart">
                <?php if ($order) : ?>
                <table class="shop_table shop_table_responsive cart pe-approval-cart-table">
                    <thead>
                        <tr>
                            <th class="product-thumbnail">&nbsp;</th>
                            <th class="product-name"><?php esc_html_e('Produit', 'portail-entreprises'); ?></th>
                            <th class="product-price"><?php esc_html_e('Prix', 'portail-entreprises'); ?></th>
                            <th class="product-quantity"><?php esc_html_e('Quantité', 'portail-entreprises'); ?></th>
                            <th class="product-subtotal"><?php esc_html_e('Sous-total', 'portail-entreprises'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order->get_items() as $item) : ?>
                        <?php
                        $product   = $item->get_product();
                        $quantity  = (int) $item->get_quantity();
                        $line_paid = (float) $item->get_subtotal();
                        $unit      = $quantity > 0 ? $line_paid / $quantity : $line_paid;
                        $sku       = $product ? $product->get_sku() : '';
                        $thumbnail = $product ? $product->get_image('woocommerce_thumbnail') : '';
                        $meta_html = wc_display_item_meta($item, ['echo' => false]);
                        $permalink = ($product && $product->is_visible()) ? $product->get_permalink() : '';

                        // Remise éventuelle de la ligne : prix catalogue vs prix facturé.
                        $regular_unit = $product ? (float) $product->get_regular_price() : 0.0;
                        $has_discount = $regular_unit > $unit + 0.01;
                        $regular_line = $regular_unit * $quantity;
                        $pct          = ($has_discount && $regular_unit > 0)
                            ? (int) round(($regular_unit - $unit) / $regular_unit * 100)
                            : 0;
                        ?>
                        <tr class="cart_item">
                            <td class="product-thumbnail">
                                <?php if ($permalink) : ?>
                                <a href="<?php echo esc_url($permalink); ?>"><?php echo wp_kses_post($thumbnail); ?></a>
                                <?php else : ?>
                                <?php echo wp_kses_post($thumbnail); ?>
                                <?php endif; ?>
                            </td>
                            <td class="product-name" data-title="<?php esc_attr_e('Produit', 'portail-entreprises'); ?>">
                                <?php if ($permalink) : ?>
                                <a class="pe-approval-product-name" href="<?php echo esc_url($permalink); ?>"><?php echo esc_html($item->get_name()); ?></a>
                                <?php else : ?>
                                <span class="pe-approval-product-name"><?php echo esc_html($item->get_name()); ?></span>
                                <?php endif; ?>
                                <?php if ($sku) : ?>
                                <span class="pe-approval-sku"><?php esc_html_e('Réf :', 'portail-entreprises'); ?> <?php echo esc_html($sku); ?></span>
                                <?php endif; ?>
                                <?php echo wp_kses_post($meta_html); ?>
                            </td>
                            <td class="product-price" data-title="<?php esc_attr_e('Prix', 'portail-entreprises'); ?>">
                                <?php if ($has_discount) : ?>
                                <del><?php echo wp_kses_post(wc_price($regular_unit)); ?></del>
                                <ins><?php echo wp_kses_post(wc_price($unit)); ?></ins>
                                <span class="pe-approval-pct">−<?php echo esc_html((string) $pct); ?> %</span>
                                <?php else : ?>
                                <?php echo wp_kses_post(wc_price($unit)); ?>
                                <?php endif; ?>
                            </td>
                            <td class="product-quantity" data-title="<?php esc_attr_e('Quantité', 'portail-entreprises'); ?>">
                                <?php echo esc_html((string) $quantity); ?>
                            </td>
                            <td class="product-subtotal" data-title="<?php esc_attr_e('Sous-total', 'portail-entreprises'); ?>">
                                <?php if ($has_discount) : ?>
                                <del><?php echo wp_kses_post(wc_price($regular_line)); ?></del>
                                <ins><?php echo wp_kses_post(wc_price($line_paid)); ?></ins>
                                <?php else : ?>
                                <?php echo wp_kses_post(wc_price($line_paid)); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <div class="cart-collaterals">
                    <div class="cart_totals">
                        <h2><?php esc_html_e('Total panier', 'portail-entreprises'); ?></h2>
                        <table class="shop_table shop_table_responsive">
                            <?php if ($order) : ?>
                            <tr class="cart-subtotal">
                                <th><?php esc_html_e('Sous-total', 'portail-entreprises'); ?></th>
                                <td><?php echo wp_kses_post(wc_price($cart_subtotal)); ?></td>
                            </tr>
                            <?php if ($discount > 0.0001) : ?>
                            <tr class="cart-discount">
                                <th>
                                    <?php esc_html_e('Remise', 'portail-entreprises'); ?>
                                    <?php if (!empty($coupon_codes)) : ?>
                                    <span class="pe-approval-coupons">(<?php echo esc_html(implode(', ', $coupon_codes)); ?>)</span>
                                    <?php endif; ?>
                                </th>
                                <td>−<?php echo wp_kses_post(wc_price($discount)); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php foreach ($order->get_items('shipping') as $shipping_item) : ?>
                            <tr class="shipping">
                                <th><?php echo esc_html($shipping_item->get_name()); ?></th>
                                <td><?php echo wp_kses_post(wc_price($shipping_item->get_total())); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="order-total">
                                <th><?php esc_html_e('Total', 'portail-entreprises'); ?></th>
                                <td><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
                            </tr>
                            <?php else : ?>
                            <tr class="order-total">
                                <th><?php esc_html_e('Total', 'portail-entreprises'); ?></th>
                                <td><?php echo wp_kses_post(wc_price($request->amount)); ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>

                        <?php if ($cost_center || $reference || $customer_note) : ?>
                        <div class="pe-approval-meta">
                            <?php if ($cost_center) : ?>
                            <p><strong><?php esc_html_e('Centre de coût :', 'portail-entreprises'); ?></strong> <?php echo esc_html($cost_center); ?></p>
                            <?php endif; ?>
                            <?php if ($reference) : ?>
                            <p><strong><?php esc_html_e('Référence :', 'portail-entreprises'); ?></strong> <?php echo esc_html($reference); ?></p>
                            <?php endif; ?>
                            <?php if ($customer_note) : ?>
                            <p><strong><?php esc_html_e('Commentaire :', 'portail-entreprises'); ?></strong> <?php echo esc_html($customer_note); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <form method="post" action="<?php echo esc_url(home_url('/approval/')); ?>" class="pe-approval-form wc-proceed-to-checkout">
                            <?php wp_nonce_field('pe_magic_decision_' . (int) $request->id, 'pe_magic_nonce'); ?>
                            <input type="hidden" name="pe_approval" value="1" />
                            <input type="hidden" name="request" value="<?php echo esc_attr((int) $request->id); ?>" />
                            <input type="hidden" name="token" value="<?php echo esc_attr($raw_token); ?>" />

                            <div class="pe-approval-reject-reason" style="<?php echo 'reject' === $preselect ? '' : 'display:none;'; ?>">
                                <label for="pe-approval-reason"><?php esc_html_e('Motif du refus', 'portail-entreprises'); ?></label>
                                <textarea id="pe-approval-reason" name="reason" rows="3"
                                          placeholder="<?php esc_attr_e('Indiquez le motif du refus…', 'portail-entreprises'); ?>"></textarea>
                            </div>

                            <button type="submit" name="decision" value="approve" class="button alt pe-approval-btn pe-approval-btn-approve">
                                <?php esc_html_e('Valider la demande', 'portail-entreprises'); ?>
                            </button>
                            <button type="button" class="button pe-approval-btn pe-approval-btn-reject" id="pe-approval-show-reject">
                                <?php esc_html_e('Refuser la demande', 'portail-entreprises'); ?>
                            </button>
                            <button type="submit" name="decision" value="reject" class="button pe-approval-btn pe-approval-btn-reject"
                                    id="pe-approval-confirm-reject" style="<?php echo 'reject' === $preselect ? '' : 'display:none;'; ?>">
                                <?php esc_html_e('Confirmer le refus', 'portail-entreprises'); ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <script>
                document.getElementById('pe-approval-show-reject').addEventListener('click', function () {
                    document.querySelector('.pe-approval-reject-reason').style.display = 'block';
                    this.style.display = 'none';
                    document.getElementById('pe-approval-confirm-reject').style.display = 'inline-block';
                    document.getElementById('pe-approval-reason').focus();
                });
            </script>
        </div>
        <?php
        $content = ob_get_clean();
        $this->render_layout(__('Demande d\'approbation', 'portail-entreprises'), $content);
    }

    /**
     * Affiche un message simple (succès / erreur / info).
     */
    private function render_message(string $title, string $detail, string $type = 'info', bool $show_approvals_link = false): void {
        $icons = ['success' => '✓', 'error' => '✕', 'info' => 'ℹ'];
        $icon  = $icons[$type] ?? 'ℹ';

        $approvals_link = '';
        if ($show_approvals_link) {
            $approvals_url  = wc_get_account_endpoint_url('b2b-approvals');
            $approvals_link = '<p style="margin-top:12px;font-size:14px;">'
                . esc_html__('Vous pouvez retrouver toutes vos demandes en attente dans votre espace personnel :', 'portail-entreprises')
                . ' <a href="' . esc_url($approvals_url) . '" style="color:#2d6ebd;font-weight:600;">'
                . esc_html__('Mes approbations', 'portail-entreprises')
                . '</a></p>';
        }

        $content = '<div class="pe-ml-card pe-ml-message pe-ml-' . esc_attr($type) . '">'
            . '<div class="pe-ml-icon">' . esc_html($icon) . '</div>'
            . '<h1>' . esc_html($title) . '</h1>'
            . '<p>' . esc_html($detail) . '</p>'
            . $approvals_link
            . '<a href="' . esc_url(home_url('/')) . '" class="pe-ml-home">' . esc_html__('Retour à l\'accueil', 'portail-entreprises') . '</a>'
            . '</div>';

        $this->render_layout($title, $content);
    }

    /**
     * Layout intégré au thème (en-tête/pied du site) pour reproduire la page panier.
     *
     * La page hérite ainsi de l'habillage WooCommerce / du thème (Woodmart, etc.),
     * tout en restant non indexable.
     */
    private function render_layout(string $title, string $content): void {
        status_header(200);

        // Titre du document (onglet navigateur).
        $full_title = $title . ' — ' . get_bloginfo('name');
        add_filter('pre_get_document_title', static function () use ($full_title) {
            return $full_title;
        });
        // Forcer le titre auprès des principaux plugins SEO.
        add_filter('wpseo_title', static function () use ($full_title) {
            return $full_title;
        });
        add_filter('rank_math/frontend/title', static function () use ($full_title) {
            return $full_title;
        });

        // Masquer la barre de titre du thème (affiche « Blog » sur cette page virtuelle) :
        // notre propre titre « Demande d'approbation » prend le relais dans le contenu.
        add_filter('woodmart_page_title', '__return_false');
        add_filter('woodmart_page_title_string', static function () use ($title) {
            return $title;
        });

        add_action('wp_head', static function () {
            echo '<meta name="robots" content="noindex,nofollow" />' . "\n";
        });
        // Styles spécifiques à la page d'approbation.
        add_action('wp_head', [$this, 'output_layout_styles']);

        get_header();
        ?>
        <div class="pe-approval-page">
            <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — contenu construit et échappé en interne ?>
        </div>
        <?php
        get_footer();
    }

    /**
     * Styles de la page d'approbation (panier + messages), injectés dans le thème.
     */
    public function output_layout_styles(): void {
        ?>
    <style>
        /* Masque la barre de titre du thème (« Blog ») sur cette page virtuelle. */
        .wd-page-title, .page-title.title-blog { display:none !important; }
        .pe-approval-page { max-width:1200px; margin:40px auto; padding:0 20px; }
        .pe-approval-title { margin:0 0 12px; }
        .pe-approval-intro { margin:0 0 24px; color:#555; }
        .pe-approval-date { font-size:.9em; color:#888; }
        .pe-approval-cart-table .product-thumbnail img { width:64px; height:auto; }
        .pe-approval-product-name { display:block; font-weight:600; }
        .pe-approval-sku { display:block; font-size:.85em; color:#888; margin-top:2px; }
        .pe-approval-cart-table del { color:#999; font-weight:400; margin-right:6px; }
        .pe-approval-cart-table ins { text-decoration:none; font-weight:600; }
        .pe-approval-pct { display:inline-block; font-size:.8em; color:#dc3545; font-weight:600; margin-left:4px; white-space:nowrap; }
        .pe-approval-cart .cart-collaterals { margin-top:32px; overflow:hidden; }
        .pe-approval-cart .cart_totals { max-width:520px; margin:0 auto; float:none; width:100%; }
        .pe-approval-cart .cart_totals h2 { text-align:center; }
        .pe-approval-cart .cart_totals > table { width:100%; }
        .pe-approval-coupons { font-weight:400; font-size:.85em; color:#888; }
        .pe-approval-meta { margin:16px 0; font-size:.95em; }
        .pe-approval-meta p { margin:0 0 6px; }
        .pe-approval-reject-reason { margin:16px 0; }
        .pe-approval-reject-reason label { display:block; font-weight:600; margin-bottom:6px; }
        .pe-approval-reject-reason textarea { width:100%; padding:10px; border:1px solid #ccc; border-radius:6px; font-family:inherit; }
        .pe-approval-form .pe-approval-btn { display:block; width:100%; margin:8px 0 0; text-align:center; }
        .pe-approval-btn-approve { background:#28a745 !important; border-color:#28a745 !important; color:#fff !important; }
        .pe-approval-btn-approve:hover { background:#218838 !important; border-color:#218838 !important; }
        .pe-approval-btn-reject { background:#dc3545 !important; border-color:#dc3545 !important; color:#fff !important; }
        .pe-approval-btn-reject:hover { background:#c82333 !important; border-color:#c82333 !important; }
        /* Messages (succès / erreur / info) */
        .pe-ml-card { max-width:640px; margin:0 auto; background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 4px 24px rgba(26,39,68,.08); padding:32px; }
        .pe-ml-message { text-align:center; }
        .pe-ml-icon { font-size:48px; width:88px; height:88px; line-height:88px; border-radius:50%; margin:0 auto 20px; color:#fff; }
        .pe-ml-success .pe-ml-icon { background:#28a745; }
        .pe-ml-error .pe-ml-icon { background:#dc3545; }
        .pe-ml-info .pe-ml-icon { background:#2d6ebd; }
        .pe-ml-home { display:inline-block; margin-top:20px; color:#2d6ebd; text-decoration:none; font-weight:600; }
    </style>
        <?php
    }

    /* =========================================================
       Administration des tokens
       ========================================================= */

    /**
     * Liste des tokens avec infos jointes (pour l'écran admin).
     */
    public function get_tokens(string $status_filter = '', int $limit = 100): array {
        global $wpdb;

        $where  = '';
        $params = [];
        if ($status_filter && in_array($status_filter, ['active', 'used', 'expired', 'revoked'], true)) {
            $where    = 'WHERE t.status = %s';
            $params[] = $status_filter;
        }

        $params[] = $limit;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.*, u.display_name AS approver_name, r.order_id, r.status AS request_status
                 FROM {$wpdb->prefix}b2b_approval_tokens t
                 LEFT JOIN {$wpdb->users} u ON u.ID = t.approver_id
                 LEFT JOIN {$wpdb->prefix}b2b_approval_requests r ON r.id = t.request_id
                 {$where}
                 ORDER BY t.created_at DESC
                 LIMIT %d",
                $params
            )
        ) ?: [];
    }

    /**
     * AJAX : révoquer un token.
     */
    public function ajax_revoke_token(): void {
        check_ajax_referer('pe_b2b_ajax', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission refusée.', 'portail-entreprises')]);
        }

        $token_id = isset($_POST['token_id']) ? absint($_POST['token_id']) : 0;
        if (!$token_id) {
            wp_send_json_error(['message' => __('Token invalide.', 'portail-entreprises')]);
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'b2b_approval_tokens',
            ['status' => 'revoked'],
            ['id' => $token_id],
            ['%s'],
            ['%d']
        );

        wp_send_json_success(['message' => __('Token révoqué.', 'portail-entreprises')]);
    }

    /**
     * AJAX : renvoyer l'e-mail d'approbation (régénère les liens).
     */
    public function ajax_resend_email(): void {
        check_ajax_referer('pe_b2b_ajax', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission refusée.', 'portail-entreprises')]);
        }

        $request_id = isset($_POST['request_id']) ? absint($_POST['request_id']) : 0;
        if (!$request_id) {
            wp_send_json_error(['message' => __('Demande invalide.', 'portail-entreprises')]);
        }

        global $wpdb;
        $request = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}b2b_approval_requests WHERE id = %d", $request_id)
        );

        if (!$request || 'pending' !== $request->status) {
            wp_send_json_error(['message' => __('Cette demande n\'est plus en attente.', 'portail-entreprises')]);
        }

        // Révoquer les anciens tokens actifs puis renvoyer.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}b2b_approval_tokens SET status = 'revoked'
                 WHERE request_id = %d AND status = 'active'",
                $request_id
            )
        );

        PE_Approval_Manager::get_instance()->resend_approval_notification($request_id);

        wp_send_json_success(['message' => __('E-mail d\'approbation renvoyé.', 'portail-entreprises')]);
    }
}

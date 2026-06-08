<?php
/**
 * Gestionnaire des budgets B2B.
 */

defined('ABSPATH') || exit;

class PE_Budget_Manager {

    private static ?PE_Budget_Manager $instance = null;

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
        add_action('woocommerce_checkout_process', [$this, 'validate_budget_at_checkout']);
        add_action('woocommerce_order_status_completed', [$this, 'on_order_completed'], 10, 1);

        // Le blocage du bouton checkout est géré côté JS (b2b-portal.js → blockCheckoutIfNeeded)
        // pour assurer la compatibilité WoodMart qui override les templates WooCommerce.
        // On garde uniquement le filtre checkout pour bloquer la validation serveur.
        add_filter('woocommerce_order_button_html', [$this, 'maybe_hide_place_order_button']);

        // Blocage serveur du mini-panier / panier flottant WoodMart : remplace les boutons natifs.
        add_action('woocommerce_widget_shopping_cart_buttons', [$this, 'maybe_block_minicart_button'], 1);

        // Cron mensuel de remise à zéro
        add_action('pe_reset_monthly_budgets', [$this, 'reset_monthly_usage']);

        if (!wp_next_scheduled('pe_reset_monthly_budgets')) {
            $next_month = mktime(0, 0, 0, (int) date('n') + 1, 1, (int) date('Y'));
            wp_schedule_event($next_month, 'monthly', 'pe_reset_monthly_budgets');
        }
    }

    /**
     * Vérifie si l'utilisateur courant peut procéder au checkout.
     * Retourne true si autorisé, string (message d'erreur) sinon.
     *
     * @return true|string
     */
    public function get_checkout_block_reason(): true|string {
        $user_id = get_current_user_id();

        if (!$user_id || !PE_Permissions::is_b2b_user($user_id)) {
            return true;
        }

        // Rôle "Demandeur" : ne peut jamais commander directement.
        $role = PE_Permissions::get_user_b2b_role($user_id);
        if ('requester' === $role) {
            return __('En tant que Demandeur, vous ne pouvez pas passer commande directement. Soumettez votre panier pour validation.', 'portail-entreprises');
        }

        // Vérification du budget si le panier est chargé.
        if (!WC()->cart || WC()->cart->is_empty()) {
            return true;
        }

        $total  = (float) WC()->cart->get_total('raw');
        $result = $this->check_budget($user_id, $total);

        if (is_wp_error($result)) {
            return $result->get_error_message();
        }

        return true;
    }

    /**
     * Remplace le bouton "Passer la commande" dans la page panier si l'utilisateur est bloqué.
     * Priorité 1 pour s'exécuter avant le bouton WooCommerce (priorité 20).
     */
    public function maybe_block_cart_checkout_button(): void {
        $reason = $this->get_checkout_block_reason();
        if (true === $reason) {
            return;
        }

        // Supprimer le bouton natif WooCommerce.
        remove_action('woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20);

        echo '<div class="woocommerce-info pe-checkout-blocked">'
            . '<span class="pe-checkout-blocked-icon">⛔</span> '
            . wp_kses_post($reason)
            . '</div>';

        // Proposer le bouton "Demander une approbation".
        // HTML construit en interne avec échappement ; wp_kses_post retirerait <form>/<button>.
        echo $this->get_approval_button_for_render('cart'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Renvoie le HTML du bouton de demande d'approbation (avec form/nonce).
     */
    private function get_approval_button_for_render(string $context): string {
        if (!class_exists('PE_Approval_Manager')) {
            return '';
        }
        return PE_Approval_Manager::get_instance()->get_approval_button_html($context);
    }

    /**
     * Masque le bouton "Passer la commande" sur la page checkout si bloqué.
     */
    public function maybe_hide_place_order_button(string $button_html): string {
        $reason = $this->get_checkout_block_reason();
        if (true === $reason) {
            return $button_html;
        }

        return '<div class="woocommerce-error pe-checkout-blocked" style="margin-top:16px;">'
            . wp_kses_post($reason)
            . '</div>'
            . $this->get_approval_button_for_render('cart');
    }

    /**
     * Remplace le bouton checkout dans le mini-panier si bloqué.
     * Priorité 1 pour s'exécuter avant les boutons natifs (priorité 10).
     */
    public function maybe_block_minicart_button(): void {
        $reason = $this->get_checkout_block_reason();
        if (true === $reason) {
            return;
        }

        // Supprimer les boutons natifs (View Cart + Checkout).
        remove_action('woocommerce_widget_shopping_cart_buttons', 'woocommerce_widget_shopping_cart_button_view_cart', 10);
        remove_action('woocommerce_widget_shopping_cart_buttons', 'woocommerce_widget_shopping_cart_proceed_to_checkout', 20);

        echo '<p class="woocommerce-mini-cart__buttons pe-checkout-blocked-mini">'
            . '<a href="' . esc_url(wc_get_cart_url()) . '" class="button wc-forward">'
            . esc_html__('Voir le panier', 'portail-entreprises')
            . '</a>'
            . '</p>'
            . '<p class="pe-checkout-blocked-notice" style="font-size:0.85em;color:#b32d2e;margin:8px 0 0;padding:0 16px;">'
            . wp_kses_post($reason)
            . '</p>';

        // Bouton "Demander une approbation" dans le mini-panier.
        echo $this->get_approval_button_for_render('minicart'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Vérifie si un utilisateur a suffisamment de budget pour une commande.
     *
     * @return bool|WP_Error true si OK, WP_Error si dépassement.
     */
    public function check_budget(int $user_id, float $amount): bool|\WP_Error {
        global $wpdb;

        $user_data = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT uc.budget_monthly, uc.budget_annual, uc.budget_per_order, uc.company_id
                 FROM {$wpdb->prefix}b2b_user_company uc
                 WHERE uc.user_id = %d AND uc.is_primary = 1
                 LIMIT 1",
                $user_id
            )
        );

        if (!$user_data) {
            return true; // Pas de données B2B, pas de restriction
        }

        // Vérification du budget par commande
        if ($user_data->budget_per_order !== null && $amount > (float) $user_data->budget_per_order) {
            return new \WP_Error(
                'budget_per_order_exceeded',
                sprintf(
                    /* translators: 1: amount, 2: limit */
                    __('Le montant de cette commande (%1$s) dépasse votre budget par commande autorisé (%2$s).', 'portail-entreprises'),
                    wc_price($amount),
                    wc_price($user_data->budget_per_order)
                )
            );
        }

        $period = date('Ym');
        $year   = date('Y');

        // Vérification du budget mensuel
        if ($user_data->budget_monthly !== null) {
            $spent_month = $this->get_usage($user_id, $period);
            if (($spent_month + $amount) > (float) $user_data->budget_monthly) {
                return new \WP_Error(
                    'budget_monthly_exceeded',
                    sprintf(
                        /* translators: 1: remaining budget */
                        __('Cette commande dépasse votre budget mensuel. Budget restant : %s.', 'portail-entreprises'),
                        wc_price(max(0, (float) $user_data->budget_monthly - $spent_month))
                    )
                );
            }
        }

        // Vérification du budget annuel
        if ($user_data->budget_annual !== null) {
            $spent_year = $this->get_annual_usage($user_id, $year);
            if (($spent_year + $amount) > (float) $user_data->budget_annual) {
                return new \WP_Error(
                    'budget_annual_exceeded',
                    sprintf(
                        /* translators: 1: remaining budget */
                        __('Cette commande dépasse votre budget annuel. Budget restant : %s.', 'portail-entreprises'),
                        wc_price(max(0, (float) $user_data->budget_annual - $spent_year))
                    )
                );
            }
        }

        // Company-level budget check
        $company_data = $wpdb->get_row($wpdb->prepare(
            "SELECT budget_monthly, budget_annual, budget_block_enabled FROM {$wpdb->prefix}b2b_companies WHERE id = %d LIMIT 1",
            (int) $user_data->company_id
        ));
        if ($company_data && (int) $company_data->budget_block_enabled === 1) {
            if ($company_data->budget_monthly !== null) {
                $co_spent_month = (float) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(amount_spent),0) FROM {$wpdb->prefix}b2b_budget_usage WHERE company_id = %d AND period_month = %s",
                    (int) $user_data->company_id, $period
                ));
                if (($co_spent_month + $amount) > (float) $company_data->budget_monthly) {
                    return new \WP_Error('company_budget_monthly_exceeded',
                        sprintf(__('Cette commande dépasse le budget mensuel de l\'entreprise. Budget restant : %s.', 'portail-entreprises'),
                            wc_price(max(0, (float) $company_data->budget_monthly - $co_spent_month)))
                    );
                }
            }
            if ($company_data->budget_annual !== null) {
                $co_spent_year = (float) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(SUM(amount_spent),0) FROM {$wpdb->prefix}b2b_budget_usage WHERE company_id = %d AND period_year = %s",
                    (int) $user_data->company_id, $year
                ));
                if (($co_spent_year + $amount) > (float) $company_data->budget_annual) {
                    return new \WP_Error('company_budget_annual_exceeded',
                        sprintf(__('Cette commande dépasse le budget annuel de l\'entreprise. Budget restant : %s.', 'portail-entreprises'),
                            wc_price(max(0, (float) $company_data->budget_annual - $co_spent_year)))
                    );
                }
            }
        }

        return true;
    }

    /**
     * Récupère le montant dépensé pour une période (YYYYMM).
     */
    public function get_usage(int $user_id, string $period): float {
        global $wpdb;

        $amount = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT amount_spent FROM {$wpdb->prefix}b2b_budget_usage
                 WHERE user_id = %d AND period_month = %s
                 LIMIT 1",
                $user_id,
                $period
            )
        );

        return $amount !== null ? (float) $amount : 0.0;
    }

    /**
     * Récupère le montant dépensé sur une année.
     */
    public function get_annual_usage(int $user_id, string $year): float {
        global $wpdb;

        $amount = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(amount_spent) FROM {$wpdb->prefix}b2b_budget_usage
                 WHERE user_id = %d AND period_year = %s",
                $user_id,
                $year
            )
        );

        return $amount !== null ? (float) $amount : 0.0;
    }

    /**
     * Enregistre l'utilisation du budget après une commande.
     */
    public function record_usage(int $user_id, int $company_id, float $amount): void {
        global $wpdb;

        $period = date('Ym');
        $year   = date('Y');

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}b2b_budget_usage
                 WHERE user_id = %d AND company_id = %d AND period_month = %s
                 LIMIT 1",
                $user_id,
                $company_id,
                $period
            )
        );

        if ($existing) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}b2b_budget_usage
                     SET amount_spent = amount_spent + %f, order_count = order_count + 1, updated_at = %s
                     WHERE user_id = %d AND company_id = %d AND period_month = %s",
                    $amount,
                    current_time('mysql'),
                    $user_id,
                    $company_id,
                    $period
                )
            );
        } else {
            $wpdb->insert(
                $wpdb->prefix . 'b2b_budget_usage',
                [
                    'user_id'      => $user_id,
                    'company_id'   => $company_id,
                    'period_month' => $period,
                    'period_year'  => $year,
                    'amount_spent' => $amount,
                    'order_count'  => 1,
                    'updated_at'   => current_time('mysql'),
                ],
                ['%d', '%d', '%s', '%s', '%f', '%d', '%s']
            );
        }

        // Company-level tracking (user_id = 0 = company aggregate)
        $existing_co = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}b2b_budget_usage WHERE user_id = 0 AND company_id = %d AND period_month = %s LIMIT 1",
            $company_id, $period
        ));
        if ($existing_co) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}b2b_budget_usage SET amount_spent = amount_spent + %f, order_count = order_count + 1, updated_at = %s WHERE user_id = 0 AND company_id = %d AND period_month = %s",
                $amount, current_time('mysql'), $company_id, $period
            ));
        } else {
            $wpdb->insert($wpdb->prefix . 'b2b_budget_usage', [
                'user_id'      => 0,
                'company_id'   => $company_id,
                'period_month' => $period,
                'period_year'  => $year,
                'amount_spent' => $amount,
                'order_count'  => 1,
                'updated_at'   => current_time('mysql'),
            ], ['%d', '%d', '%s', '%s', '%f', '%d', '%s']);
        }
    }

    /**
     * Valide le budget au checkout WooCommerce.
     */
    public function validate_budget_at_checkout(): void {
        $user_id = get_current_user_id();

        if (!$user_id || !PE_Permissions::is_b2b_user($user_id)) {
            return;
        }

        $total  = (float) WC()->cart->get_total('raw');
        $result = $this->check_budget($user_id, $total);

        if (is_wp_error($result)) {
            wc_add_notice($result->get_error_message(), 'error');
        }
    }

    /**
     * Enregistre l'utilisation du budget quand une commande est complétée.
     */
    public function on_order_completed(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $user_id = (int) $order->get_customer_id();
        if (!$user_id || !PE_Permissions::is_b2b_user($user_id)) {
            return;
        }

        $company = PE_Permissions::get_user_company($user_id);
        if (!$company) {
            return;
        }

        $this->record_usage($user_id, (int) $company->id, (float) $order->get_total());
    }

    /**
     * Remet à zéro les usages mensuels (pour le cron).
     */
    public function reset_monthly_usage(): void {
        // Les données historiques sont conservées, on ne fait rien car la lecture
        // se fait par période. La remise à zéro est implicite via le changement de période.
        // Si on voulait purger les vieilles données :
        global $wpdb;
        $cutoff = date('Ym', strtotime('-13 months'));
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}b2b_budget_usage WHERE period_month < %s",
                $cutoff
            )
        );
    }

    /**
     * Récupère les données d'utilisation pour l'affichage frontend.
     */
    public function get_usage_summary(int $user_id): array {
        global $wpdb;

        $period = date('Ym');
        $year   = date('Y');

        $user_data = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT uc.budget_monthly, uc.budget_annual, uc.budget_per_order
                 FROM {$wpdb->prefix}b2b_user_company uc
                 WHERE uc.user_id = %d AND uc.is_primary = 1
                 LIMIT 1",
                $user_id
            )
        );

        $spent_month = $this->get_usage($user_id, $period);
        $spent_year  = $this->get_annual_usage($user_id, $year);

        return [
            'budget_monthly'      => $user_data ? (float) $user_data->budget_monthly : null,
            'budget_annual'       => $user_data ? (float) $user_data->budget_annual : null,
            'budget_per_order'    => $user_data ? (float) $user_data->budget_per_order : null,
            'spent_month'         => $spent_month,
            'spent_year'          => $spent_year,
            'remaining_month'     => $user_data && $user_data->budget_monthly ? max(0, (float) $user_data->budget_monthly - $spent_month) : null,
            'remaining_year'      => $user_data && $user_data->budget_annual ? max(0, (float) $user_data->budget_annual - $spent_year) : null,
            'percent_month'       => ($user_data && $user_data->budget_monthly && $user_data->budget_monthly > 0)
                                        ? min(100, round($spent_month / (float) $user_data->budget_monthly * 100, 1))
                                        : null,
        ];
    }

    /**
     * Récupère les données de budget pour une entreprise entière.
     */
    public function get_company_usage_summary(int $company_id): array {
        global $wpdb;
        $period = date('Ym');
        $year   = date('Y');
        $company = $wpdb->get_row($wpdb->prepare(
            "SELECT budget_monthly, budget_annual, budget_block_enabled FROM {$wpdb->prefix}b2b_companies WHERE id = %d LIMIT 1",
            $company_id
        ));
        if (!$company) return ['budget_monthly' => null, 'budget_annual' => null, 'block_enabled' => true, 'spent_month' => 0.0, 'spent_year' => 0.0, 'remaining_month' => null, 'remaining_year' => null, 'percent_month' => null];

        // Sum all usage for the company across all users
        $spent_month = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount_spent),0) FROM {$wpdb->prefix}b2b_budget_usage WHERE company_id = %d AND period_month = %s",
            $company_id, $period
        ));
        $spent_year = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount_spent),0) FROM {$wpdb->prefix}b2b_budget_usage WHERE company_id = %d AND period_year = %s",
            $company_id, $year
        ));

        $budget_monthly = $company->budget_monthly !== null ? (float) $company->budget_monthly : null;
        $budget_annual  = $company->budget_annual  !== null ? (float) $company->budget_annual  : null;

        return [
            'budget_monthly'  => $budget_monthly,
            'budget_annual'   => $budget_annual,
            'block_enabled'   => (bool) $company->budget_block_enabled,
            'spent_month'     => $spent_month,
            'spent_year'      => $spent_year,
            'remaining_month' => $budget_monthly !== null ? max(0, $budget_monthly - $spent_month) : null,
            'remaining_year'  => $budget_annual  !== null ? max(0, $budget_annual  - $spent_year)  : null,
            'percent_month'   => ($budget_monthly && $budget_monthly > 0) ? min(100, round($spent_month / $budget_monthly * 100, 1)) : null,
            'percent_year'    => ($budget_annual  && $budget_annual  > 0) ? min(100, round($spent_year  / $budget_annual  * 100, 1)) : null,
        ];
    }
}

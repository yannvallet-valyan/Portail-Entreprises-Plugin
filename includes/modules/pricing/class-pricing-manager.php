<?php
/**
 * Application de la remise entreprise aux prix WooCommerce.
 *
 * Ce module lit le champ `discount_rate` de la société B2B de l'utilisateur
 * et applique la réduction à chaque article du panier WooCommerce via le hook
 * `woocommerce_before_calculate_totals` (priorité 90, avant la remise admin
 * de wc-quotes-woodmart qui s'exécute à la priorité 100).
 *
 * @package PortailEntreprises
 */

defined('ABSPATH') || exit;

class PE_Pricing_Manager {

    private static ?PE_Pricing_Manager $instance = null;

    /**
     * Cache des prix originaux par cart_item_key pour rendre apply_company_discount idempotent.
     * Sans ça, plusieurs appels à calculate_totals() dans la même requête composeraient la remise.
     */
    private array $original_prices = [];

    private function __construct() {}

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        add_action('woocommerce_before_calculate_totals', [$this, 'apply_company_discount'], 90);

        add_filter('woocommerce_cart_item_subtotal', [$this, 'display_discounted_subtotal'], 20, 3);

        add_action('woocommerce_cart_item_removed', [$this, 'invalidate_cache_for_key']);
        add_action('woocommerce_add_to_cart',       [$this, 'invalidate_full_cache']);
        add_action('woocommerce_cart_emptied',       [$this, 'invalidate_full_cache']);

        add_filter('woocommerce_get_cart_item_from_session', [$this, 'restore_cart_item_data'], 10, 2);
    }

    /**
     * Applique la remise entreprise aux articles du panier.
     * Idempotent grâce à $original_prices.
     */
    public function apply_company_discount(\WC_Cart $cart): void {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        $user_id = get_current_user_id();
        if (!$user_id || !PE_Permissions::is_b2b_user($user_id)) {
            return;
        }

        $company = PE_Permissions::get_user_company($user_id);
        if (!$company || empty($company->discount_rate) || (float) $company->discount_rate <= 0) {
            return;
        }

        $discount_rate = (float) $company->discount_rate;

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];

            if (!is_object($product) || !method_exists($product, 'get_price')) {
                continue;
            }

            // Mémoriser le prix d'origine la première fois.
            if (!isset($this->original_prices[$cart_item_key])) {
                $this->original_prices[$cart_item_key] = floatval($product->get_price());
            }

            $original_price = $this->original_prices[$cart_item_key];
            $new_price      = max(0, round($original_price * (1 - $discount_rate / 100), wc_get_price_decimals()));

            $product->set_price($new_price);

            // Marquer le cart item pour que wc-quote puisse détecter la remise B2B.
            $cart->cart_contents[$cart_item_key]['pe_b2b_discount_rate']   = $discount_rate;
            $cart->cart_contents[$cart_item_key]['pe_b2b_original_price']  = $original_price;
        }
    }

    /**
     * Affiche le prix barré (original) + prix remisé dans les sous-totaux du panier / checkout.
     */
    public function display_discounted_subtotal(string $subtotal, array $cart_item, string $cart_item_key): string {
        if (empty($cart_item['pe_b2b_discount_rate']) || (float) $cart_item['pe_b2b_discount_rate'] <= 0) {
            return $subtotal;
        }

        $discount_rate = (float) $cart_item['pe_b2b_discount_rate'];
        $quantity      = $cart_item['quantity'];

        $discounted_price = floatval($cart_item['data']->get_price());

        if (isset($this->original_prices[$cart_item_key])) {
            $original_price = $this->original_prices[$cart_item_key];
        } else {
            $original_price = $discounted_price / max(0.001, 1 - ($discount_rate / 100));
        }

        $original_subtotal   = wc_price($original_price * $quantity);
        $discounted_subtotal = wc_price($discounted_price * $quantity);

        return '<del style="color:#999;font-size:.9em;">' . $original_subtotal . '</del>&nbsp;' . $discounted_subtotal;
    }

    public function invalidate_cache_for_key(string $cart_item_key): void {
        unset($this->original_prices[$cart_item_key]);
    }

    public function invalidate_full_cache(): void {
        $this->original_prices = [];
    }

    public function restore_cart_item_data(array $cart_item, array $values): array {
        foreach (['pe_b2b_discount_rate', 'pe_b2b_original_price'] as $key) {
            if (isset($values[$key])) {
                $cart_item[$key] = $values[$key];
            }
        }
        return $cart_item;
    }
}

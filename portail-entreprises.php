<?php
/**
 * Plugin Name: Portail Entreprises B2B
 * Plugin URI:  https://b-mesure.fr
 * Description: Portail B2B complet pour WooCommerce : gestion des entreprises, utilisateurs, budgets, workflow d'approbation et audit.
 * Version:     1.0.0
 * Author:      B MESURE
 * Author URI:  https://b-mesure.fr
 * Text Domain: portail-entreprises
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * WC requires at least: 9.0
 * WC tested up to: 10.8.1
 */

defined('ABSPATH') || exit;

// Constants
define('PE_VERSION', '1.0.0');
define('PE_PATH', plugin_dir_path(__FILE__));
define('PE_URL', plugin_dir_url(__FILE__));
define('PE_DB_VERSION', '1.0.0');

// Declare WooCommerce HPOS compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, false);
    }
});

// Activation / Deactivation hooks
register_activation_hook(__FILE__, function () {
    require_once PE_PATH . 'includes/class-installer.php';
    PE_Installer::install();

    // Enregistrer les endpoints et flusher les rewrite rules.
    add_rewrite_endpoint('b2b-dashboard', EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('b2b-company',   EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('b2b-users',     EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('b2b-budgets',   EP_ROOT | EP_PAGES);
    add_rewrite_endpoint('b2b-approvals', EP_ROOT | EP_PAGES);
    flush_rewrite_rules();

    // Flag pour flush différé si activation sans request (CLI, etc.)
    update_option('pe_flush_rewrite_rules', 1);
});

register_deactivation_hook(__FILE__, function () {
    // Nettoyer les endpoints et flusher.
    flush_rewrite_rules();
    delete_option('pe_flush_rewrite_rules');
});

// Bootstrap plugin
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>' .
                esc_html__('Portail Entreprises B2B nécessite WooCommerce pour fonctionner.', 'portail-entreprises') .
                '</p></div>';
        });
        return;
    }

    require_once PE_PATH . 'includes/class-installer.php';
    PE_Installer::install(); // S'assure que les tables existent (idempotent via version check).
    require_once PE_PATH . 'includes/class-permissions.php';
    require_once PE_PATH . 'includes/modules/audit/class-audit-log.php';
    require_once PE_PATH . 'includes/modules/companies/class-company-manager.php';
    require_once PE_PATH . 'includes/modules/users/class-user-manager.php';
    require_once PE_PATH . 'includes/modules/budgets/class-budget-manager.php';
    require_once PE_PATH . 'includes/modules/approval/class-approval-manager.php';
    require_once PE_PATH . 'includes/class-core.php';

    PE_Core::get_instance();
});

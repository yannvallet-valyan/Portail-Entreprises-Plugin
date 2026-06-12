<?php
/**
 * Uninstall script.
 *
 * Par défaut, les données sont CONSERVÉES lors de la suppression du plugin afin
 * de pouvoir réinstaller sans tout perdre (sociétés, utilisateurs, budgets…).
 *
 * Pour supprimer définitivement toutes les données, activez l'option
 * « Supprimer toutes les données à la désinstallation » dans
 * Portail B2B → Paramètres avant de supprimer le plugin.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

$settings = get_option('pe_settings', []);

// Sécurité : ne rien supprimer sauf si l'utilisateur l'a explicitement demandé.
if (empty($settings['delete_data_on_uninstall'])) {
    return;
}

$tables = [
    $wpdb->prefix . 'b2b_audit_log',
    $wpdb->prefix . 'b2b_approval_tokens',
    $wpdb->prefix . 'b2b_approval_requests',
    $wpdb->prefix . 'b2b_approval_rules',
    $wpdb->prefix . 'b2b_budget_usage',
    $wpdb->prefix . 'b2b_cost_centers',
    $wpdb->prefix . 'b2b_user_company',
    $wpdb->prefix . 'b2b_agencies',
    $wpdb->prefix . 'b2b_companies',
];

foreach ($tables as $table) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
}

// Supprime les options
delete_option('pe_db_version');
delete_option('pe_settings');
delete_option('pe_rewrite_version');
delete_option('pe_flush_rewrite_rules');
delete_option('pe_profile_roles_synced');

// Supprime les options de visibilité des commandes (une par entreprise).
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'pe_order_visibility_%'");

// Supprime les user meta
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('b2b_enabled', 'b2b_role', 'b2b_company_id')");

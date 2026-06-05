<?php
/**
 * Uninstall script — supprime toutes les données du plugin.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

$tables = [
    $wpdb->prefix . 'b2b_audit_log',
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

// Supprime les user meta
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('b2b_enabled', 'b2b_role', 'b2b_company_id')");

<?php
/**
 * Gestion des permissions B2B.
 */

defined('ABSPATH') || exit;

class PE_Permissions {

    /**
     * Matrice des capacités par rôle.
     */
    private const ROLE_CAPABILITIES = [
        'company_admin' => [
            'manage_company_users',
            'view_all_orders',
            'edit_budgets',
            'manage_addresses',
            'approve_orders',
            'place_orders',
            'view_company_orders',
            'create_quotes',
            'view_orders',
            'download_invoices',
        ],
        'purchase_manager' => [
            'approve_orders',
            'place_orders',
            'view_company_orders',
        ],
        'buyer' => [
            'create_quotes',
            'place_orders',
        ],
        'requester' => [
            'create_cart',
        ],
        'accountant' => [
            'view_orders',
            'download_invoices',
        ],
    ];

    /**
     * Vérifie si un utilisateur est un utilisateur B2B.
     */
    public static function is_b2b_user(int $user_id): bool {
        return (bool) get_user_meta($user_id, 'b2b_enabled', true);
    }

    /**
     * Récupère le rôle B2B d'un utilisateur.
     */
    public static function get_user_b2b_role(int $user_id): ?string {
        global $wpdb;

        $cache_key = 'pe_user_b2b_role_' . $user_id;
        $cached = wp_cache_get($cache_key, 'portail-entreprises');

        if (false !== $cached) {
            return $cached ?: null;
        }

        $role = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT role FROM {$wpdb->prefix}b2b_user_company WHERE user_id = %d AND is_primary = 1 LIMIT 1",
                $user_id
            )
        );

        wp_cache_set($cache_key, $role ?? '', 'portail-entreprises', 300);

        return $role ?: null;
    }

    /**
     * Récupère l'entreprise d'un utilisateur.
     */
    public static function get_user_company(int $user_id): ?object {
        global $wpdb;

        $cache_key = 'pe_user_company_' . $user_id;
        $cached = wp_cache_get($cache_key, 'portail-entreprises');

        if (false !== $cached) {
            return $cached ?: null;
        }

        $company = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT c.* FROM {$wpdb->prefix}b2b_companies c
                 INNER JOIN {$wpdb->prefix}b2b_user_company uc ON uc.company_id = c.id
                 WHERE uc.user_id = %d AND uc.is_primary = 1
                 LIMIT 1",
                $user_id
            )
        );

        wp_cache_set($cache_key, $company ?? false, 'portail-entreprises', 300);

        return $company ?: null;
    }

    /**
     * Vérifie si l'utilisateur a une capacité donnée.
     */
    public static function user_can(int $user_id, string $capability): bool {
        $role = self::get_user_b2b_role($user_id);

        if (null === $role) {
            return false;
        }

        return in_array($capability, self::ROLE_CAPABILITIES[$role] ?? [], true);
    }

    /**
     * Vérifie si l'utilisateur appartient à l'entreprise donnée.
     */
    public static function user_belongs_to_company(int $user_id, int $company_id): bool {
        static $memo = [];

        $key = $user_id . '-' . $company_id;

        if (array_key_exists($key, $memo)) {
            return $memo[$key];
        }

        global $wpdb;

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}b2b_user_company WHERE user_id = %d AND company_id = %d",
                $user_id,
                $company_id
            )
        );

        $memo[$key] = (int) $count > 0;

        return $memo[$key];
    }

    /**
     * Retourne toutes les capacités d'un rôle.
     */
    public static function get_role_capabilities(string $role): array {
        return self::ROLE_CAPABILITIES[$role] ?? [];
    }

    /**
     * Retourne tous les rôles disponibles.
     */
    public static function get_roles(): array {
        return [
            'company_admin'    => __('Administrateur société', 'portail-entreprises'),
            'purchase_manager' => __('Responsable achats', 'portail-entreprises'),
            'buyer'            => __('Acheteur', 'portail-entreprises'),
            'requester'        => __('Demandeur', 'portail-entreprises'),
            'accountant'       => __('Comptable', 'portail-entreprises'),
        ];
    }

    /**
     * Invalide le cache des permissions d'un utilisateur.
     */
    public static function flush_user_cache(int $user_id): void {
        wp_cache_delete('pe_user_b2b_role_' . $user_id, 'portail-entreprises');
        wp_cache_delete('pe_user_company_' . $user_id, 'portail-entreprises');
    }
}

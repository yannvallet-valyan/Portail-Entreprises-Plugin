<?php
/**
 * Gestionnaire des utilisateurs B2B.
 */

defined('ABSPATH') || exit;

class PE_User_Manager {

    private static ?PE_User_Manager $instance = null;

    private function __construct() {}

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Active le mode B2B pour un utilisateur.
     */
    public function enable_b2b(int $user_id): void {
        update_user_meta($user_id, 'b2b_enabled', 1);

        PE_Audit_Log::get_instance()->log(
            get_current_user_id(),
            null,
            'enable_b2b',
            'user',
            $user_id,
            []
        );
    }

    /**
     * Désactive le mode B2B pour un utilisateur.
     */
    public function disable_b2b(int $user_id): void {
        update_user_meta($user_id, 'b2b_enabled', 0);
        PE_Permissions::flush_user_cache($user_id);

        PE_Audit_Log::get_instance()->log(
            get_current_user_id(),
            null,
            'disable_b2b',
            'user',
            $user_id,
            []
        );
    }

    /**
     * Crée un sous-compte B2B.
     *
     * @param array  $data       Données de l'utilisateur (email, first_name, last_name, password).
     * @param int    $company_id ID de l'entreprise.
     * @param string $role       Rôle B2B.
     * @return int|WP_Error ID de l'utilisateur ou erreur.
     */
    public function create_sub_account(array $data, int $company_id, string $role): int|\WP_Error {
        if (empty($data['email'])) {
            return new \WP_Error('missing_email', __('L\'adresse e-mail est requise.', 'portail-entreprises'));
        }

        if (!is_email($data['email'])) {
            return new \WP_Error('invalid_email', __('L\'adresse e-mail est invalide.', 'portail-entreprises'));
        }

        if (email_exists($data['email'])) {
            return new \WP_Error('email_exists', __('Cette adresse e-mail est déjà utilisée.', 'portail-entreprises'));
        }

        $password  = !empty($data['password']) ? $data['password'] : wp_generate_password(16, true);
        $user_data = [
            'user_email' => sanitize_email($data['email']),
            'user_login' => sanitize_user($data['email']),
            'user_pass'  => $password,
            'first_name' => sanitize_text_field($data['first_name'] ?? ''),
            'last_name'  => sanitize_text_field($data['last_name'] ?? ''),
            'role'       => 'customer',
        ];

        $user_id = wp_insert_user($user_data);

        if (is_wp_error($user_id)) {
            return $user_id;
        }

        $this->enable_b2b($user_id);

        $company_manager = PE_Company_Manager::get_instance();
        $opts            = [
            'agency_id'        => $data['agency_id'] ?? null,
            'budget_monthly'   => $data['budget_monthly'] ?? null,
            'budget_annual'    => $data['budget_annual'] ?? null,
            'budget_per_order' => $data['budget_per_order'] ?? null,
        ];

        $company_manager->add_user_to_company($user_id, $company_id, $role, $opts);

        // Envoi du mot de passe par e-mail si généré
        if (empty($data['password'])) {
            $settings   = get_option('pe_settings', []);
            $from_email = isset($settings['from_email']) ? trim((string) $settings['from_email']) : '';
            if (!is_email($from_email)) {
                $from_email = get_option('admin_email');
            }
            $headers = is_email($from_email)
                ? [sprintf('From: %s <%s>', wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES), $from_email)]
                : [];

            wp_mail(
                $data['email'],
                __('Votre accès au portail B2B', 'portail-entreprises'),
                sprintf(
                    /* translators: 1: login URL, 2: email, 3: password */
                    __("Bonjour,\n\nVotre compte B2B a été créé.\n\nIdentifiant : %1\$s\nMot de passe : %2\$s\n\nConnectez-vous ici : %3\$s\n\nCordialement,\nL'équipe B MESURE", 'portail-entreprises'),
                    $data['email'],
                    $password,
                    wc_get_page_permalink('myaccount')
                ),
                $headers
            );
        }

        PE_Audit_Log::get_instance()->log(
            get_current_user_id(),
            $company_id,
            'create_sub_account',
            'user',
            $user_id,
            ['email' => $data['email'], 'role' => $role]
        );

        return $user_id;
    }

    /**
     * Suspend un utilisateur B2B.
     */
    public function suspend_user(int $user_id): void {
        update_user_meta($user_id, 'b2b_suspended', 1);

        PE_Audit_Log::get_instance()->log(
            get_current_user_id(),
            null,
            'suspend_user',
            'user',
            $user_id,
            []
        );
    }

    /**
     * Réactive un utilisateur B2B suspendu.
     */
    public function reactivate_user(int $user_id): void {
        delete_user_meta($user_id, 'b2b_suspended');

        PE_Audit_Log::get_instance()->log(
            get_current_user_id(),
            null,
            'reactivate_user',
            'user',
            $user_id,
            []
        );
    }

    /**
     * Vérifie si un utilisateur est suspendu.
     */
    public function is_suspended(int $user_id): bool {
        return (bool) get_user_meta($user_id, 'b2b_suspended', true);
    }

    /**
     * Récupère les utilisateurs d'une entreprise avec leurs infos budgétaires.
     */
    public function get_company_users_with_budgets(int $company_id): array {
        global $wpdb;

        $period = date('Ym');

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT uc.*, u.user_email, u.display_name,
                        COALESCE(bu.amount_spent, 0) as amount_spent_month,
                        COALESCE(bu.order_count, 0) as order_count_month
                 FROM {$wpdb->prefix}b2b_user_company uc
                 INNER JOIN {$wpdb->users} u ON u.ID = uc.user_id
                 LEFT JOIN {$wpdb->prefix}b2b_budget_usage bu ON bu.user_id = uc.user_id
                     AND bu.company_id = uc.company_id
                     AND bu.period_month = %s
                 WHERE uc.company_id = %d
                 ORDER BY u.display_name ASC",
                $period,
                $company_id
            )
        ) ?: [];
    }

    /**
     * Met à jour les paramètres budgétaires d'un utilisateur dans une entreprise.
     */
    public function update_user_budget(int $user_id, int $company_id, array $budget_data): bool {
        global $wpdb;

        $update = [];
        $format = [];

        if (array_key_exists('budget_monthly', $budget_data)) {
            $update['budget_monthly'] = $budget_data['budget_monthly'] !== null ? (float) $budget_data['budget_monthly'] : null;
            $format[]                 = '%f';
        }
        if (array_key_exists('budget_annual', $budget_data)) {
            $update['budget_annual'] = $budget_data['budget_annual'] !== null ? (float) $budget_data['budget_annual'] : null;
            $format[]                = '%f';
        }
        if (array_key_exists('budget_per_order', $budget_data)) {
            $update['budget_per_order'] = $budget_data['budget_per_order'] !== null ? (float) $budget_data['budget_per_order'] : null;
            $format[]                   = '%f';
        }
        if (isset($budget_data['role'])) {
            $allowed = array_keys(PE_Permissions::get_roles());
            if (in_array($budget_data['role'], $allowed, true)) {
                $update['role'] = $budget_data['role'];
                $format[]       = '%s';
            }
        }

        if (empty($update)) {
            return false;
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'b2b_user_company',
            $update,
            ['user_id' => $user_id, 'company_id' => $company_id],
            $format,
            ['%d', '%d']
        );

        if (false !== $result) {
            PE_Permissions::flush_user_cache($user_id);
        }

        return false !== $result;
    }
}

<?php
/**
 * Template : Tableau de bord B2B — Mon Compte.
 */

defined('ABSPATH') || exit;

$user_id      = get_current_user_id();
$company      = PE_Permissions::get_user_company($user_id);
$role         = PE_Permissions::get_user_b2b_role($user_id);
$budget_mgr   = PE_Budget_Manager::get_instance();
$approval_mgr = PE_Approval_Manager::get_instance();
$company_mgr  = PE_Company_Manager::get_instance();

if (!$company) {
    echo '<p>' . esc_html__('Aucune entreprise associée à votre compte.', 'portail-entreprises') . '</p>';
    return;
}

$budget_summary  = $budget_mgr->get_usage_summary($user_id);
$company_budget  = null;
if (in_array($role, ['company_admin', 'purchase_manager'], true)) {
    $company_budget = $budget_mgr->get_company_usage_summary((int) $company->id);
}
$pending_count   = 0;
$user_count      = 0;
$recent_orders   = [];

if (in_array($role, ['company_admin', 'purchase_manager'], true)) {
    // Compteurs via requêtes COUNT (sans charger les enregistrements complets).
    $pending_count = $approval_mgr->get_pending_count_for_user($user_id);
    $user_count    = $company_mgr->count_company_users((int) $company->id);
}

$all_statuses  = ['wc-pending-approval', 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending', 'wc-cancelled'];
$status_labels = wc_get_order_statuses();
$is_manager    = in_array($role, ['company_admin', 'purchase_manager'], true);

// Filtres de recherche : dates appliquées en base (rapide), numéro filtré en PHP.
$order_search = isset($_GET['order_search']) ? sanitize_text_field(wp_unslash($_GET['order_search'])) : '';
$date_from    = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
$date_to      = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';
$from_ts      = $date_from ? strtotime($date_from . ' 00:00:00') : 0;
$to_ts        = $date_to ? strtotime($date_to . ' 23:59:59') : 0;
$search_num   = ltrim($order_search, '#');
$has_filters  = ('' !== $order_search) || ('' !== $date_from) || ('' !== $date_to);

$date_created = '';
if ($from_ts && $to_ts) {
    $date_created = $from_ts . '...' . $to_ts;
} elseif ($from_ts) {
    $date_created = '>=' . $from_ts;
} elseif ($to_ts) {
    $date_created = '<=' . $to_ts;
}

// Membres dont l'utilisateur peut voir les commandes (visibilité).
global $wpdb;
$company_user_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
    "SELECT user_id FROM {$wpdb->prefix}b2b_user_company WHERE company_id = %d",
    (int) $company->id
)));
$visible_ids = PE_Order_Visibility::get_instance()->get_visible_user_ids($user_id);
$other_ids   = array_values(array_filter(
    $company_user_ids,
    fn($id) => $id !== $user_id && in_array($id, $visible_ids, true)
));
$can_see_members = !empty($other_ids);

// Onglet actif + base d'URL.
$active_tab   = (isset($_GET['orders_tab']) && 'membres' === $_GET['orders_tab'] && $can_see_members) ? 'membres' : 'mes-commandes';
$tab_base_url = remove_query_arg(['orders_tab', 'member_uid', 'member_status', 'order_search', 'date_from', 'date_to']);

// Filtres « membres » + liste pour le sélecteur (uniquement si pertinent).
$member_filter_user   = 0;
$member_filter_status = '';
$company_members      = [];
if ($can_see_members) {
    $member_filter_user   = isset($_GET['member_uid']) ? absint($_GET['member_uid']) : 0;
    $member_filter_status = isset($_GET['member_status']) ? sanitize_key($_GET['member_status']) : '';
    foreach ($other_ids as $mid) {
        $mu = get_userdata($mid);
        if ($mu) { $company_members[$mid] = $mu->display_name; }
    }
}

// Compteurs d'onglets : COUNT optimisé via pagination (aucune hydratation d'objet).
$pe_count_orders = static function ($customer, array $status): int {
    $res = wc_get_orders([
        'customer_id' => $customer,
        'status'      => $status,
        'limit'       => 1,
        'paginate'    => true,
        'return'      => 'ids',
    ]);
    return (int) $res->total;
};
$my_count     = $pe_count_orders($user_id, $all_statuses);
$member_count = $can_see_members ? $pe_count_orders($other_ids, $all_statuses) : 0;

// Hydratation des commandes du SEUL onglet actif, avec filtre de date appliqué en base.
$orders_query = [
    'limit'   => -1,
    'orderby' => 'date',
    'order'   => 'DESC',
];
if ('' !== $date_created) {
    $orders_query['date_created'] = $date_created;
}

if ('membres' === $active_tab) {
    $query_ids = ($member_filter_user && in_array($member_filter_user, $other_ids, true)) ? [$member_filter_user] : $other_ids;
    $orders_query['customer_id'] = $query_ids;
    $orders_query['status']      = $member_filter_status ? [$member_filter_status] : $all_statuses;
    $show_member_col = true;
} else {
    $orders_query['customer_id'] = $user_id;
    $orders_query['status']      = $all_statuses;
    $show_member_col = false;
}
$display_orders = wc_get_orders($orders_query);

// Filtre numéro de commande (recherche partielle) sur l'onglet actif déjà restreint par date.
if ('' !== $search_num) {
    $display_orders = array_filter(
        $display_orders,
        static fn($o) => stripos((string) $o->get_order_number(), $search_num) !== false
    );
}

$active_total = ('membres' === $active_tab) ? $member_count : $my_count;
?>

<div class="pe-dashboard">
    <h2 class="pe-section-title">
        <?php esc_html_e('Tableau de bord B2B', 'portail-entreprises'); ?>
    </h2>

    <!-- Cartes statistiques -->
    <div class="pe-dashboard-cards">
        <!-- Entreprise -->
        <div class="pe-card pe-card-company">
            <div class="pe-card-icon">🏢</div>
            <div class="pe-card-body">
                <h3 class="pe-card-title"><?php echo esc_html($company->name); ?></h3>
                <?php if ('active' === $company->status) : ?>
                    <span class="pe-badge pe-badge-active"><?php esc_html_e('Active', 'portail-entreprises'); ?></span>
                <?php else : ?>
                    <span class="pe-badge pe-badge-suspended"><?php esc_html_e('Suspendue', 'portail-entreprises'); ?></span>
                <?php endif; ?>
                <p class="pe-card-meta">
                    <?php echo esc_html(PE_Permissions::get_roles()[$role] ?? $role); ?>
                </p>
            </div>
        </div>

        <!-- Budget mensuel -->
        <?php if ($budget_summary['budget_monthly'] !== null) : ?>
        <div class="pe-card pe-card-budget">
            <div class="pe-card-icon">💰</div>
            <div class="pe-card-body">
                <h3 class="pe-card-title"><?php esc_html_e('Budget mensuel', 'portail-entreprises'); ?></h3>
                <div class="pe-progress-bar-container">
                    <div class="pe-progress-bar" style="width: <?php echo esc_attr($budget_summary['percent_month']); ?>%;"
                         data-percent="<?php echo esc_attr($budget_summary['percent_month']); ?>"></div>
                </div>
                <p class="pe-card-meta">
                    <?php
                    printf(
                        /* translators: 1: spent, 2: total */
                        esc_html__('%1$s / %2$s', 'portail-entreprises'),
                        wc_price($budget_summary['spent_month']),
                        wc_price($budget_summary['budget_monthly'])
                    );
                    ?>
                </p>
                <p class="pe-card-sub">
                    <?php
                    printf(
                        /* translators: %s: remaining amount */
                        esc_html__('Restant : %s', 'portail-entreprises'),
                        wc_price($budget_summary['remaining_month'])
                    );
                    ?>
                </p>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($company_budget !== null) : ?>
        <div class="pe-card pe-card-budget">
            <div class="pe-card-icon">🏭</div>
            <div class="pe-card-body">
                <h3 class="pe-card-title"><?php esc_html_e('Budget entreprise', 'portail-entreprises'); ?></h3>
                <?php if ($company_budget['budget_monthly'] === null && $company_budget['budget_annual'] === null) : ?>
                <p class="pe-card-sub" style="font-size:0.85em;color:#999;">
                    <a href="<?php echo esc_url(wc_get_account_endpoint_url('b2b-company')); ?>"><?php esc_html_e('Configurer →', 'portail-entreprises'); ?></a>
                </p>
                <?php endif; ?>
                <?php if ($company_budget['budget_monthly'] !== null) : ?>
                <p class="pe-card-sub" style="margin:0 0 4px;font-size:0.8em;color:#666;"><?php esc_html_e('Mensuel', 'portail-entreprises'); ?></p>
                <div class="pe-progress-bar-container">
                    <div class="pe-progress-bar" style="width:<?php echo esc_attr((string)($company_budget['percent_month'] ?? 0)); ?>%;"></div>
                </div>
                <p class="pe-card-meta">
                    <?php echo wp_kses_post(wc_price($company_budget['spent_month'])); ?> / <?php echo wp_kses_post(wc_price($company_budget['budget_monthly'])); ?>
                </p>
                <p class="pe-card-sub"><?php printf(esc_html__('Restant : %s', 'portail-entreprises'), wp_kses_post(wc_price($company_budget['remaining_month']))); ?></p>
                <?php endif; ?>
                <?php if ($company_budget['budget_annual'] !== null) : ?>
                <p class="pe-card-sub" style="margin:8px 0 4px;font-size:0.8em;color:#666;"><?php esc_html_e('Annuel', 'portail-entreprises'); ?></p>
                <div class="pe-progress-bar-container">
                    <div class="pe-progress-bar" style="width:<?php echo esc_attr((string)($company_budget['percent_year'] ?? 0)); ?>%;"></div>
                </div>
                <p class="pe-card-meta">
                    <?php echo wp_kses_post(wc_price($company_budget['spent_year'])); ?> / <?php echo wp_kses_post(wc_price($company_budget['budget_annual'])); ?>
                </p>
                <p class="pe-card-sub"><?php printf(esc_html__('Restant : %s', 'portail-entreprises'), wp_kses_post(wc_price($company_budget['remaining_year']))); ?></p>
                <?php endif; ?>
                <?php if (!$company_budget['block_enabled']) : ?>
                <p class="pe-card-sub" style="font-size:0.75em;color:#888;"><?php esc_html_e('(indicatif — blocage désactivé)', 'portail-entreprises'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Approbations en attente (pour les managers) -->
        <?php if (in_array($role, ['company_admin', 'purchase_manager'], true)) : ?>
        <div class="pe-card pe-card-approvals <?php echo $pending_count > 0 ? 'pe-card-alert' : ''; ?>">
            <div class="pe-card-icon">📋</div>
            <div class="pe-card-body">
                <h3 class="pe-card-title"><?php esc_html_e('Approbations en attente', 'portail-entreprises'); ?></h3>
                <p class="pe-stat-number"><?php echo esc_html($pending_count); ?></p>
                <?php if ($pending_count > 0) : ?>
                <a href="<?php echo esc_url(wc_get_account_endpoint_url('b2b-approvals')); ?>" class="pe-card-link">
                    <?php esc_html_e('Voir les demandes →', 'portail-entreprises'); ?>
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Utilisateurs actifs -->
        <div class="pe-card pe-card-users">
            <div class="pe-card-icon">👥</div>
            <div class="pe-card-body">
                <h3 class="pe-card-title"><?php esc_html_e('Utilisateurs actifs', 'portail-entreprises'); ?></h3>
                <p class="pe-stat-number"><?php echo esc_html($user_count); ?></p>
                <a href="<?php echo esc_url(wc_get_account_endpoint_url('b2b-users')); ?>" class="pe-card-link">
                    <?php esc_html_e('Gérer les utilisateurs →', 'portail-entreprises'); ?>
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Commandes récentes -->
    <div class="pe-section" id="pe-commandes" style="scroll-margin-top:140px;">
        <h3 class="pe-section-subtitle"><?php esc_html_e('Commandes', 'portail-entreprises'); ?></h3>

        <?php if ($can_see_members) : ?>
        <!-- Onglets -->
        <div class="pe-tabs" style="display:flex;gap:0;margin-bottom:0;border-bottom:2px solid #e5e7eb;">
            <a href="<?php echo esc_url(add_query_arg('orders_tab', 'mes-commandes', $tab_base_url) . '#pe-commandes'); ?>"
               class="pe-tab <?php echo 'mes-commandes' === $active_tab ? 'pe-tab-active' : ''; ?>"
               style="padding:10px 20px;font-weight:600;text-decoration:none;border-bottom:2px solid <?php echo 'mes-commandes' === $active_tab ? '#1e3a5f' : 'transparent'; ?>;margin-bottom:-2px;color:<?php echo 'mes-commandes' === $active_tab ? '#1e3a5f' : '#6b7280'; ?>;">
                <?php esc_html_e('Mes commandes', 'portail-entreprises'); ?>
                <span style="background:#e5e7eb;border-radius:999px;padding:1px 8px;font-size:0.75em;margin-left:6px;"><?php echo (int) $my_count; ?></span>
            </a>
            <a href="<?php echo esc_url(add_query_arg('orders_tab', 'membres', $tab_base_url) . '#pe-commandes'); ?>"
               class="pe-tab <?php echo 'membres' === $active_tab ? 'pe-tab-active' : ''; ?>"
               style="padding:10px 20px;font-weight:600;text-decoration:none;border-bottom:2px solid <?php echo 'membres' === $active_tab ? '#1e3a5f' : 'transparent'; ?>;margin-bottom:-2px;color:<?php echo 'membres' === $active_tab ? '#1e3a5f' : '#6b7280'; ?>;">
                <?php esc_html_e('Commandes des membres', 'portail-entreprises'); ?>
                <span style="background:#e5e7eb;border-radius:999px;padding:1px 8px;font-size:0.75em;margin-left:6px;"><?php echo (int) $member_count; ?></span>
            </a>
        </div>
        <?php endif; ?>

        <?php if ('membres' === $active_tab && $can_see_members) : ?>
        <!-- Filtres membres -->
        <form method="get" action="#pe-commandes" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;padding:14px 0 12px;">
            <input type="hidden" name="orders_tab" value="membres" />
            <select name="member_uid" class="pe-input" style="width:auto;min-width:160px;" onchange="this.form.submit()">
                <option value="0"><?php esc_html_e('Tous les membres', 'portail-entreprises'); ?></option>
                <?php foreach ($company_members as $mid => $mname) : ?>
                <option value="<?php echo esc_attr($mid); ?>" <?php selected($member_filter_user, $mid); ?>>
                    <?php echo esc_html($mname); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="member_status" class="pe-input" style="width:auto;min-width:160px;" onchange="this.form.submit()">
                <option value=""><?php esc_html_e('Tous les statuts', 'portail-entreprises'); ?></option>
                <?php foreach ($status_labels as $slug => $label) : ?>
                <option value="<?php echo esc_attr($slug); ?>" <?php selected($member_filter_status, $slug); ?>>
                    <?php echo esc_html($label); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($member_filter_user || $member_filter_status) : ?>
            <a href="<?php echo esc_url(add_query_arg('orders_tab', 'membres', $tab_base_url) . '#pe-commandes'); ?>" style="font-size:0.85em;color:#6b7280;">
                <?php esc_html_e('Réinitialiser', 'portail-entreprises'); ?>
            </a>
            <?php endif; ?>
        </form>

        <?php endif; // filtres membres ?>

        <?php if (0 === $active_total && !$has_filters) : ?>
            <p style="padding:20px 0;color:#6b7280;"><?php esc_html_e('Aucune commande trouvée.', 'portail-entreprises'); ?></p>
        <?php else : ?>
        <?php
        // Base d'URL conservant l'onglet courant et les filtres membres (pour « Réinitialiser »).
        $base_args = ['orders_tab' => $active_tab];
        if ('membres' === $active_tab) {
            if ($member_filter_user)   { $base_args['member_uid'] = $member_filter_user; }
            if ($member_filter_status) { $base_args['member_status'] = $member_filter_status; }
        }
        ?>

        <form method="get" action="#pe-commandes" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;padding:8px 0 14px;">
            <input type="hidden" name="orders_tab" value="<?php echo esc_attr($active_tab); ?>" />
            <?php if ('membres' === $active_tab && $member_filter_user) : ?>
            <input type="hidden" name="member_uid" value="<?php echo esc_attr($member_filter_user); ?>" />
            <?php endif; ?>
            <?php if ('membres' === $active_tab && $member_filter_status) : ?>
            <input type="hidden" name="member_status" value="<?php echo esc_attr($member_filter_status); ?>" />
            <?php endif; ?>
            <label style="font-size:.85em;color:#374151;">
                <?php esc_html_e('N° de commande', 'portail-entreprises'); ?><br>
                <input type="text" name="order_search" value="<?php echo esc_attr($order_search); ?>" class="pe-input"
                       placeholder="<?php esc_attr_e('ex. 98815', 'portail-entreprises'); ?>" style="width:auto;min-width:140px;" />
            </label>
            <label style="font-size:.85em;color:#374151;">
                <?php esc_html_e('Du', 'portail-entreprises'); ?><br>
                <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" class="pe-input" style="width:auto;" />
            </label>
            <label style="font-size:.85em;color:#374151;">
                <?php esc_html_e('Au', 'portail-entreprises'); ?><br>
                <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" class="pe-input" style="width:auto;" />
            </label>
            <button type="submit" class="pe-btn pe-btn-sm pe-btn-primary"><?php esc_html_e('Rechercher', 'portail-entreprises'); ?></button>
            <?php if ($has_filters) : ?>
            <a href="<?php echo esc_url(add_query_arg($base_args, $tab_base_url) . '#pe-commandes'); ?>" style="font-size:.85em;color:#6b7280;align-self:center;">
                <?php esc_html_e('Réinitialiser', 'portail-entreprises'); ?>
            </a>
            <?php endif; ?>
        </form>

        <?php if (empty($display_orders)) : ?>
            <p style="padding:20px 0;color:#6b7280;"><?php esc_html_e('Aucune commande ne correspond à votre recherche.', 'portail-entreprises'); ?></p>
        <?php else : ?>
        <div class="pe-table-responsive">
            <table class="pe-table pe-orders-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('N° commande', 'portail-entreprises'); ?></th>
                        <?php if ($show_member_col) : ?>
                        <th><?php esc_html_e('Membre', 'portail-entreprises'); ?></th>
                        <?php endif; ?>
                        <th><?php esc_html_e('Date', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Statut', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Total', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Actions', 'portail-entreprises'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($display_orders as $order) : ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url($order->get_view_order_url()); ?>">
                                #<?php echo esc_html($order->get_order_number()); ?>
                            </a>
                        </td>
                        <?php if ($show_member_col) : ?>
                        <td>
                            <?php
                            $order_customer = get_userdata((int) $order->get_customer_id());
                            echo esc_html($order_customer ? $order_customer->display_name : '—');
                            ?>
                        </td>
                        <?php endif; ?>
                        <td><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></td>
                        <td>
                            <?php
                            $status_slug  = 'wc-' . $order->get_status();
                            $status_label = $status_labels[$status_slug] ?? ucfirst($order->get_status());
                            $badge_class  = 'pending-approval' === $order->get_status() ? 'pe-badge-pending' : 'pe-badge-default';
                            ?>
                            <span class="pe-badge <?php echo esc_attr($badge_class); ?>">
                                <?php echo esc_html($status_label); ?>
                            </span>
                        </td>
                        <td><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
                        <td>
                            <a href="<?php echo esc_url($order->get_view_order_url()); ?>" class="pe-btn pe-btn-sm">
                                <?php esc_html_e('Voir', 'portail-entreprises'); ?>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; // résultats filtrés ?>
        <?php endif; // commandes de l'onglet ?>
    </div>
</div>

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
    $pending_requests = $approval_mgr->get_company_requests((int) $company->id, 'pending');
    $pending_count    = count($pending_requests);
    $company_users    = $company_mgr->get_company_users((int) $company->id);
    $user_count       = count($company_users);
}

// Orders: for admins/managers show all company orders; for requesters show own only
if (in_array($role, ['company_admin', 'purchase_manager'], true)) {
    global $wpdb;
    $company_user_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->prefix}b2b_user_company WHERE company_id = %d",
        (int) $company->id
    ));
    if (empty($company_user_ids)) {
        $company_user_ids = [$user_id];
    }
    $recent_orders = wc_get_orders([
        'customer_id' => array_map('intval', $company_user_ids),
        'limit'       => 10,
        'orderby'     => 'date',
        'order'       => 'DESC',
        'status'      => ['wc-pending-approval', 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending', 'wc-cancelled'],
    ]);
} else {
    $recent_orders = wc_get_orders([
        'customer_id' => $user_id,
        'limit'       => 10,
        'orderby'     => 'date',
        'order'       => 'DESC',
        'status'      => ['wc-pending-approval', 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending', 'wc-cancelled'],
    ]);
}

$status_labels = wc_get_order_statuses();
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
    <div class="pe-section">
        <h3 class="pe-section-subtitle"><?php esc_html_e('Mes dernières commandes', 'portail-entreprises'); ?></h3>

        <?php if (empty($recent_orders)) : ?>
            <p><?php esc_html_e('Aucune commande trouvée.', 'portail-entreprises'); ?></p>
        <?php else : ?>
        <div class="pe-table-responsive">
            <table class="pe-table pe-orders-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('N° commande', 'portail-entreprises'); ?></th>
                        <?php if (in_array($role, ['company_admin', 'purchase_manager'], true)) : ?>
                        <th><?php esc_html_e('Demandeur', 'portail-entreprises'); ?></th>
                        <?php endif; ?>
                        <th><?php esc_html_e('Date', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Statut', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Total', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Actions', 'portail-entreprises'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_orders as $order) : ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url($order->get_view_order_url()); ?>">
                                #<?php echo esc_html($order->get_order_number()); ?>
                            </a>
                        </td>
                        <?php if (in_array($role, ['company_admin', 'purchase_manager'], true)) : ?>
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
        <?php endif; ?>
    </div>
</div>

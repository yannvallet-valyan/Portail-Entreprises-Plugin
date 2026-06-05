<?php
/**
 * Template : Approbations — Mon Compte.
 */

defined('ABSPATH') || exit;

$user_id      = get_current_user_id();
$company      = PE_Permissions::get_user_company($user_id);
$role         = PE_Permissions::get_user_b2b_role($user_id);
$can_approve  = PE_Permissions::user_can($user_id, 'approve_orders');
$approval_mgr = PE_Approval_Manager::get_instance();

if (!$company) {
    echo '<p>' . esc_html__('Aucune entreprise associée à votre compte.', 'portail-entreprises') . '</p>';
    return;
}

// Filtre par statut
$filter_status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
if (!in_array($filter_status, ['', 'pending', 'approved', 'rejected'], true)) {
    $filter_status = '';
}

if ($can_approve) {
    // Les approvers voient toutes les demandes de l'entreprise
    $requests = $approval_mgr->get_company_requests((int) $company->id, $filter_status);
} else {
    // Les autres voient uniquement leurs propres demandes
    global $wpdb;
    $requests = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ar.* FROM {$wpdb->prefix}b2b_approval_requests ar
             WHERE ar.requester_id = %d
             ORDER BY ar.created_at DESC",
            $user_id
        )
    ) ?: [];
}

$status_labels = [
    'pending'  => ['label' => __('En attente', 'portail-entreprises'), 'badge' => 'pe-badge-pending'],
    'approved' => ['label' => __('Approuvée', 'portail-entreprises'), 'badge' => 'pe-badge-active'],
    'rejected' => ['label' => __('Rejetée', 'portail-entreprises'), 'badge' => 'pe-badge-suspended'],
];
?>

<div class="pe-approvals-page">
    <h2 class="pe-section-title"><?php esc_html_e('Demandes d\'approbation', 'portail-entreprises'); ?></h2>

    <!-- Filtres -->
    <div class="pe-filters">
        <a href="<?php echo esc_url(remove_query_arg('status')); ?>"
           class="pe-filter-btn <?php echo $filter_status === '' ? 'active' : ''; ?>">
            <?php esc_html_e('Toutes', 'portail-entreprises'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('status', 'pending')); ?>"
           class="pe-filter-btn <?php echo $filter_status === 'pending' ? 'active' : ''; ?>">
            <?php esc_html_e('En attente', 'portail-entreprises'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('status', 'approved')); ?>"
           class="pe-filter-btn <?php echo $filter_status === 'approved' ? 'active' : ''; ?>">
            <?php esc_html_e('Approuvées', 'portail-entreprises'); ?>
        </a>
        <a href="<?php echo esc_url(add_query_arg('status', 'rejected')); ?>"
           class="pe-filter-btn <?php echo $filter_status === 'rejected' ? 'active' : ''; ?>">
            <?php esc_html_e('Rejetées', 'portail-entreprises'); ?>
        </a>
    </div>

    <div id="pe-approvals-feedback" class="pe-ajax-message" style="display:none;"></div>

    <?php if (empty($requests)) : ?>
        <div class="pe-empty-state">
            <p><?php esc_html_e('Aucune demande d\'approbation trouvée.', 'portail-entreprises'); ?></p>
        </div>
    <?php else : ?>
    <div class="pe-card">
        <div class="pe-table-responsive">
            <table class="pe-table pe-approvals-table" id="pe-approvals-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('N° commande', 'portail-entreprises'); ?></th>
                        <?php if ($can_approve) : ?>
                        <th><?php esc_html_e('Demandeur', 'portail-entreprises'); ?></th>
                        <?php endif; ?>
                        <th><?php esc_html_e('Montant', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Centre de coût', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Date', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Statut', 'portail-entreprises'); ?></th>
                        <?php if ($can_approve) : ?>
                        <th><?php esc_html_e('Actions', 'portail-entreprises'); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $req) : ?>
                    <?php
                    $status_info = $status_labels[$req->status] ?? ['label' => esc_html($req->status), 'badge' => 'pe-badge-default'];
                    $order       = wc_get_order((int) $req->order_id);
                    $cost_center = null;
                    if ($req->cost_center_id) {
                        global $wpdb;
                        $cost_center = $wpdb->get_row(
                            $wpdb->prepare(
                                "SELECT name, code FROM {$wpdb->prefix}b2b_cost_centers WHERE id = %d LIMIT 1",
                                (int) $req->cost_center_id
                            )
                        );
                    }
                    ?>
                    <tr class="pe-approval-row" data-request-id="<?php echo esc_attr($req->id); ?>">
                        <td>
                            <?php if ($order) : ?>
                            <a href="<?php echo esc_url($order->get_view_order_url()); ?>">
                                #<?php echo esc_html($order->get_order_number()); ?>
                            </a>
                            <?php else : ?>
                            #<?php echo esc_html($req->order_id); ?>
                            <?php endif; ?>
                        </td>
                        <?php if ($can_approve) : ?>
                        <td>
                            <?php echo esc_html($req->requester_name ?? $req->requester_id); ?>
                            <?php if (!empty($req->requester_email)) : ?>
                            <small class="pe-text-muted"><?php echo esc_html($req->requester_email); ?></small>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td><strong><?php echo wp_kses_post(wc_price($req->amount)); ?></strong></td>
                        <td>
                            <?php if ($cost_center) : ?>
                                <?php echo esc_html($cost_center->name); ?>
                                <?php if ($cost_center->code) : ?>
                                <small>(<?php echo esc_html($cost_center->code); ?>)</small>
                                <?php endif; ?>
                            <?php else : ?>
                                <em>—</em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($req->created_at))); ?>
                        </td>
                        <td>
                            <span class="pe-badge <?php echo esc_attr($status_info['badge']); ?>">
                                <?php echo esc_html($status_info['label']); ?>
                            </span>
                            <?php if ('rejected' === $req->status && $req->notes) : ?>
                            <div class="pe-rejection-note">
                                <small><?php echo esc_html($req->notes); ?></small>
                            </div>
                            <?php endif; ?>
                        </td>
                        <?php if ($can_approve) : ?>
                        <td class="pe-actions-cell">
                            <?php if ('pending' === $req->status) : ?>
                            <button type="button"
                                    class="pe-btn pe-btn-sm pe-btn-success pe-approve-btn"
                                    data-request-id="<?php echo esc_attr($req->id); ?>"
                                    data-nonce="<?php echo esc_attr(wp_create_nonce('pe_b2b_ajax')); ?>">
                                <?php esc_html_e('Approuver', 'portail-entreprises'); ?>
                            </button>
                            <button type="button"
                                    class="pe-btn pe-btn-sm pe-btn-danger pe-reject-btn"
                                    data-request-id="<?php echo esc_attr($req->id); ?>"
                                    data-nonce="<?php echo esc_attr(wp_create_nonce('pe_b2b_ajax')); ?>">
                                <?php esc_html_e('Rejeter', 'portail-entreprises'); ?>
                            </button>
                            <?php else : ?>
                            <span class="pe-text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal de rejet -->
    <?php if ($can_approve) : ?>
    <div id="pe-reject-modal" class="pe-modal" style="display:none;" aria-modal="true" role="dialog">
        <div class="pe-modal-overlay"></div>
        <div class="pe-modal-content">
            <h3><?php esc_html_e('Motif de refus', 'portail-entreprises'); ?></h3>
            <textarea id="pe-reject-reason" class="pe-textarea" rows="4"
                      placeholder="<?php esc_attr_e('Indiquez le motif du refus (optionnel)…', 'portail-entreprises'); ?>"></textarea>
            <div class="pe-modal-actions">
                <button type="button" class="pe-btn pe-btn-danger" id="pe-confirm-reject">
                    <?php esc_html_e('Confirmer le refus', 'portail-entreprises'); ?>
                </button>
                <button type="button" class="pe-btn pe-btn-secondary pe-modal-close">
                    <?php esc_html_e('Annuler', 'portail-entreprises'); ?>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

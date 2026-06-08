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

// Centres de coût disponibles (pour édition inline par les managers).
global $wpdb;
$company_cost_centers = [];
if ($can_approve) {
    $company_cost_centers = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, name, code FROM {$wpdb->prefix}b2b_cost_centers WHERE company_id = %d ORDER BY name ASC",
            (int) $company->id
        )
    ) ?: [];
}

// Filtre par statut.
$filter_status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
if (!in_array($filter_status, ['', 'pending', 'approved', 'rejected'], true)) {
    $filter_status = '';
}

if ($can_approve) {
    $requests = $approval_mgr->get_company_requests((int) $company->id, $filter_status);
} else {
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

// Commandes de tous les membres (managers uniquement).
$company_orders = [];
if ($can_approve) {
    $company_orders = PE_Core::get_company_orders((int) $company->id, 100);
}

$wc_status_labels = wc_get_order_statuses();
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
                        <th><?php esc_html_e('Référence', 'portail-entreprises'); ?></th>
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
                    $status_info  = $status_labels[$req->status] ?? ['label' => esc_html($req->status), 'badge' => 'pe-badge-default'];
                    $order        = wc_get_order((int) $req->order_id);
                    $cost_center_label = $order ? $order->get_meta('_b2b_cost_center_label') : '';
                    $reference         = $order ? $order->get_meta('_b2b_personal_reference') : '';
                    $current_cc_id     = $order ? (int) $order->get_meta('_b2b_cost_center_id') : 0;
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
                            <br><small class="pe-text-muted"><?php echo esc_html($req->requester_email); ?></small>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td><strong><?php echo wp_kses_post(wc_price($req->amount)); ?></strong></td>
                        <td class="pe-cc-cell" data-order-id="<?php echo esc_attr($req->order_id); ?>">
                            <?php if ($can_approve) : ?>
                            <span class="pe-cc-display">
                                <?php echo $cost_center_label ? esc_html($cost_center_label) : '<em>—</em>'; ?>
                            </span>
                            <?php if ($order) : ?>
                            <button type="button" class="pe-btn-link pe-edit-meta-btn"
                                    data-order-id="<?php echo esc_attr($req->order_id); ?>"
                                    data-cc-id="<?php echo esc_attr($current_cc_id); ?>"
                                    data-reference="<?php echo esc_attr($reference); ?>"
                                    title="<?php esc_attr_e('Modifier', 'portail-entreprises'); ?>">✏️</button>
                            <?php endif; ?>
                            <?php else : ?>
                                <?php echo $cost_center_label ? esc_html($cost_center_label) : '<em>—</em>'; ?>
                            <?php endif; ?>
                        </td>
                        <td class="pe-ref-cell" data-order-id="<?php echo esc_attr($req->order_id); ?>">
                            <span class="pe-ref-display">
                                <?php echo $reference ? esc_html($reference) : '<em>—</em>'; ?>
                            </span>
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
                            <?php endif; ?>
                            <?php if ($order) : ?>
                            <a href="<?php echo esc_url(add_query_arg('b2b_manager_view', '1', $order->get_view_order_url())); ?>"
                               class="pe-btn pe-btn-sm pe-btn-secondary" style="margin-top:4px;">
                                <?php esc_html_e('Voir', 'portail-entreprises'); ?>
                            </a>
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

    <!-- Modale d'édition centre de coût + référence (managers) -->
    <?php if ($can_approve) : ?>
    <div id="pe-edit-meta-modal" class="pe-modal" style="display:none;" aria-modal="true" role="dialog">
        <div class="pe-modal-overlay"></div>
        <div class="pe-modal-content">
            <h3><?php esc_html_e('Modifier les informations B2B', 'portail-entreprises'); ?></h3>
            <?php if (!empty($company_cost_centers)) : ?>
            <div class="pe-form-row" style="margin-bottom:12px;">
                <label for="pe-edit-cc"><?php esc_html_e('Centre de coût', 'portail-entreprises'); ?></label>
                <select id="pe-edit-cc" class="pe-select" style="width:100%;margin-top:4px;">
                    <option value="0"><?php esc_html_e('— Aucun —', 'portail-entreprises'); ?></option>
                    <?php foreach ($company_cost_centers as $cc) : ?>
                    <option value="<?php echo esc_attr((int) $cc->id); ?>">
                        <?php echo esc_html($cc->name . ($cc->code ? ' (' . $cc->code . ')' : '')); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="pe-form-row" style="margin-bottom:12px;">
                <label for="pe-edit-ref"><?php esc_html_e('Référence', 'portail-entreprises'); ?></label>
                <input type="text" id="pe-edit-ref" class="pe-input" style="width:100%;margin-top:4px;"
                       placeholder="<?php esc_attr_e('Bon de commande, référence interne…', 'portail-entreprises'); ?>" />
            </div>
            <div class="pe-modal-actions">
                <button type="button" class="pe-btn pe-btn-primary" id="pe-save-meta"
                        data-nonce="<?php echo esc_attr(wp_create_nonce('pe_b2b_ajax')); ?>">
                    <?php esc_html_e('Enregistrer', 'portail-entreprises'); ?>
                </button>
                <button type="button" class="pe-btn pe-btn-secondary pe-modal-close">
                    <?php esc_html_e('Annuler', 'portail-entreprises'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Modal de rejet -->
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

    <!-- Section : Commandes de l'entreprise -->
    <?php if (!empty($company_orders)) : ?>
    <div style="margin-top:40px;">
        <h2 class="pe-section-title"><?php esc_html_e('Commandes de l\'entreprise', 'portail-entreprises'); ?></h2>
        <div class="pe-card">
            <div class="pe-table-responsive">
                <table class="pe-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('N° commande', 'portail-entreprises'); ?></th>
                            <th><?php esc_html_e('Membre', 'portail-entreprises'); ?></th>
                            <th><?php esc_html_e('Date', 'portail-entreprises'); ?></th>
                            <th><?php esc_html_e('Statut', 'portail-entreprises'); ?></th>
                            <th><?php esc_html_e('Total', 'portail-entreprises'); ?></th>
                            <th><?php esc_html_e('Centre de coût', 'portail-entreprises'); ?></th>
                            <th><?php esc_html_e('Référence', 'portail-entreprises'); ?></th>
                            <th><?php esc_html_e('Actions', 'portail-entreprises'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($company_orders as $co) : ?>
                        <?php
                        $co_customer_id = (int) $co->get_customer_id();
                        $co_customer    = get_userdata($co_customer_id);
                        $co_cc          = $co->get_meta('_b2b_cost_center_label');
                        $co_ref         = $co->get_meta('_b2b_personal_reference');
                        $co_status      = 'wc-' . $co->get_status();
                        $co_status_lbl  = $wc_status_labels[$co_status] ?? ucfirst($co->get_status());
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url($co->get_view_order_url()); ?>">
                                    #<?php echo esc_html($co->get_order_number()); ?>
                                </a>
                            </td>
                            <td>
                                <?php if ($co_customer) : ?>
                                    <?php echo esc_html($co_customer->display_name); ?>
                                    <br><small class="pe-text-muted"><?php echo esc_html($co_customer->user_email); ?></small>
                                <?php else : ?>
                                    <em><?php esc_html_e('Inconnu', 'portail-entreprises'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(wc_format_datetime($co->get_date_created())); ?></td>
                            <td>
                                <span class="pe-order-status pe-order-status-<?php echo esc_attr($co->get_status()); ?>">
                                    <?php echo esc_html($co_status_lbl); ?>
                                </span>
                            </td>
                            <td><?php echo wp_kses_post(wc_price($co->get_total())); ?></td>
                            <td class="pe-cc-cell" data-order-id="<?php echo esc_attr($co->get_id()); ?>">
                                <span class="pe-cc-display">
                                    <?php echo $co_cc ? esc_html($co_cc) : '<em>—</em>'; ?>
                                </span>
                                <button type="button" class="pe-btn-link pe-edit-meta-btn"
                                        data-order-id="<?php echo esc_attr($co->get_id()); ?>"
                                        data-cc-id="<?php echo esc_attr((int) $co->get_meta('_b2b_cost_center_id')); ?>"
                                        data-reference="<?php echo esc_attr($co_ref); ?>"
                                        title="<?php esc_attr_e('Modifier', 'portail-entreprises'); ?>">✏️</button>
                            </td>
                            <td class="pe-ref-cell" data-order-id="<?php echo esc_attr($co->get_id()); ?>">
                                <span class="pe-ref-display">
                                    <?php echo $co_ref ? esc_html($co_ref) : '<em>—</em>'; ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?php echo esc_url($co->get_view_order_url()); ?>"
                                   class="pe-btn pe-btn-sm pe-btn-secondary">
                                    <?php esc_html_e('Voir', 'portail-entreprises'); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

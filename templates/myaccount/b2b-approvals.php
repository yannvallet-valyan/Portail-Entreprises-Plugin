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

// ----- Section « Commandes de l'entreprise » (managers uniquement) -----
// Optimisé comme le tableau de bord : filtre date poussé en base, filtre
// statut/membre dans la requête wc_get_orders, filtre n° en PHP sur
// l'ensemble déjà restreint. Respecte les règles de visibilité des commandes.
$wc_status_labels = wc_get_order_statuses();
$company_orders   = [];
$co_members       = [];
$co_visible_ids   = [];
$co_has_filters   = false;

// Lecture des filtres (préfixe « co_ » pour ne pas interférer avec le filtre
// des demandes d'approbation « status » en haut de page).
$co_search = isset($_GET['co_search']) ? sanitize_text_field(wp_unslash($_GET['co_search'])) : '';
$co_member = isset($_GET['co_member']) ? absint($_GET['co_member']) : 0;
$co_status = isset($_GET['co_status']) ? sanitize_key($_GET['co_status']) : '';
$co_from   = isset($_GET['co_from']) ? sanitize_text_field(wp_unslash($_GET['co_from'])) : '';
$co_to     = isset($_GET['co_to']) ? sanitize_text_field(wp_unslash($_GET['co_to'])) : '';

if ($can_approve) {
    // Membres de l'entreprise dont l'utilisateur peut voir les commandes.
    $co_company_user_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT user_id FROM {$wpdb->prefix}b2b_user_company WHERE company_id = %d",
        (int) $company->id
    )));
    if (class_exists('PE_Order_Visibility')) {
        $co_visible     = PE_Order_Visibility::get_instance()->get_visible_user_ids($user_id);
        $co_visible_ids = array_values(array_intersect($co_company_user_ids, $co_visible));
    } else {
        $co_visible_ids = $co_company_user_ids;
    }
    foreach ($co_visible_ids as $mid) {
        $mu = get_userdata($mid);
        if ($mu) { $co_members[$mid] = $mu->display_name; }
    }
}

if ($can_approve && !empty($co_visible_ids)) {
    $co_search_num  = ltrim($co_search, '#');
    $co_has_filters = ('' !== $co_search) || $co_member || ('' !== $co_status) || ('' !== $co_from) || ('' !== $co_to);

    // Plage de dates poussée en base.
    $co_from_ts = $co_from ? strtotime($co_from . ' 00:00:00') : 0;
    $co_to_ts   = $co_to ? strtotime($co_to . ' 23:59:59') : 0;
    $co_date_created = '';
    if ($co_from_ts && $co_to_ts) {
        $co_date_created = $co_from_ts . '...' . $co_to_ts;
    } elseif ($co_from_ts) {
        $co_date_created = '>=' . $co_from_ts;
    } elseif ($co_to_ts) {
        $co_date_created = '<=' . $co_to_ts;
    }

    // Membre sélectionné restreint à la liste visible (sécurité).
    $co_customer = ($co_member && in_array($co_member, $co_visible_ids, true)) ? [$co_member] : $co_visible_ids;

    $co_query = [
        'customer' => $co_customer,
        'limit'    => 100,
        'orderby'  => 'date',
        'order'    => 'DESC',
        'type'     => 'shop_order',
    ];
    if ('' !== $co_status) {
        $co_query['status'] = [$co_status];
    }
    if ('' !== $co_date_created) {
        $co_query['date_created'] = $co_date_created;
    }
    $company_orders = wc_get_orders($co_query) ?: [];

    // Filtre n° de commande (recherche partielle, « # » ignoré) sur l'ensemble restreint.
    if ('' !== $co_search_num) {
        $company_orders = array_filter(
            $company_orders,
            static fn($o) => stripos((string) $o->get_order_number(), $co_search_num) !== false
        );
    }
}
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
                        <th><?php esc_html_e('Approuvé par', 'portail-entreprises'); ?></th>
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
                            <span class="pe-cc-display">
                                <?php echo $cost_center_label ? esc_html($cost_center_label) : '<em>—</em>'; ?>
                            </span>
                            <?php if ($can_approve && $order) : ?>
                            <button type="button" class="pe-btn-link pe-edit-meta-btn"
                                    data-order-id="<?php echo esc_attr($req->order_id); ?>"
                                    data-cc-label="<?php echo esc_attr($cost_center_label); ?>"
                                    data-reference="<?php echo esc_attr($reference); ?>"
                                    title="<?php esc_attr_e('Modifier', 'portail-entreprises'); ?>">✏️</button>
                            <?php endif; ?>
                        </td>
                        <td class="pe-ref-cell" data-order-id="<?php echo esc_attr($req->order_id); ?>">
                            <span class="pe-ref-display">
                                <?php echo $reference ? esc_html($reference) : '<em>—</em>'; ?>
                            </span>
                            <?php if ($can_approve && $order) : ?>
                            <button type="button" class="pe-btn-link pe-edit-meta-btn"
                                    data-order-id="<?php echo esc_attr($req->order_id); ?>"
                                    data-cc-label="<?php echo esc_attr($cost_center_label); ?>"
                                    data-reference="<?php echo esc_attr($reference); ?>"
                                    title="<?php esc_attr_e('Modifier', 'portail-entreprises'); ?>">✏️</button>
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
                        <td>
                            <?php
                            if (!empty($req->approver_id)) {
                                $approver = get_userdata((int) $req->approver_id);
                                if ($approver) {
                                    $full = trim($approver->first_name . ' ' . $approver->last_name);
                                    echo esc_html($full ?: $approver->display_name);
                                } else {
                                    echo '<em>—</em>';
                                }
                            } else {
                                echo '<em>—</em>';
                            }
                            ?>
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
                            <a href="<?php echo esc_url(wc_get_account_endpoint_url('b2b-order') . $order->get_id() . '/'); ?>"
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
    <?php PE_Core::render_b2b_meta_modal(); ?>

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
    <?php if (!empty($co_visible_ids)) : ?>
    <div id="pe-company-orders" style="margin-top:40px;scroll-margin-top:140px;">
        <h2 class="pe-section-title"><?php esc_html_e('Commandes de l\'entreprise', 'portail-entreprises'); ?></h2>

        <!-- Filtres de recherche -->
        <form method="get" action="#pe-company-orders" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;padding:8px 0 16px;">
            <?php if ('' !== $filter_status) : ?>
            <input type="hidden" name="status" value="<?php echo esc_attr($filter_status); ?>" />
            <?php endif; ?>
            <label style="font-size:.85em;color:#374151;">
                <?php esc_html_e('N° de commande', 'portail-entreprises'); ?><br>
                <input type="text" name="co_search" value="<?php echo esc_attr($co_search); ?>" class="pe-input"
                       placeholder="<?php esc_attr_e('ex. 98815', 'portail-entreprises'); ?>" style="width:auto;min-width:140px;" />
            </label>
            <label style="font-size:.85em;color:#374151;">
                <?php esc_html_e('Membre', 'portail-entreprises'); ?><br>
                <select name="co_member" class="pe-input" style="width:auto;min-width:160px;">
                    <option value="0"><?php esc_html_e('Tous les membres', 'portail-entreprises'); ?></option>
                    <?php foreach ($co_members as $mid => $mname) : ?>
                    <option value="<?php echo esc_attr($mid); ?>" <?php selected($co_member, $mid); ?>>
                        <?php echo esc_html($mname); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="font-size:.85em;color:#374151;">
                <?php esc_html_e('Statut', 'portail-entreprises'); ?><br>
                <select name="co_status" class="pe-input" style="width:auto;min-width:160px;">
                    <option value=""><?php esc_html_e('Tous les statuts', 'portail-entreprises'); ?></option>
                    <?php foreach ($wc_status_labels as $slug => $label) : ?>
                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($co_status, $slug); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="font-size:.85em;color:#374151;">
                <?php esc_html_e('Du', 'portail-entreprises'); ?><br>
                <input type="date" name="co_from" value="<?php echo esc_attr($co_from); ?>" class="pe-input" style="width:auto;" />
            </label>
            <label style="font-size:.85em;color:#374151;">
                <?php esc_html_e('Au', 'portail-entreprises'); ?><br>
                <input type="date" name="co_to" value="<?php echo esc_attr($co_to); ?>" class="pe-input" style="width:auto;" />
            </label>
            <button type="submit" class="pe-btn pe-btn-sm pe-btn-primary"><?php esc_html_e('Rechercher', 'portail-entreprises'); ?></button>
            <?php if ($co_has_filters) : ?>
            <a href="<?php echo esc_url(remove_query_arg(['co_search', 'co_member', 'co_status', 'co_from', 'co_to']) . '#pe-company-orders'); ?>"
               style="font-size:.85em;color:#6b7280;align-self:center;">
                <?php esc_html_e('Réinitialiser', 'portail-entreprises'); ?>
            </a>
            <?php endif; ?>
        </form>

        <?php if (empty($company_orders)) : ?>
            <p style="padding:20px 0;color:#6b7280;">
                <?php echo $co_has_filters
                    ? esc_html__('Aucune commande ne correspond à votre recherche.', 'portail-entreprises')
                    : esc_html__('Aucune commande pour le moment.', 'portail-entreprises'); ?>
            </p>
        <?php else : ?>
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
                        $co_row_status  = 'wc-' . $co->get_status();
                        $co_status_lbl  = $wc_status_labels[$co_row_status] ?? ucfirst($co->get_status());
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
                                        data-cc-label="<?php echo esc_attr($co_cc); ?>"
                                        data-reference="<?php echo esc_attr($co_ref); ?>"
                                        title="<?php esc_attr_e('Modifier', 'portail-entreprises'); ?>">✏️</button>
                            </td>
                            <td class="pe-ref-cell" data-order-id="<?php echo esc_attr($co->get_id()); ?>">
                                <span class="pe-ref-display">
                                    <?php echo $co_ref ? esc_html($co_ref) : '<em>—</em>'; ?>
                                </span>
                                <button type="button" class="pe-btn-link pe-edit-meta-btn"
                                        data-order-id="<?php echo esc_attr($co->get_id()); ?>"
                                        data-cc-label="<?php echo esc_attr($co_cc); ?>"
                                        data-reference="<?php echo esc_attr($co_ref); ?>"
                                        title="<?php esc_attr_e('Modifier', 'portail-entreprises'); ?>">✏️</button>
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
        <?php endif; // résultats des commandes de l'entreprise ?>
    </div>
    <?php endif; // section commandes de l'entreprise ?>
    <?php endif; // managers (can_approve) ?>
</div>

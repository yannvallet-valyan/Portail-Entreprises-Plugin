<?php
/**
 * Template : Budgets — Mon Compte.
 */

defined('ABSPATH') || exit;

$user_id    = get_current_user_id();
$company    = PE_Permissions::get_user_company($user_id);
$role       = PE_Permissions::get_user_b2b_role($user_id);
$is_admin   = 'company_admin' === $role;
$budget_mgr = PE_Budget_Manager::get_instance();
$user_mgr   = PE_User_Manager::get_instance();

if (!$company) {
    echo '<p>' . esc_html__('Aucune entreprise associée à votre compte.', 'portail-entreprises') . '</p>';
    return;
}

// Traitement de la mise à jour des budgets (admin uniquement)
if ($is_admin && isset($_POST['pe_update_budgets']) && isset($_POST['_wpnonce'])) {
    if (!wp_verify_nonce(sanitize_key($_POST['_wpnonce']), 'pe_update_budgets_' . (int) $company->id)) {
        wc_add_notice(__('Erreur de sécurité.', 'portail-entreprises'), 'error');
    } else {
        $user_budgets = $_POST['user_budgets'] ?? [];
        $success      = true;

        foreach ($user_budgets as $target_user_id => $budgets) {
            $target_user_id = absint($target_user_id);

            // Vérification que l'utilisateur appartient à la même entreprise
            if (!PE_Permissions::user_belongs_to_company($target_user_id, (int) $company->id)) {
                continue;
            }

            $budget_data = [
                'budget_monthly'   => isset($budgets['monthly']) && $budgets['monthly'] !== '' ? (float) $budgets['monthly'] : null,
                'budget_annual'    => isset($budgets['annual']) && $budgets['annual'] !== '' ? (float) $budgets['annual'] : null,
                'budget_per_order' => isset($budgets['per_order']) && $budgets['per_order'] !== '' ? (float) $budgets['per_order'] : null,
            ];

            if (!$user_mgr->update_user_budget($target_user_id, (int) $company->id, $budget_data)) {
                $success = false;
            }
        }

        if ($success) {
            wc_add_notice(__('Budgets mis à jour avec succès.', 'portail-entreprises'), 'success');
        } else {
            wc_add_notice(__('Erreur lors de la mise à jour de certains budgets.', 'portail-entreprises'), 'error');
        }
    }
}

// Traitement de la mise à jour du budget entreprise (admin uniquement)
if ($is_admin && isset($_POST['pe_update_company_budget']) && isset($_POST['_wpnonce_company_budget'])) {
    if (!wp_verify_nonce(sanitize_key($_POST['_wpnonce_company_budget']), 'pe_update_company_budget_' . (int) $company->id)) {
        wc_add_notice(__('Erreur de sécurité.', 'portail-entreprises'), 'error');
    } else {
        global $wpdb;
        $co_monthly = isset($_POST['budget_monthly_company']) && $_POST['budget_monthly_company'] !== '' ? (float) $_POST['budget_monthly_company'] : null;
        $co_annual  = isset($_POST['budget_annual_company'])  && $_POST['budget_annual_company']  !== '' ? (float) $_POST['budget_annual_company']  : null;
        $co_block   = isset($_POST['budget_block_enabled']) ? 1 : 0;
        $wpdb->update(
            $wpdb->prefix . 'b2b_companies',
            ['budget_monthly' => $co_monthly, 'budget_annual' => $co_annual, 'budget_block_enabled' => $co_block],
            ['id' => (int) $company->id],
            ['%s', '%s', '%d'],
            ['%d']
        );
        // Rafraîchir les données de l'entreprise
        $company = PE_Permissions::get_user_company($user_id);
        wc_add_notice(__('Budget entreprise mis à jour.', 'portail-entreprises'), 'success');
    }
}

// Affichage de mon budget ou des budgets de tous les utilisateurs (admin)
$my_summary     = $budget_mgr->get_usage_summary($user_id);
$all_users      = $is_admin ? $user_mgr->get_company_users_with_budgets((int) $company->id) : [];
$company_budget = $is_admin ? $budget_mgr->get_company_usage_summary((int) $company->id) : null;
?>

<div class="pe-budgets-page">
    <h2 class="pe-section-title"><?php esc_html_e('Gestion des budgets', 'portail-entreprises'); ?></h2>

    <?php wc_print_notices(); ?>

    <!-- Mon budget personnel -->
    <div class="pe-card">
        <div class="pe-card-header">
            <h3><?php esc_html_e('Mon budget', 'portail-entreprises'); ?></h3>
            <span class="pe-period-label">
                <?php echo esc_html(date_i18n('F Y', strtotime('first day of this month'))); ?>
            </span>
        </div>
        <div class="pe-card-body">
            <div class="pe-budget-overview">
                <!-- Budget mensuel -->
                <div class="pe-budget-block">
                    <h4><?php esc_html_e('Budget mensuel', 'portail-entreprises'); ?></h4>
                    <?php if ($my_summary['budget_monthly'] !== null) : ?>
                    <div class="pe-progress-bar-container" title="<?php echo esc_attr($my_summary['percent_month']); ?>%">
                        <div class="pe-progress-bar <?php echo $my_summary['percent_month'] >= 90 ? 'pe-progress-danger' : ($my_summary['percent_month'] >= 70 ? 'pe-progress-warning' : ''); ?>"
                             style="width: <?php echo esc_attr($my_summary['percent_month']); ?>%"></div>
                    </div>
                    <div class="pe-budget-figures">
                        <span class="pe-spent"><?php echo wp_kses_post(wc_price($my_summary['spent_month'])); ?></span>
                        <span class="pe-separator">/</span>
                        <span class="pe-limit"><?php echo wp_kses_post(wc_price($my_summary['budget_monthly'])); ?></span>
                        <span class="pe-percent">(<?php echo esc_html($my_summary['percent_month']); ?>%)</span>
                    </div>
                    <p class="pe-remaining">
                        <?php
                        printf(
                            /* translators: %s: remaining amount */
                            esc_html__('Restant : %s', 'portail-entreprises'),
                            wp_kses_post(wc_price($my_summary['remaining_month']))
                        );
                        ?>
                    </p>
                    <?php else : ?>
                    <p class="pe-unlimited"><em><?php esc_html_e('Illimité', 'portail-entreprises'); ?></em></p>
                    <p class="pe-spent-info">
                        <?php
                        printf(
                            /* translators: %s: spent amount */
                            esc_html__('Dépensé ce mois : %s', 'portail-entreprises'),
                            wp_kses_post(wc_price($my_summary['spent_month']))
                        );
                        ?>
                    </p>
                    <?php endif; ?>
                </div>

                <!-- Budget annuel -->
                <div class="pe-budget-block">
                    <h4><?php esc_html_e('Budget annuel', 'portail-entreprises'); ?> (<?php echo esc_html(date('Y')); ?>)</h4>
                    <?php if ($my_summary['budget_annual'] !== null) : ?>
                    <?php $pct_year = $my_summary['budget_annual'] > 0 ? min(100, round($my_summary['spent_year'] / $my_summary['budget_annual'] * 100, 1)) : 0; ?>
                    <div class="pe-progress-bar-container">
                        <div class="pe-progress-bar <?php echo $pct_year >= 90 ? 'pe-progress-danger' : ($pct_year >= 70 ? 'pe-progress-warning' : ''); ?>"
                             style="width: <?php echo esc_attr($pct_year); ?>%"></div>
                    </div>
                    <div class="pe-budget-figures">
                        <span class="pe-spent"><?php echo wp_kses_post(wc_price($my_summary['spent_year'])); ?></span>
                        <span class="pe-separator">/</span>
                        <span class="pe-limit"><?php echo wp_kses_post(wc_price($my_summary['budget_annual'])); ?></span>
                        <span class="pe-percent">(<?php echo esc_html($pct_year); ?>%)</span>
                    </div>
                    <p class="pe-remaining">
                        <?php
                        printf(
                            /* translators: %s: remaining amount */
                            esc_html__('Restant : %s', 'portail-entreprises'),
                            wp_kses_post(wc_price($my_summary['remaining_year']))
                        );
                        ?>
                    </p>
                    <?php else : ?>
                    <p class="pe-unlimited"><em><?php esc_html_e('Illimité', 'portail-entreprises'); ?></em></p>
                    <p class="pe-spent-info">
                        <?php
                        printf(
                            /* translators: %s: spent amount */
                            esc_html__('Dépensé cette année : %s', 'portail-entreprises'),
                            wp_kses_post(wc_price($my_summary['spent_year']))
                        );
                        ?>
                    </p>
                    <?php endif; ?>
                </div>

                <!-- Budget par commande -->
                <?php if ($my_summary['budget_per_order'] !== null) : ?>
                <div class="pe-budget-block pe-budget-block-sm">
                    <h4><?php esc_html_e('Limite par commande', 'portail-entreprises'); ?></h4>
                    <p class="pe-limit-value"><?php echo wp_kses_post(wc_price($my_summary['budget_per_order'])); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Budgets de tous les utilisateurs (admin uniquement) -->
    <?php if ($is_admin && !empty($all_users)) : ?>
    <div class="pe-card" style="margin-top:24px;">
        <div class="pe-card-header">
            <h3><?php esc_html_e('Budgets des utilisateurs', 'portail-entreprises'); ?></h3>
        </div>
        <div class="pe-card-body">
            <form method="post" action="" class="pe-form">
                <?php wp_nonce_field('pe_update_budgets_' . (int) $company->id, '_wpnonce'); ?>
                <div class="pe-table-responsive">
                    <table class="pe-table pe-budgets-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Utilisateur', 'portail-entreprises'); ?></th>
                                <th><?php esc_html_e('Rôle', 'portail-entreprises'); ?></th>
                                <th><?php esc_html_e('Budget mensuel (€)', 'portail-entreprises'); ?></th>
                                <th><?php esc_html_e('Budget annuel (€)', 'portail-entreprises'); ?></th>
                                <th><?php esc_html_e('Budget/commande (€)', 'portail-entreprises'); ?></th>
                                <th><?php esc_html_e('Dépensé ce mois', 'portail-entreprises'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_users as $member) : ?>
                            <?php
                            $bpct = null;
                            if ($member->budget_monthly && $member->budget_monthly > 0) {
                                $bpct = min(100, round($member->amount_spent_month / $member->budget_monthly * 100, 0));
                            }
                            $all_roles = PE_Permissions::get_roles();
                            ?>
                            <tr>
                                <td>
                                    <?php echo esc_html($member->display_name); ?>
                                    <?php if ((int) $member->user_id === $user_id) : ?>
                                    <span class="pe-badge pe-badge-info"><?php esc_html_e('Moi', 'portail-entreprises'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($all_roles[$member->role] ?? $member->role); ?></td>
                                <td>
                                    <input type="number"
                                           name="user_budgets[<?php echo esc_attr($member->user_id); ?>][monthly]"
                                           class="pe-input-sm"
                                           min="0" step="0.01"
                                           value="<?php echo esc_attr($member->budget_monthly ?? ''); ?>"
                                           placeholder="<?php esc_attr_e('Illimité', 'portail-entreprises'); ?>" />
                                </td>
                                <td>
                                    <input type="number"
                                           name="user_budgets[<?php echo esc_attr($member->user_id); ?>][annual]"
                                           class="pe-input-sm"
                                           min="0" step="0.01"
                                           value="<?php echo esc_attr($member->budget_annual ?? ''); ?>"
                                           placeholder="<?php esc_attr_e('Illimité', 'portail-entreprises'); ?>" />
                                </td>
                                <td>
                                    <input type="number"
                                           name="user_budgets[<?php echo esc_attr($member->user_id); ?>][per_order]"
                                           class="pe-input-sm"
                                           min="0" step="0.01"
                                           value="<?php echo esc_attr($member->budget_per_order ?? ''); ?>"
                                           placeholder="<?php esc_attr_e('Illimité', 'portail-entreprises'); ?>" />
                                </td>
                                <td>
                                    <div class="pe-budget-cell">
                                        <?php echo wp_kses_post(wc_price($member->amount_spent_month)); ?>
                                        <?php if ($bpct !== null) : ?>
                                        <div class="pe-mini-progress">
                                            <div class="pe-mini-progress-bar <?php echo $bpct >= 90 ? 'pe-progress-danger' : ($bpct >= 70 ? 'pe-progress-warning' : ''); ?>"
                                                 style="width:<?php echo esc_attr($bpct); ?>%"></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="pe-form-actions" style="margin-top:16px;">
                    <button type="submit" name="pe_update_budgets" value="1" class="pe-btn pe-btn-primary">
                        <?php esc_html_e('Enregistrer les budgets', 'portail-entreprises'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Budget entreprise (admin uniquement) -->
    <?php if ($is_admin && $company_budget !== null) : ?>
    <div class="pe-card" style="margin-top:24px;">
        <div class="pe-card-header">
            <h3><?php esc_html_e('Budget entreprise', 'portail-entreprises'); ?></h3>
        </div>
        <div class="pe-card-body">

            <!-- Résumé consommation -->
            <div class="pe-budget-overview" style="margin-bottom:24px;">
                <div class="pe-budget-block">
                    <h4><?php esc_html_e('Consommation mensuelle', 'portail-entreprises'); ?></h4>
                    <?php if ($company_budget['budget_monthly'] !== null) : ?>
                    <?php $co_pct_m = $company_budget['percent_month'] ?? 0; ?>
                    <div class="pe-progress-bar-container">
                        <div class="pe-progress-bar <?php echo $co_pct_m >= 90 ? 'pe-progress-danger' : ($co_pct_m >= 70 ? 'pe-progress-warning' : ''); ?>"
                             style="width:<?php echo esc_attr($co_pct_m); ?>%"></div>
                    </div>
                    <div class="pe-budget-figures">
                        <span class="pe-spent"><?php echo wp_kses_post(wc_price($company_budget['spent_month'])); ?></span>
                        <span class="pe-separator">/</span>
                        <span class="pe-limit"><?php echo wp_kses_post(wc_price($company_budget['budget_monthly'])); ?></span>
                        <span class="pe-percent">(<?php echo esc_html($co_pct_m); ?>%)</span>
                    </div>
                    <p class="pe-remaining"><?php printf(esc_html__('Restant : %s', 'portail-entreprises'), wp_kses_post(wc_price($company_budget['remaining_month']))); ?></p>
                    <?php else : ?>
                    <p class="pe-unlimited"><em><?php esc_html_e('Non configuré', 'portail-entreprises'); ?></em></p>
                    <p class="pe-spent-info"><?php printf(esc_html__('Dépensé ce mois : %s', 'portail-entreprises'), wp_kses_post(wc_price($company_budget['spent_month']))); ?></p>
                    <?php endif; ?>
                </div>

                <div class="pe-budget-block">
                    <h4><?php esc_html_e('Consommation annuelle', 'portail-entreprises'); ?> (<?php echo esc_html(date('Y')); ?>)</h4>
                    <?php if ($company_budget['budget_annual'] !== null) : ?>
                    <?php $co_pct_y = $company_budget['percent_year'] ?? 0; ?>
                    <div class="pe-progress-bar-container">
                        <div class="pe-progress-bar <?php echo $co_pct_y >= 90 ? 'pe-progress-danger' : ($co_pct_y >= 70 ? 'pe-progress-warning' : ''); ?>"
                             style="width:<?php echo esc_attr($co_pct_y); ?>%"></div>
                    </div>
                    <div class="pe-budget-figures">
                        <span class="pe-spent"><?php echo wp_kses_post(wc_price($company_budget['spent_year'])); ?></span>
                        <span class="pe-separator">/</span>
                        <span class="pe-limit"><?php echo wp_kses_post(wc_price($company_budget['budget_annual'])); ?></span>
                        <span class="pe-percent">(<?php echo esc_html($co_pct_y); ?>%)</span>
                    </div>
                    <p class="pe-remaining"><?php printf(esc_html__('Restant : %s', 'portail-entreprises'), wp_kses_post(wc_price($company_budget['remaining_year']))); ?></p>
                    <?php else : ?>
                    <p class="pe-unlimited"><em><?php esc_html_e('Non configuré', 'portail-entreprises'); ?></em></p>
                    <p class="pe-spent-info"><?php printf(esc_html__('Dépensé cette année : %s', 'portail-entreprises'), wp_kses_post(wc_price($company_budget['spent_year']))); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Formulaire de configuration -->
            <form method="post" action="" class="pe-form">
                <?php wp_nonce_field('pe_update_company_budget_' . (int) $company->id, '_wpnonce_company_budget'); ?>
                <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-end;">
                    <div class="pe-form-group" style="flex:1;min-width:160px;">
                        <label class="pe-label"><?php esc_html_e('Budget mensuel (€)', 'portail-entreprises'); ?></label>
                        <input type="number" name="budget_monthly_company" class="pe-input" min="0" step="0.01"
                               value="<?php echo esc_attr($company->budget_monthly ?? ''); ?>"
                               placeholder="<?php esc_attr_e('Illimité', 'portail-entreprises'); ?>" />
                    </div>
                    <div class="pe-form-group" style="flex:1;min-width:160px;">
                        <label class="pe-label"><?php esc_html_e('Budget annuel (€)', 'portail-entreprises'); ?></label>
                        <input type="number" name="budget_annual_company" class="pe-input" min="0" step="0.01"
                               value="<?php echo esc_attr($company->budget_annual ?? ''); ?>"
                               placeholder="<?php esc_attr_e('Illimité', 'portail-entreprises'); ?>" />
                    </div>
                    <div class="pe-form-group" style="flex:0 0 auto;">
                        <label class="pe-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" name="budget_block_enabled" value="1"
                                   <?php checked((int)($company->budget_block_enabled ?? 1), 1); ?> />
                            <?php esc_html_e('Bloquer si budget dépassé', 'portail-entreprises'); ?>
                        </label>
                    </div>
                    <div class="pe-form-group" style="flex:0 0 auto;">
                        <button type="submit" name="pe_update_company_budget" value="1" class="pe-btn pe-btn-primary">
                            <?php esc_html_e('Enregistrer', 'portail-entreprises'); ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

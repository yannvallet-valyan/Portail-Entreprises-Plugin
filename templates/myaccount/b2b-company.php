<?php
/**
 * Template : Mon entreprise — Mon Compte.
 */

defined('ABSPATH') || exit;

$user_id     = get_current_user_id();
$company     = PE_Permissions::get_user_company($user_id);
$role        = PE_Permissions::get_user_b2b_role($user_id);
$is_admin    = 'company_admin' === $role;
$company_mgr = PE_Company_Manager::get_instance();

if (!$company) {
    echo '<p>' . esc_html__('Aucune entreprise associée à votre compte.', 'portail-entreprises') . '</p>';
    return;
}

$agencies     = $company_mgr->get_agencies((int) $company->id);
$cost_centers = $company_mgr->get_cost_centers((int) $company->id);

// Adresse de facturation depuis WooCommerce (pas depuis b2b_companies)
$wc_customer   = new WC_Customer($user_id);
$billing_parts = array_filter([
    $wc_customer->get_billing_address_1(),
    $wc_customer->get_billing_address_2(),
    trim($wc_customer->get_billing_postcode() . ' ' . $wc_customer->get_billing_city()),
    $wc_customer->get_billing_country() ? WC()->countries->countries[ $wc_customer->get_billing_country() ] ?? $wc_customer->get_billing_country() : '',
]);
?>

<div class="pe-company-page">
    <h2 class="pe-section-title"><?php esc_html_e('Mon entreprise', 'portail-entreprises'); ?></h2>

    <?php wc_print_notices(); ?>

    <!-- Informations générales -->
    <div class="pe-card">
        <div class="pe-card-header">
            <h3><?php echo esc_html($company->name); ?></h3>
            <?php if ('active' === $company->status) : ?>
                <span class="pe-badge pe-badge-active"><?php esc_html_e('Active', 'portail-entreprises'); ?></span>
            <?php else : ?>
                <span class="pe-badge pe-badge-suspended"><?php esc_html_e('Suspendue', 'portail-entreprises'); ?></span>
            <?php endif; ?>
        </div>
        <div class="pe-card-body">
            <div class="pe-info-grid">
                <?php if ($company->siret) : ?>
                <div class="pe-info-item">
                    <span class="pe-info-label"><?php esc_html_e('SIRET', 'portail-entreprises'); ?></span>
                    <span class="pe-info-value"><?php echo esc_html($company->siret); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($company->vat_number) : ?>
                <div class="pe-info-item">
                    <span class="pe-info-label"><?php esc_html_e('N° TVA', 'portail-entreprises'); ?></span>
                    <span class="pe-info-value"><?php echo esc_html($company->vat_number); ?></span>
                </div>
                <?php endif; ?>
                <div class="pe-info-item">
                    <span class="pe-info-label"><?php esc_html_e('Délai de paiement', 'portail-entreprises'); ?></span>
                    <span class="pe-info-value">
                        <?php
                        printf(
                            /* translators: %d: number of days */
                            esc_html(_n('%d jour', '%d jours', (int) $company->payment_terms, 'portail-entreprises')),
                            (int) $company->payment_terms
                        );
                        ?>
                    </span>
                </div>
                <?php if ($company->discount_rate > 0) : ?>
                <div class="pe-info-item">
                    <span class="pe-info-label"><?php esc_html_e('Remise commerciale', 'portail-entreprises'); ?></span>
                    <span class="pe-info-value"><?php echo esc_html(number_format((float) $company->discount_rate, 2, ',', ' ')); ?>%</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Adresse de facturation -->
    <div class="pe-card" style="margin-top:20px;">
        <div class="pe-card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h3><?php esc_html_e('Adresse de facturation', 'portail-entreprises'); ?></h3>
            <a href="<?php echo esc_url(wc_get_account_endpoint_url('edit-address') . 'billing/'); ?>" class="pe-btn pe-btn-sm pe-btn-outline">
                <?php esc_html_e('Modifier →', 'portail-entreprises'); ?>
            </a>
        </div>
        <div class="pe-card-body">
            <?php if (!empty($billing_parts)) : ?>
            <address class="pe-address" style="font-style:normal;line-height:1.7;">
                <?php echo nl2br(esc_html(implode("\n", $billing_parts))); ?>
            </address>
            <?php else : ?>
            <p class="pe-text-muted">
                <?php esc_html_e('Aucune adresse de facturation renseignée.', 'portail-entreprises'); ?>
                <a href="<?php echo esc_url(wc_get_account_endpoint_url('edit-address') . 'billing/'); ?>">
                    <?php esc_html_e('Ajouter une adresse →', 'portail-entreprises'); ?>
                </a>
            </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Agences -->
    <?php if (!empty($agencies)) : ?>
    <div class="pe-card" style="margin-top:20px;">
        <div class="pe-card-header">
            <h3><?php esc_html_e('Agences', 'portail-entreprises'); ?></h3>
        </div>
        <div class="pe-card-body">
            <div class="pe-agencies-grid">
                <?php foreach ($agencies as $agency) : ?>
                <?php $agency_address = (array) json_decode($agency->address, true); ?>
                <div class="pe-agency-card">
                    <h4><?php echo esc_html($agency->name); ?></h4>
                    <?php if (!empty(array_filter($agency_address))) : ?>
                    <address>
                        <?php echo esc_html(implode(', ', array_filter($agency_address))); ?>
                    </address>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Centres de coût -->
    <?php if (!empty($cost_centers)) : ?>
    <div class="pe-card" style="margin-top:20px;">
        <div class="pe-card-header">
            <h3><?php esc_html_e('Centres de coût', 'portail-entreprises'); ?></h3>
        </div>
        <div class="pe-card-body">
            <table class="pe-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Nom', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Code', 'portail-entreprises'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cost_centers as $cc) : ?>
                    <tr>
                        <td><?php echo esc_html($cc->name); ?></td>
                        <td><?php echo esc_html($cc->code ?: '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($is_admin) : ?>
    <!-- Budget entreprise -->
    <?php
    // Handle form submission
    if (isset($_POST['pe_update_company_budget']) && isset($_POST['_wpnonce_budget'])) {
        if (!wp_verify_nonce(sanitize_key($_POST['_wpnonce_budget']), 'pe_update_company_budget_' . (int) $company->id)) {
            wc_add_notice(__('Erreur de sécurité.', 'portail-entreprises'), 'error');
        } else {
            global $wpdb;
            $bm = isset($_POST['budget_monthly_company']) && $_POST['budget_monthly_company'] !== ''
                ? (float) $_POST['budget_monthly_company'] : null;
            $ba = isset($_POST['budget_annual_company']) && $_POST['budget_annual_company'] !== ''
                ? (float) $_POST['budget_annual_company'] : null;
            $block = isset($_POST['budget_block_enabled']) ? 1 : 0;

            $wpdb->update(
                $wpdb->prefix . 'b2b_companies',
                [
                    'budget_monthly'      => $bm,
                    'budget_annual'       => $ba,
                    'budget_block_enabled' => $block,
                ],
                ['id' => (int) $company->id],
                ['%s', '%s', '%d'],
                ['%d']
            );
            wc_add_notice(__('Budget entreprise mis à jour.', 'portail-entreprises'), 'success');
            $company = PE_Permissions::get_user_company($user_id);
        }
    }
    ?>
    <div class="pe-card" style="margin-top:20px;">
        <div class="pe-card-header">
            <h3><?php esc_html_e('Budget entreprise', 'portail-entreprises'); ?></h3>
        </div>
        <div class="pe-card-body">
            <form method="post" action="" class="pe-form">
                <?php wp_nonce_field('pe_update_company_budget_' . (int) $company->id, '_wpnonce_budget'); ?>
                <div class="pe-form-row-grid">
                    <div>
                        <label for="budget_monthly_company"><?php esc_html_e('Budget mensuel (€)', 'portail-entreprises'); ?></label>
                        <input type="number" id="budget_monthly_company" name="budget_monthly_company" step="0.01" min="0"
                               value="<?php echo esc_attr($company->budget_monthly ?? ''); ?>" class="pe-input"
                               placeholder="<?php esc_attr_e('Illimité', 'portail-entreprises'); ?>" />
                    </div>
                    <div>
                        <label for="budget_annual_company"><?php esc_html_e('Budget annuel (€)', 'portail-entreprises'); ?></label>
                        <input type="number" id="budget_annual_company" name="budget_annual_company" step="0.01" min="0"
                               value="<?php echo esc_attr($company->budget_annual ?? ''); ?>" class="pe-input"
                               placeholder="<?php esc_attr_e('Illimité', 'portail-entreprises'); ?>" />
                    </div>
                </div>
                <div class="pe-form-row" style="margin-top:12px;">
                    <label>
                        <input type="checkbox" name="budget_block_enabled" value="1"
                               <?php checked((int)($company->budget_block_enabled ?? 1), 1); ?> />
                        <?php esc_html_e('Bloquer les commandes quand le budget entreprise est atteint', 'portail-entreprises'); ?>
                    </label>
                    <p class="pe-text-muted" style="margin:4px 0 0;font-size:0.85em;">
                        <?php esc_html_e('Si décoché, le budget est affiché à titre indicatif uniquement.', 'portail-entreprises'); ?>
                    </p>
                </div>
                <div class="pe-form-actions">
                    <button type="submit" name="pe_update_company_budget" value="1" class="pe-btn pe-btn-primary">
                        <?php esc_html_e('Enregistrer le budget', 'portail-entreprises'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

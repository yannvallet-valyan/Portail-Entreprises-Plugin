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

$agencies    = $company_mgr->get_agencies((int) $company->id);
$cost_centers = $company_mgr->get_cost_centers((int) $company->id);
$address     = (array) json_decode($company->billing_address, true);

// Traitement du formulaire de mise à jour (company_admin uniquement)
if ($is_admin && isset($_POST['pe_update_company']) && isset($_POST['_wpnonce'])) {
    if (!wp_verify_nonce(sanitize_key($_POST['_wpnonce']), 'pe_update_company_' . (int) $company->id)) {
        wc_add_notice(__('Erreur de sécurité. Veuillez réessayer.', 'portail-entreprises'), 'error');
    } else {
        $update_data = [
            'billing_address' => [
                'address_1' => sanitize_text_field(wp_unslash($_POST['billing_address_1'] ?? '')),
                'address_2' => sanitize_text_field(wp_unslash($_POST['billing_address_2'] ?? '')),
                'city'      => sanitize_text_field(wp_unslash($_POST['billing_city'] ?? '')),
                'postcode'  => sanitize_text_field(wp_unslash($_POST['billing_postcode'] ?? '')),
                'country'   => sanitize_text_field(wp_unslash($_POST['billing_country'] ?? '')),
            ],
        ];

        if ($company_mgr->update_company((int) $company->id, $update_data)) {
            wc_add_notice(__('Informations mises à jour avec succès.', 'portail-entreprises'), 'success');
            // Rafraîchir les données
            $company = PE_Permissions::get_user_company($user_id);
            $address = (array) json_decode($company->billing_address, true);
        } else {
            wc_add_notice(__('Erreur lors de la mise à jour.', 'portail-entreprises'), 'error');
        }
    }
}
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
        <div class="pe-card-header">
            <h3><?php esc_html_e('Adresse de facturation', 'portail-entreprises'); ?></h3>
        </div>
        <div class="pe-card-body">
            <?php if ($is_admin) : ?>
            <form method="post" action="" class="pe-form">
                <?php wp_nonce_field('pe_update_company_' . (int) $company->id, '_wpnonce'); ?>
                <div class="pe-form-row">
                    <label for="billing_address_1"><?php esc_html_e('Adresse ligne 1', 'portail-entreprises'); ?></label>
                    <input type="text" id="billing_address_1" name="billing_address_1"
                           value="<?php echo esc_attr($address['address_1'] ?? ''); ?>" class="pe-input" />
                </div>
                <div class="pe-form-row">
                    <label for="billing_address_2"><?php esc_html_e('Adresse ligne 2', 'portail-entreprises'); ?></label>
                    <input type="text" id="billing_address_2" name="billing_address_2"
                           value="<?php echo esc_attr($address['address_2'] ?? ''); ?>" class="pe-input" />
                </div>
                <div class="pe-form-row-grid">
                    <div>
                        <label for="billing_postcode"><?php esc_html_e('Code postal', 'portail-entreprises'); ?></label>
                        <input type="text" id="billing_postcode" name="billing_postcode"
                               value="<?php echo esc_attr($address['postcode'] ?? ''); ?>" class="pe-input" />
                    </div>
                    <div>
                        <label for="billing_city"><?php esc_html_e('Ville', 'portail-entreprises'); ?></label>
                        <input type="text" id="billing_city" name="billing_city"
                               value="<?php echo esc_attr($address['city'] ?? ''); ?>" class="pe-input" />
                    </div>
                </div>
                <div class="pe-form-actions">
                    <button type="submit" name="pe_update_company" value="1" class="pe-btn pe-btn-primary">
                        <?php esc_html_e('Enregistrer', 'portail-entreprises'); ?>
                    </button>
                </div>
            </form>
            <?php else : ?>
            <address class="pe-address">
                <?php if (!empty($address['address_1'])) : ?>
                    <?php echo esc_html($address['address_1']); ?><br>
                <?php endif; ?>
                <?php if (!empty($address['address_2'])) : ?>
                    <?php echo esc_html($address['address_2']); ?><br>
                <?php endif; ?>
                <?php if (!empty($address['postcode']) || !empty($address['city'])) : ?>
                    <?php echo esc_html(trim(($address['postcode'] ?? '') . ' ' . ($address['city'] ?? ''))); ?><br>
                <?php endif; ?>
                <?php if (!empty($address['country'])) : ?>
                    <?php echo esc_html($address['country']); ?>
                <?php endif; ?>
            </address>
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

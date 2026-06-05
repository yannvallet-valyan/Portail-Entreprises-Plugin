<?php
/**
 * Vue : édition/création d'une entreprise B2B.
 */

defined('ABSPATH') || exit;

$manager    = PE_Company_Manager::get_instance();
$company_id = isset($_GET['company_id']) ? absint($_GET['company_id']) : 0;
$is_new     = 0 === $company_id;
$company    = $is_new ? null : $manager->get_company($company_id);

if (!$is_new && !$company) {
    wp_die(esc_html__('Société introuvable.', 'portail-entreprises'));
}

$billing_address  = $company ? (array) json_decode($company->billing_address, true) : [];
$modules_enabled  = $company ? (array) json_decode($company->modules_enabled, true) : [];
$company_users    = $is_new ? [] : $manager->get_company_users($company_id);
$agencies         = $is_new ? [] : $manager->get_agencies($company_id);
$cost_centers     = $is_new ? [] : $manager->get_cost_centers($company_id);
$approval_rules   = $is_new ? [] : $manager->get_approval_rules($company_id);

$nonce_action = $is_new ? 'pe_create_company' : 'pe_save_company_' . $company_id;

settings_errors('pe_messages');

$available_modules = [
    'approvals' => __('Workflow d\'approbation', 'portail-entreprises'),
    'budgets'   => __('Gestion des budgets', 'portail-entreprises'),
    'agencies'  => __('Agences', 'portail-entreprises'),
];

$all_roles = PE_Permissions::get_roles();
?>
<div class="wrap pe-admin-wrap">
    <h1>
        <?php if ($is_new) : ?>
            <?php esc_html_e('Créer une société', 'portail-entreprises'); ?>
        <?php else : ?>
            <?php echo esc_html(sprintf(__('Modifier : %s', 'portail-entreprises'), $company->name ?? '')); ?>
        <?php endif; ?>
    </h1>
    <a href="<?php echo esc_url(admin_url('admin.php?page=portail-b2b')); ?>" class="button">
        &larr; <?php esc_html_e('Retour à la liste', 'portail-entreprises'); ?>
    </a>

    <form method="post" action="" class="pe-company-form">
        <?php wp_nonce_field($nonce_action, '_wpnonce'); ?>

        <div class="pe-form-grid">
            <!-- Informations générales -->
            <div class="pe-form-card">
                <h2><?php esc_html_e('Informations générales', 'portail-entreprises'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="company_name"><?php esc_html_e('Nom de la société *', 'portail-entreprises'); ?></label></th>
                        <td>
                            <input type="text" id="company_name" name="company_name" class="regular-text" required
                                   value="<?php echo esc_attr($company->name ?? ''); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="siret"><?php esc_html_e('SIRET', 'portail-entreprises'); ?></label></th>
                        <td>
                            <input type="text" id="siret" name="siret" class="regular-text" maxlength="14"
                                   value="<?php echo esc_attr($company->siret ?? ''); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="vat_number"><?php esc_html_e('Numéro de TVA', 'portail-entreprises'); ?></label></th>
                        <td>
                            <input type="text" id="vat_number" name="vat_number" class="regular-text"
                                   value="<?php echo esc_attr($company->vat_number ?? ''); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="status"><?php esc_html_e('Statut', 'portail-entreprises'); ?></label></th>
                        <td>
                            <select id="status" name="status">
                                <option value="active" <?php selected($company->status ?? 'active', 'active'); ?>><?php esc_html_e('Actif', 'portail-entreprises'); ?></option>
                                <option value="suspended" <?php selected($company->status ?? '', 'suspended'); ?>><?php esc_html_e('Suspendu', 'portail-entreprises'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Adresse de facturation -->
            <div class="pe-form-card">
                <h2><?php esc_html_e('Adresse de facturation', 'portail-entreprises'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="billing_address_1"><?php esc_html_e('Adresse ligne 1', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="billing_address_1" name="billing_address_1" class="regular-text"
                                   value="<?php echo esc_attr($billing_address['address_1'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="billing_address_2"><?php esc_html_e('Adresse ligne 2', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="billing_address_2" name="billing_address_2" class="regular-text"
                                   value="<?php echo esc_attr($billing_address['address_2'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="billing_city"><?php esc_html_e('Ville', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="billing_city" name="billing_city" class="regular-text"
                                   value="<?php echo esc_attr($billing_address['city'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="billing_postcode"><?php esc_html_e('Code postal', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="billing_postcode" name="billing_postcode" class="small-text"
                                   value="<?php echo esc_attr($billing_address['postcode'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="billing_country"><?php esc_html_e('Pays', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="billing_country" name="billing_country" class="small-text" maxlength="2"
                                   value="<?php echo esc_attr($billing_address['country'] ?? 'FR'); ?>" /></td>
                    </tr>
                </table>
            </div>

            <!-- Paramètres commerciaux -->
            <div class="pe-form-card">
                <h2><?php esc_html_e('Paramètres commerciaux', 'portail-entreprises'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="discount_rate"><?php esc_html_e('Taux de remise (%)', 'portail-entreprises'); ?></label></th>
                        <td><input type="number" id="discount_rate" name="discount_rate" class="small-text"
                                   min="0" max="100" step="0.01"
                                   value="<?php echo esc_attr($company->discount_rate ?? '0'); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="credit_limit"><?php esc_html_e('Plafond de crédit (€)', 'portail-entreprises'); ?></label></th>
                        <td><input type="number" id="credit_limit" name="credit_limit" class="regular-text"
                                   min="0" step="0.01"
                                   value="<?php echo esc_attr($company->credit_limit ?? '0'); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="payment_terms"><?php esc_html_e('Délai de paiement (jours)', 'portail-entreprises'); ?></label></th>
                        <td><input type="number" id="payment_terms" name="payment_terms" class="small-text"
                                   min="0" max="365"
                                   value="<?php echo esc_attr($company->payment_terms ?? '30'); ?>" /></td>
                    </tr>
                </table>
            </div>

            <!-- Modules activés -->
            <div class="pe-form-card">
                <h2><?php esc_html_e('Modules activés', 'portail-entreprises'); ?></h2>
                <fieldset>
                    <?php foreach ($available_modules as $key => $label) : ?>
                    <label class="pe-checkbox-label">
                        <input type="checkbox" name="modules_enabled[]"
                               value="<?php echo esc_attr($key); ?>"
                               <?php checked(in_array($key, $modules_enabled, true)); ?> />
                        <?php echo esc_html($label); ?>
                    </label><br>
                    <?php endforeach; ?>
                </fieldset>
            </div>
        </div>

        <p class="submit">
            <input type="submit" name="pe_save_company" class="button button-primary"
                   value="<?php echo $is_new ? esc_attr__('Créer la société', 'portail-entreprises') : esc_attr__('Enregistrer les modifications', 'portail-entreprises'); ?>" />
        </p>
    </form>

    <?php if (!$is_new) : ?>

    <!-- Section Agences -->
    <div class="pe-form-card" style="margin-top:20px;">
        <h2><?php esc_html_e('Agences', 'portail-entreprises'); ?></h2>
        <?php if (empty($agencies)) : ?>
            <p><?php esc_html_e('Aucune agence enregistrée.', 'portail-entreprises'); ?></p>
        <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Nom', 'portail-entreprises'); ?></th>
                    <th><?php esc_html_e('Adresse', 'portail-entreprises'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($agencies as $agency) : ?>
                <?php $addr = (array) json_decode($agency->address, true); ?>
                <tr>
                    <td><?php echo esc_html($agency->name); ?></td>
                    <td><?php echo esc_html(implode(', ', array_filter($addr))); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Section Centres de coût -->
    <div class="pe-form-card" style="margin-top:20px;">
        <h2><?php esc_html_e('Centres de coût', 'portail-entreprises'); ?></h2>
        <?php if (empty($cost_centers)) : ?>
            <p><?php esc_html_e('Aucun centre de coût enregistré.', 'portail-entreprises'); ?></p>
        <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
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
        <?php endif; ?>
        <div style="margin-top:10px;">
            <h3><?php esc_html_e('Ajouter un centre de coût', 'portail-entreprises'); ?></h3>
            <input type="text" id="new_cc_name" placeholder="<?php esc_attr_e('Nom', 'portail-entreprises'); ?>" class="regular-text" />
            <input type="text" id="new_cc_code" placeholder="<?php esc_attr_e('Code', 'portail-entreprises'); ?>" class="small-text" />
            <button type="button" class="button" id="pe-add-cost-center"
                    data-company="<?php echo esc_attr($company_id); ?>"
                    data-nonce="<?php echo esc_attr(wp_create_nonce('pe_b2b_ajax')); ?>">
                <?php esc_html_e('Ajouter', 'portail-entreprises'); ?>
            </button>
        </div>
    </div>

    <!-- Règles d'approbation -->
    <div class="pe-form-card" style="margin-top:20px;">
        <h2><?php esc_html_e('Règles d\'approbation', 'portail-entreprises'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('pe_save_approval_rule_' . $company_id, '_wpnonce_rule'); ?>
            <input type="hidden" name="action" value="pe_save_approval_rule" />
            <input type="hidden" name="company_id" value="<?php echo esc_attr($company_id); ?>" />
            <?php if (empty($approval_rules)) : ?>
                <p><?php esc_html_e('Aucune règle configurée.', 'portail-entreprises'); ?></p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped" id="pe-approval-rules-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Seuil min (€)', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Seuil max (€)', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Rôles approbateurs', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Délai (h)', 'portail-entreprises'); ?></th>
                        <th><?php esc_html_e('Actions', 'portail-entreprises'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approval_rules as $rule) : ?>
                    <?php $roles_arr = (array) json_decode($rule->approver_roles, true); ?>
                    <tr>
                        <td><?php echo esc_html(number_format((float) $rule->threshold_min, 2, ',', ' ')); ?> €</td>
                        <td><?php echo $rule->threshold_max ? esc_html(number_format((float) $rule->threshold_max, 2, ',', ' ')) . ' €' : '—'; ?></td>
                        <td>
                            <?php
                            $role_labels = [];
                            foreach ($roles_arr as $r) {
                                $role_labels[] = esc_html($all_roles[$r] ?? $r);
                            }
                            echo implode(', ', $role_labels);
                            ?>
                        </td>
                        <td><?php echo esc_html($rule->delay_hours); ?>h</td>
                        <td>
                            <button type="button" class="button button-small pe-delete-approval-rule"
                                    data-rule-id="<?php echo esc_attr($rule->id); ?>"
                                    data-company-id="<?php echo esc_attr($company_id); ?>"
                                    data-nonce="<?php echo esc_attr(wp_create_nonce('pe_b2b_ajax')); ?>">
                                <?php esc_html_e('Supprimer', 'portail-entreprises'); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <div style="margin-top:15px; background:#f9f9f9; padding:15px; border:1px solid #ddd;">
                <h3><?php esc_html_e('Ajouter une règle', 'portail-entreprises'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th><label for="rule_min"><?php esc_html_e('Seuil minimum (€)', 'portail-entreprises'); ?></label></th>
                        <td><input type="number" name="threshold_min" id="rule_min" class="small-text" min="0" step="0.01" value="0" /></td>
                    </tr>
                    <tr>
                        <th><label for="rule_max"><?php esc_html_e('Seuil maximum (€, vide = illimité)', 'portail-entreprises'); ?></label></th>
                        <td><input type="number" name="threshold_max" id="rule_max" class="small-text" min="0" step="0.01" value="" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Rôles approbateurs', 'portail-entreprises'); ?></th>
                        <td>
                            <?php foreach (['company_admin' => __('Administrateur société', 'portail-entreprises'), 'purchase_manager' => __('Responsable achats', 'portail-entreprises')] as $key => $label) : ?>
                            <label>
                                <input type="checkbox" name="approver_roles[]" value="<?php echo esc_attr($key); ?>" />
                                <?php echo esc_html($label); ?>
                            </label><br>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="rule_delay"><?php esc_html_e('Délai de validation (heures)', 'portail-entreprises'); ?></label></th>
                        <td><input type="number" name="delay_hours" id="rule_delay" class="small-text" min="1" value="24" /></td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" class="button button-primary"
                           value="<?php esc_attr_e('Ajouter la règle', 'portail-entreprises'); ?>" />
                </p>
            </div>
        </form>
    </div>

    <!-- Utilisateurs de la société -->
    <div class="pe-form-card" style="margin-top:20px;">
        <h2><?php esc_html_e('Utilisateurs', 'portail-entreprises'); ?></h2>
        <?php if (empty($company_users)) : ?>
            <p><?php esc_html_e('Aucun utilisateur associé.', 'portail-entreprises'); ?></p>
        <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Nom', 'portail-entreprises'); ?></th>
                    <th><?php esc_html_e('E-mail', 'portail-entreprises'); ?></th>
                    <th><?php esc_html_e('Rôle', 'portail-entreprises'); ?></th>
                    <th><?php esc_html_e('Budget mensuel', 'portail-entreprises'); ?></th>
                    <th><?php esc_html_e('Actions', 'portail-entreprises'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($company_users as $user) : ?>
                <tr>
                    <td>
                        <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . (int) $user->user_id)); ?>">
                            <?php echo esc_html($user->display_name); ?>
                        </a>
                    </td>
                    <td><?php echo esc_html($user->user_email); ?></td>
                    <td><?php echo esc_html($all_roles[$user->role] ?? $user->role); ?></td>
                    <td>
                        <?php if ($user->budget_monthly !== null) : ?>
                            <?php echo esc_html(number_format((float) $user->budget_monthly, 2, ',', ' ')); ?> €
                        <?php else : ?>
                            <em><?php esc_html_e('Illimité', 'portail-entreprises'); ?></em>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . (int) $user->user_id)); ?>"
                           class="button button-small">
                            <?php esc_html_e('Modifier', 'portail-entreprises'); ?>
                        </a>
                        <button type="button" class="button button-small pe-remove-user"
                                data-user-id="<?php echo esc_attr($user->user_id); ?>"
                                data-company-id="<?php echo esc_attr($company_id); ?>"
                                data-nonce="<?php echo esc_attr(wp_create_nonce('pe_b2b_ajax')); ?>">
                            <?php esc_html_e('Retirer', 'portail-entreprises'); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php endif; // !$is_new ?>
</div>

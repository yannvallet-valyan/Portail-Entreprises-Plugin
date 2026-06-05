<?php
/**
 * Template : Utilisateurs de l'entreprise — Mon Compte.
 */

defined('ABSPATH') || exit;

$user_id     = get_current_user_id();
$company     = PE_Permissions::get_user_company($user_id);
$role        = PE_Permissions::get_user_b2b_role($user_id);
$is_admin    = 'company_admin' === $role;
$company_mgr = PE_Company_Manager::get_instance();
$user_mgr    = PE_User_Manager::get_instance();

if (!$company) {
    echo '<p>' . esc_html__('Aucune entreprise associée à votre compte.', 'portail-entreprises') . '</p>';
    return;
}

if (!PE_Permissions::user_can($user_id, 'view_all_orders') && !PE_Permissions::user_can($user_id, 'view_company_orders')) {
    echo '<p>' . esc_html__('Vous n\'avez pas accès à cette section.', 'portail-entreprises') . '</p>';
    return;
}

// Traitement du formulaire d'ajout d'utilisateur
if ($is_admin && isset($_POST['pe_create_user']) && isset($_POST['_wpnonce'])) {
    if (!wp_verify_nonce(sanitize_key($_POST['_wpnonce']), 'pe_create_user_' . (int) $company->id)) {
        wc_add_notice(__('Erreur de sécurité.', 'portail-entreprises'), 'error');
    } else {
        $user_data = [
            'email'            => sanitize_email(wp_unslash($_POST['user_email'] ?? '')),
            'first_name'       => sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')),
            'last_name'        => sanitize_text_field(wp_unslash($_POST['last_name'] ?? '')),
            'budget_monthly'   => isset($_POST['budget_monthly']) && $_POST['budget_monthly'] !== '' ? (float) $_POST['budget_monthly'] : null,
            'budget_per_order' => isset($_POST['budget_per_order']) && $_POST['budget_per_order'] !== '' ? (float) $_POST['budget_per_order'] : null,
        ];
        $new_role = sanitize_key($_POST['b2b_role'] ?? 'buyer');

        $result = $user_mgr->create_sub_account($user_data, (int) $company->id, $new_role);

        if (is_wp_error($result)) {
            wc_add_notice($result->get_error_message(), 'error');
        } else {
            wc_add_notice(__('Utilisateur créé avec succès. Un email lui a été envoyé.', 'portail-entreprises'), 'success');
        }
    }
}

$company_users = $user_mgr->get_company_users_with_budgets((int) $company->id);
$all_roles     = PE_Permissions::get_roles();
?>

<div class="pe-users-page">
    <h2 class="pe-section-title"><?php esc_html_e('Utilisateurs de l\'entreprise', 'portail-entreprises'); ?></h2>

    <?php wc_print_notices(); ?>

    <!-- Liste des utilisateurs -->
    <div class="pe-card">
        <div class="pe-card-header">
            <h3><?php esc_html_e('Membres actifs', 'portail-entreprises'); ?></h3>
            <span class="pe-badge pe-badge-neutral"><?php echo esc_html(count($company_users)); ?></span>
        </div>
        <div class="pe-card-body">
            <?php if (empty($company_users)) : ?>
                <p><?php esc_html_e('Aucun utilisateur dans cette entreprise.', 'portail-entreprises'); ?></p>
            <?php else : ?>
            <div class="pe-table-responsive">
                <table class="pe-table pe-users-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Nom', 'portail-entreprises'); ?></th>
                            <th><?php esc_html_e('E-mail', 'portail-entreprises'); ?></th>
                            <th><?php esc_html_e('Rôle', 'portail-entreprises'); ?></th>
                            <th><?php esc_html_e('Budget mensuel', 'portail-entreprises'); ?></th>
                            <th><?php esc_html_e('Dépenses du mois', 'portail-entreprises'); ?></th>
                            <?php if ($is_admin) : ?>
                            <th><?php esc_html_e('Actions', 'portail-entreprises'); ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($company_users as $member) : ?>
                        <?php
                        $is_current = (int) $member->user_id === $user_id;
                        $budget_pct = null;
                        if ($member->budget_monthly && $member->budget_monthly > 0) {
                            $budget_pct = min(100, round($member->amount_spent_month / $member->budget_monthly * 100, 0));
                        }
                        ?>
                        <tr <?php echo $is_current ? 'class="pe-current-user"' : ''; ?>>
                            <td>
                                <?php echo esc_html($member->display_name); ?>
                                <?php if ($is_current) : ?>
                                    <span class="pe-badge pe-badge-info"><?php esc_html_e('Moi', 'portail-entreprises'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($member->user_email); ?></td>
                            <td>
                                <span class="pe-role-badge pe-role-<?php echo esc_attr($member->role); ?>">
                                    <?php echo esc_html($all_roles[$member->role] ?? $member->role); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($member->budget_monthly !== null) : ?>
                                    <?php echo wp_kses_post(wc_price($member->budget_monthly)); ?>
                                <?php else : ?>
                                    <em><?php esc_html_e('Illimité', 'portail-entreprises'); ?></em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="pe-budget-cell">
                                    <span><?php echo wp_kses_post(wc_price($member->amount_spent_month)); ?></span>
                                    <?php if ($budget_pct !== null) : ?>
                                    <div class="pe-mini-progress">
                                        <div class="pe-mini-progress-bar <?php echo $budget_pct >= 90 ? 'pe-progress-danger' : ($budget_pct >= 70 ? 'pe-progress-warning' : ''); ?>"
                                             style="width:<?php echo esc_attr($budget_pct); ?>%"></div>
                                    </div>
                                    <small><?php echo esc_html($budget_pct); ?>%</small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <?php if ($is_admin) : ?>
                            <td class="pe-actions-cell">
                                <?php if (!$is_current) : ?>
                                <button type="button"
                                        class="pe-btn pe-btn-sm pe-btn-danger pe-remove-user"
                                        data-user-id="<?php echo esc_attr($member->user_id); ?>"
                                        data-company-id="<?php echo esc_attr((int) $company->id); ?>"
                                        data-nonce="<?php echo esc_attr(wp_create_nonce('pe_b2b_ajax')); ?>">
                                    <?php esc_html_e('Retirer', 'portail-entreprises'); ?>
                                </button>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Formulaire d'ajout d'utilisateur (company_admin uniquement) -->
    <?php if ($is_admin) : ?>
    <div class="pe-card" style="margin-top:24px;">
        <div class="pe-card-header">
            <h3><?php esc_html_e('Inviter un utilisateur', 'portail-entreprises'); ?></h3>
        </div>
        <div class="pe-card-body">
            <form method="post" action="" class="pe-form pe-invite-form">
                <?php wp_nonce_field('pe_create_user_' . (int) $company->id, '_wpnonce'); ?>

                <div class="pe-form-grid">
                    <div class="pe-form-row">
                        <label for="first_name"><?php esc_html_e('Prénom', 'portail-entreprises'); ?></label>
                        <input type="text" id="first_name" name="first_name" class="pe-input" required />
                    </div>
                    <div class="pe-form-row">
                        <label for="last_name"><?php esc_html_e('Nom', 'portail-entreprises'); ?></label>
                        <input type="text" id="last_name" name="last_name" class="pe-input" required />
                    </div>
                    <div class="pe-form-row">
                        <label for="user_email"><?php esc_html_e('Adresse e-mail *', 'portail-entreprises'); ?></label>
                        <input type="email" id="user_email" name="user_email" class="pe-input" required />
                    </div>
                    <div class="pe-form-row">
                        <label for="b2b_role"><?php esc_html_e('Rôle', 'portail-entreprises'); ?></label>
                        <select id="b2b_role" name="b2b_role" class="pe-select">
                            <?php foreach ($all_roles as $key => $label) : ?>
                            <?php if ('company_admin' === $key) continue; // Ne pas permettre de créer un autre admin via ce formulaire ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pe-form-row">
                        <label for="budget_monthly"><?php esc_html_e('Budget mensuel (€, vide = illimité)', 'portail-entreprises'); ?></label>
                        <input type="number" id="budget_monthly" name="budget_monthly" class="pe-input"
                               min="0" step="0.01" placeholder="<?php esc_attr_e('Illimité', 'portail-entreprises'); ?>" />
                    </div>
                    <div class="pe-form-row">
                        <label for="budget_per_order"><?php esc_html_e('Budget par commande (€)', 'portail-entreprises'); ?></label>
                        <input type="number" id="budget_per_order" name="budget_per_order" class="pe-input"
                               min="0" step="0.01" placeholder="<?php esc_attr_e('Illimité', 'portail-entreprises'); ?>" />
                    </div>
                </div>

                <div class="pe-form-actions">
                    <button type="submit" name="pe_create_user" value="1" class="pe-btn pe-btn-primary">
                        <?php esc_html_e('Inviter l\'utilisateur', 'portail-entreprises'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

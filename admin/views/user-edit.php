<?php
/**
 * Vue : section B2B dans l'édition d'un utilisateur WordPress.
 * Variables disponibles depuis PE_Admin::add_user_b2b_fields() :
 * $user, $is_b2b, $b2b_role, $company, $all_roles, $all_companies
 */

defined('ABSPATH') || exit;
?>
<div class="pe-user-b2b-section" style="margin-top:30px;">
    <h2><?php esc_html_e('Paramètres B2B', 'portail-entreprises'); ?></h2>
    <?php wp_nonce_field('pe_save_user_b2b_' . $user->ID, '_wpnonce_b2b'); ?>

    <table class="form-table">
        <tr>
            <th scope="row"><?php esc_html_e('Activer B2B', 'portail-entreprises'); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="b2b_enabled" value="1" <?php checked($is_b2b, true); ?> id="pe_b2b_enabled_toggle" />
                    <?php esc_html_e('Cet utilisateur est un client B2B', 'portail-entreprises'); ?>
                </label>
            </td>
        </tr>
    </table>

    <div id="pe_b2b_fields" <?php echo $is_b2b ? '' : 'style="display:none;"'; ?>>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="b2b_company_id"><?php esc_html_e('Société', 'portail-entreprises'); ?></label>
                </th>
                <td>
                    <select name="b2b_company_id" id="b2b_company_id">
                        <option value=""><?php esc_html_e('-- Sélectionner une société --', 'portail-entreprises'); ?></option>
                        <?php foreach ($all_companies as $c) : ?>
                        <option value="<?php echo esc_attr($c->id); ?>"
                                <?php selected($company ? (int) $company->id : 0, (int) $c->id); ?>>
                            <?php echo esc_html($c->name); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="b2b_role"><?php esc_html_e('Rôle B2B', 'portail-entreprises'); ?></label>
                </th>
                <td>
                    <select name="b2b_role" id="b2b_role">
                        <?php foreach ($all_roles as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($b2b_role, $key); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e('company_admin : toutes les permissions | purchase_manager : approbation + commandes | buyer : commandes dans budget | requester : panier uniquement | accountant : lecture seule', 'portail-entreprises'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row" colspan="2">
                    <h3 style="margin:0;"><?php esc_html_e('Limites budgétaires individuelles', 'portail-entreprises'); ?></h3>
                </th>
            </tr>

            <?php
            // Récupérer les données budgétaires actuelles
            global $wpdb;
            $budget_data = null;
            if ($company) {
                $budget_data = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT budget_monthly, budget_annual, budget_per_order FROM {$wpdb->prefix}b2b_user_company WHERE user_id = %d AND company_id = %d LIMIT 1",
                        (int) $user->ID,
                        (int) $company->id
                    )
                );
            }
            ?>

            <tr>
                <th scope="row">
                    <label for="budget_monthly"><?php esc_html_e('Budget mensuel (€)', 'portail-entreprises'); ?></label>
                </th>
                <td>
                    <input type="number" name="budget_monthly" id="budget_monthly" class="regular-text"
                           min="0" step="0.01"
                           value="<?php echo esc_attr($budget_data->budget_monthly ?? ''); ?>"
                           placeholder="<?php esc_attr_e('Vide = illimité', 'portail-entreprises'); ?>" />
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="budget_annual"><?php esc_html_e('Budget annuel (€)', 'portail-entreprises'); ?></label>
                </th>
                <td>
                    <input type="number" name="budget_annual" id="budget_annual" class="regular-text"
                           min="0" step="0.01"
                           value="<?php echo esc_attr($budget_data->budget_annual ?? ''); ?>"
                           placeholder="<?php esc_attr_e('Vide = illimité', 'portail-entreprises'); ?>" />
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="budget_per_order"><?php esc_html_e('Budget par commande (€)', 'portail-entreprises'); ?></label>
                </th>
                <td>
                    <input type="number" name="budget_per_order" id="budget_per_order" class="regular-text"
                           min="0" step="0.01"
                           value="<?php echo esc_attr($budget_data->budget_per_order ?? ''); ?>"
                           placeholder="<?php esc_attr_e('Vide = illimité', 'portail-entreprises'); ?>" />
                </td>
            </tr>
        </table>
    </div>

    <script type="text/javascript">
    (function() {
        var toggle = document.getElementById('pe_b2b_enabled_toggle');
        var fields = document.getElementById('pe_b2b_fields');
        if (toggle && fields) {
            toggle.addEventListener('change', function() {
                fields.style.display = this.checked ? '' : 'none';
            });
        }
    })();
    </script>
</div>

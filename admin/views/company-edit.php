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
$shipping_address = $company ? (array) json_decode($company->shipping_address ?? '', true) : [];
$modules_enabled  = $company ? (array) json_decode($company->modules_enabled, true) : [];
$company_users    = $is_new ? [] : $manager->get_company_users($company_id);
$approval_rules   = $is_new ? [] : $manager->get_approval_rules($company_id);

$nonce_action  = $is_new ? 'pe_create_company' : 'pe_save_company_' . $company_id;
$form_action   = $is_new ? 'pe_create_company' : 'pe_update_company';

$available_modules = [
    'approvals' => __('Workflow d\'approbation', 'portail-entreprises'),
    'budgets'   => __('Gestion des budgets', 'portail-entreprises'),
];

$all_roles = PE_Permissions::get_roles();
?>
<div class="wrap pe-admin-wrap">
    <?php
    // Affichage des messages de succès/erreur passés en query string.
    $pe_notice = isset($_GET['pe_notice']) ? sanitize_key($_GET['pe_notice']) : '';
    $pe_error  = isset($_GET['pe_error']) ? sanitize_text_field(wp_unslash($_GET['pe_error'])) : '';
    if ('created' === $pe_notice) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Société créée avec succès.', 'portail-entreprises'); ?></p></div>
    <?php elseif ('updated' === $pe_notice) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Société mise à jour avec succès.', 'portail-entreprises'); ?></p></div>
    <?php elseif ($pe_error) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($pe_error); ?></p></div>
    <?php endif; ?>

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

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pe-company-form">
        <?php wp_nonce_field($nonce_action, '_wpnonce'); ?>
        <input type="hidden" name="action" value="<?php echo esc_attr($form_action); ?>" />
        <?php if (!$is_new) : ?>
        <input type="hidden" name="company_id" value="<?php echo esc_attr($company_id); ?>" />
        <?php endif; ?>

        <div class="pe-form-grid">
            <!-- Définition -->
            <div class="pe-form-card">
                <h2><?php esc_html_e('Définition', 'portail-entreprises'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="customer_code"><?php esc_html_e('Code client', 'portail-entreprises'); ?></label></th>
                        <td>
                            <input type="text" id="customer_code" name="customer_code" class="regular-text"
                                   value="<?php echo esc_attr($company->customer_code ?? ''); ?>" />
                            <p class="description">
                                <?php esc_html_e('Identifiant interne permettant de distinguer cette société lorsqu\'un même client possède plusieurs entreprises.', 'portail-entreprises'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="company_name"><?php esc_html_e('Libellé client (nom de la société) *', 'portail-entreprises'); ?></label></th>
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
                        <th><label for="vat_number"><?php esc_html_e('N° de TVA intracommunautaire', 'portail-entreprises'); ?></label></th>
                        <td>
                            <input type="text" id="vat_number" name="vat_number" class="regular-text"
                                   value="<?php echo esc_attr($company->vat_number ?? ''); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="naf_code"><?php esc_html_e('Code NAF', 'portail-entreprises'); ?></label></th>
                        <td>
                            <input type="text" id="naf_code" name="naf_code" class="regular-text" maxlength="10"
                                   value="<?php echo esc_attr($company->naf_code ?? ''); ?>" />
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

            <!-- Éléments de communication -->
            <div class="pe-form-card">
                <h2><?php esc_html_e('Éléments de communication', 'portail-entreprises'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="phone"><?php esc_html_e('Téléphone société', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="phone" name="phone" class="regular-text"
                                   value="<?php echo esc_attr($company->phone ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="fax"><?php esc_html_e('Fax société', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="fax" name="fax" class="regular-text"
                                   value="<?php echo esc_attr($company->fax ?? ''); ?>" /></td>
                    </tr>
                </table>
            </div>

            <!-- Contact principal -->
            <div class="pe-form-card">
                <h2><?php esc_html_e('Contact', 'portail-entreprises'); ?></h2>
                <p class="description" style="margin-top:-6px;">
                    <?php esc_html_e('Contact principal de la société.', 'portail-entreprises'); ?>
                </p>

                <div style="position:relative; margin-bottom:14px;">
                    <label for="pe-contact-search-input" style="display:block; font-weight:600; margin-bottom:4px;">
                        <?php esc_html_e('Rattacher un client existant', 'portail-entreprises'); ?>
                    </label>
                    <input type="text" id="pe-contact-search-input" class="regular-text" style="width:100%;"
                           placeholder="<?php esc_attr_e('Nom ou e-mail…', 'portail-entreprises'); ?>" autocomplete="off" />
                    <div id="pe-contact-search-results"
                         style="display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #ddd; max-height:220px; overflow-y:auto; z-index:200; box-shadow:0 2px 6px rgba(0,0,0,.15);"></div>
                    <p class="description" style="margin:4px 0 0;">
                        <?php esc_html_e('Sélectionnez un client existant pour remplir automatiquement les informations déjà connues : nom, société, SIRET, TVA, code NAF, téléphone, adresses, règlement, remise… (si ce client possède déjà une société dans le portail, ces informations sont reprises de sa fiche).', 'portail-entreprises'); ?>
                    </p>
                    <p id="pe-contact-search-msg" style="margin-top:6px; font-weight:600;"></p>
                </div>

                <table class="form-table">
                    <tr>
                        <th><label for="contact_function"><?php esc_html_e('Fonction', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="contact_function" name="contact_function" class="regular-text"
                                   value="<?php echo esc_attr($company->contact_function ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="contact_last_name"><?php esc_html_e('Nom', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="contact_last_name" name="contact_last_name" class="regular-text"
                                   value="<?php echo esc_attr($company->contact_last_name ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="contact_first_name"><?php esc_html_e('Prénom', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="contact_first_name" name="contact_first_name" class="regular-text"
                                   value="<?php echo esc_attr($company->contact_first_name ?? ''); ?>" /></td>
                    </tr>
                </table>
            </div>

            <script>
            jQuery(function($) {
                var searchTimer;
                var ajaxNonce  = '<?php echo esc_js(wp_create_nonce('pe_b2b_ajax')); ?>';
                var companyId  = <?php echo (int) $company_id; ?>;

                function setIfPresent(id, val) {
                    if (val !== undefined && val !== null && val !== '') {
                        $('#' + id).val(val);
                    }
                }

                $('#pe-contact-search-input').on('keyup', function() {
                    clearTimeout(searchTimer);
                    var q = $(this).val().trim();
                    if (q.length < 2) { $('#pe-contact-search-results').hide().empty(); return; }
                    searchTimer = setTimeout(function() {
                        $.post(ajaxurl, { action: 'pe_admin_search_users', nonce: ajaxNonce, search: q, company_id: companyId }, function(res) {
                            var $list = $('#pe-contact-search-results').empty();
                            if (res.success && res.data.users.length) {
                                $.each(res.data.users, function(i, u) {
                                    $('<div>').text(u.name + ' — ' + u.email)
                                        .css({ padding: '7px 12px', cursor: 'pointer', borderBottom: '1px solid #f0f0f0' })
                                        .on('mouseenter', function() { $(this).css('background', '#f0f6ff'); })
                                        .on('mouseleave', function() { $(this).css('background', ''); })
                                        .on('click', function() {
                                            $('#pe-contact-search-input').val(u.name);
                                            $list.hide().empty();
                                            $('#pe-contact-search-msg').css('color', '#666').text('<?php echo esc_js(__('Chargement des informations…', 'portail-entreprises')); ?>');

                                            $.post(ajaxurl, { action: 'pe_admin_get_customer_prefill', nonce: ajaxNonce, user_id: u.id, exclude_company_id: companyId }, function(res2) {
                                                if (res2.success) {
                                                    var d = res2.data;
                                                    setIfPresent('contact_first_name', d.first_name);
                                                    setIfPresent('contact_last_name', d.last_name);
                                                    setIfPresent('company_name', d.company_name);
                                                    setIfPresent('phone', d.phone);
                                                    setIfPresent('fax', d.fax);
                                                    setIfPresent('siret', d.siret);
                                                    setIfPresent('vat_number', d.vat_number);
                                                    setIfPresent('naf_code', d.naf_code);
                                                    setIfPresent('customer_code', d.customer_code);
                                                    setIfPresent('category', d.category);
                                                    setIfPresent('activity', d.activity);
                                                    setIfPresent('payment_terms', d.payment_terms);
                                                    setIfPresent('payment_method_code', d.payment_method_code);
                                                    setIfPresent('payment_method_label', d.payment_method_label);
                                                    setIfPresent('discount_rate', d.discount_rate);
                                                    setIfPresent('credit_limit', d.credit_limit);
                                                    setIfPresent('billing_address_1', d.billing_address.address_1);
                                                    setIfPresent('billing_address_2', d.billing_address.address_2);
                                                    setIfPresent('billing_city', d.billing_address.city);
                                                    setIfPresent('billing_postcode', d.billing_address.postcode);
                                                    setIfPresent('billing_country', d.billing_address.country);
                                                    setIfPresent('shipping_address_1', d.shipping_address.address_1);
                                                    setIfPresent('shipping_address_2', d.shipping_address.address_2);
                                                    setIfPresent('shipping_city', d.shipping_address.city);
                                                    setIfPresent('shipping_postcode', d.shipping_address.postcode);
                                                    setIfPresent('shipping_country', d.shipping_address.country);
                                                    $('#pe-contact-search-msg').css('color', '#28a745')
                                                        .text('<?php echo esc_js(__('Informations disponibles pré-remplies (nom, société, SIRET, TVA, adresses, règlement…). Vérifiez avant d\'enregistrer.', 'portail-entreprises')); ?>');
                                                } else {
                                                    $('#pe-contact-search-msg').css('color', '#b32d2e').text(res2.data.message);
                                                }
                                            });
                                        }).appendTo($list);
                                });
                                $list.show();
                            } else {
                                $list.append($('<div>').text('<?php echo esc_js(__('Aucun résultat.', 'portail-entreprises')); ?>').css({ padding: '7px 12px', color: '#888' })).show();
                            }
                        });
                    }, 300);
                });

                $(document).on('click', function(e) {
                    if (!$(e.target).closest('#pe-contact-search-input, #pe-contact-search-results').length) {
                        $('#pe-contact-search-results').hide();
                    }
                });
            });
            </script>

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

            <!-- Adresse de livraison -->
            <div class="pe-form-card">
                <h2><?php esc_html_e('Adresse de livraison', 'portail-entreprises'); ?></h2>
                <p class="description" style="margin-top:-6px;">
                    <?php esc_html_e('Laissez vide pour utiliser l\'adresse de facturation par défaut.', 'portail-entreprises'); ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th><label for="shipping_address_1"><?php esc_html_e('Adresse ligne 1', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="shipping_address_1" name="shipping_address_1" class="regular-text"
                                   value="<?php echo esc_attr($shipping_address['address_1'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="shipping_address_2"><?php esc_html_e('Adresse ligne 2', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="shipping_address_2" name="shipping_address_2" class="regular-text"
                                   value="<?php echo esc_attr($shipping_address['address_2'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="shipping_city"><?php esc_html_e('Ville', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="shipping_city" name="shipping_city" class="regular-text"
                                   value="<?php echo esc_attr($shipping_address['city'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="shipping_postcode"><?php esc_html_e('Code postal', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="shipping_postcode" name="shipping_postcode" class="small-text"
                                   value="<?php echo esc_attr($shipping_address['postcode'] ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="shipping_country"><?php esc_html_e('Pays', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="shipping_country" name="shipping_country" class="small-text" maxlength="2"
                                   value="<?php echo esc_attr($shipping_address['country'] ?? 'FR'); ?>" /></td>
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
                    <?php if (class_exists('TDW_B2B_Taxonomies')) :
                        $tdw_profiles        = TDW_B2B_Taxonomies::get_profiles();
                        $current_tdw_profile = $company ? (string) ($company->tdw_profile_slug ?? '') : '';
                    ?>
                    <tr>
                        <th><label for="tdw_profile_slug"><?php esc_html_e('Profil de remise TDW', 'portail-entreprises'); ?></label></th>
                        <td>
                            <select id="tdw_profile_slug" name="tdw_profile_slug">
                                <option value=""><?php esc_html_e('— Aucun profil —', 'portail-entreprises'); ?></option>
                                <?php foreach ($tdw_profiles as $tdw_p) : ?>
                                <option value="<?php echo esc_attr($tdw_p['slug']); ?>" <?php selected($current_tdw_profile, $tdw_p['slug']); ?>>
                                    <?php echo esc_html($tdw_p['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Les règles de remise du profil s\'appliquent aux utilisateurs de cette société.', 'portail-entreprises'); ?></p>
                        </td>
                    </tr>
                    <?php endif; ?>
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
                    <tr>
                        <th><label for="payment_method_code"><?php esc_html_e('Code règlement', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="payment_method_code" name="payment_method_code" class="regular-text"
                                   value="<?php echo esc_attr($company->payment_method_code ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="payment_method_label"><?php esc_html_e('Libellé règlement', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="payment_method_label" name="payment_method_label" class="regular-text"
                                   placeholder="<?php esc_attr_e('Ex. : Virement à 45 jours fin de mois', 'portail-entreprises'); ?>"
                                   value="<?php echo esc_attr($company->payment_method_label ?? ''); ?>" /></td>
                    </tr>
                </table>
            </div>

            <!-- Divers -->
            <div class="pe-form-card">
                <h2><?php esc_html_e('Divers', 'portail-entreprises'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="category"><?php esc_html_e('Catégorie', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="category" name="category" class="regular-text"
                                   placeholder="<?php esc_attr_e('Ex. : Client, Prospect…', 'portail-entreprises'); ?>"
                                   value="<?php echo esc_attr($company->category ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="activity"><?php esc_html_e('Activité', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="activity" name="activity" class="regular-text"
                                   value="<?php echo esc_attr($company->activity ?? ''); ?>" /></td>
                    </tr>
                </table>
            </div>

            <!-- Commentaires -->
            <div class="pe-form-card">
                <h2><?php esc_html_e('Commentaires', 'portail-entreprises'); ?></h2>
                <textarea name="comments" rows="4" class="large-text"><?php echo esc_textarea($company->comments ?? ''); ?></textarea>
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
            <input type="submit" class="button button-primary"
                   value="<?php echo $is_new ? esc_attr__('Créer la société', 'portail-entreprises') : esc_attr__('Enregistrer les modifications', 'portail-entreprises'); ?>" />
        </p>
    </form>

    <?php if (!$is_new) : ?>

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
        <h2 style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
            <?php esc_html_e('Utilisateurs', 'portail-entreprises'); ?>
            <?php if (!empty($company_users)) : ?>
            <button type="button" class="button pe-bulk-block-orders"
                    data-company-id="<?php echo esc_attr($company_id); ?>"
                    data-nonce="<?php echo esc_attr(wp_create_nonce('pe_b2b_ajax')); ?>"
                    style="font-weight:normal; font-size:13px;">
                <?php esc_html_e('Bloquer les commandes de tous les membres', 'portail-entreprises'); ?>
            </button>
            <?php endif; ?>
        </h2>
        <?php if (!empty($company_users)) : ?>
        <p class="description" style="margin-top:-8px;">
            <?php esc_html_e('Passe tous les membres (hors administrateurs de société) en rôle « Devis uniquement » : ils pourront toujours créer un devis, mais plus passer commande.', 'portail-entreprises'); ?>
        </p>
        <?php endif; ?>
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
                    <th><?php esc_html_e('Budget annuel', 'portail-entreprises'); ?></th>
                    <th><?php esc_html_e('Budget/commande', 'portail-entreprises'); ?></th>
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
                        <?php if ($user->budget_annual !== null) : ?>
                            <?php echo esc_html(number_format((float) $user->budget_annual, 2, ',', ' ')); ?> €
                        <?php else : ?>
                            <em><?php esc_html_e('Illimité', 'portail-entreprises'); ?></em>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($user->budget_per_order !== null) : ?>
                            <?php echo esc_html(number_format((float) $user->budget_per_order, 2, ',', ' ')); ?> €
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

        <!-- Rechercher / Inviter un utilisateur -->
        <div style="margin-top:20px; border-top:1px solid #ddd; padding-top:15px;">
            <h3 style="margin-top:0;"><?php esc_html_e('Ajouter un utilisateur', 'portail-entreprises'); ?></h3>
            <div style="margin-bottom:12px;">
                <button type="button" class="button pe-user-tab-btn pe-tab-active" data-tab="existing">
                    <?php esc_html_e('Utilisateur existant', 'portail-entreprises'); ?>
                </button>
                <button type="button" class="button pe-user-tab-btn" data-tab="invite" style="margin-left:4px;">
                    <?php esc_html_e('Inviter par e-mail', 'portail-entreprises'); ?>
                </button>
            </div>

            <!-- Tab : utilisateur existant -->
            <div id="pe-panel-existing" style="padding:15px; background:#f9f9f9; border:1px solid #ddd;">
                <table class="form-table" style="margin:0;">
                    <tr>
                        <th style="width:200px;"><?php esc_html_e('Rechercher', 'portail-entreprises'); ?></th>
                        <td style="position:relative;">
                            <input type="text" id="pe-user-search-input" class="regular-text"
                                   placeholder="<?php esc_attr_e('Nom ou adresse e-mail…', 'portail-entreprises'); ?>"
                                   autocomplete="off" />
                            <div id="pe-user-search-results"
                                 style="display:none; position:absolute; top:100%; left:0; background:#fff; border:1px solid #ddd; min-width:340px; max-height:220px; overflow-y:auto; z-index:200; box-shadow:0 2px 6px rgba(0,0,0,.15);"></div>
                            <input type="hidden" id="pe-selected-user-id" />
                            <span id="pe-selected-user-label" style="display:inline-block; margin-top:4px; color:#2271b1; font-weight:600;"></span>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Rôle', 'portail-entreprises'); ?></th>
                        <td>
                            <select id="pe-existing-role">
                                <?php foreach ($all_roles as $rk => $rl) : ?>
                                <option value="<?php echo esc_attr($rk); ?>"><?php echo esc_html($rl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Budget mensuel (€)', 'portail-entreprises'); ?></th>
                        <td><input type="number" id="pe-existing-bm" class="small-text" min="0" step="0.01"
                                   placeholder="<?php esc_attr_e('Illimité', 'portail-entreprises'); ?>" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Budget annuel (€)', 'portail-entreprises'); ?></th>
                        <td><input type="number" id="pe-existing-ba" class="small-text" min="0" step="0.01"
                                   placeholder="<?php esc_attr_e('Illimité', 'portail-entreprises'); ?>" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Budget par commande (€)', 'portail-entreprises'); ?></th>
                        <td><input type="number" id="pe-existing-bpo" class="small-text" min="0" step="0.01"
                                   placeholder="<?php esc_attr_e('Illimité', 'portail-entreprises'); ?>" /></td>
                    </tr>
                </table>
                <p style="margin-top:10px;">
                    <button type="button" class="button button-primary" id="pe-add-existing-btn"
                            data-company="<?php echo esc_attr($company_id); ?>"
                            data-nonce="<?php echo esc_attr(wp_create_nonce('pe_b2b_ajax')); ?>">
                        <?php esc_html_e('Ajouter à la société', 'portail-entreprises'); ?>
                    </button>
                    <span id="pe-add-existing-msg" style="margin-left:10px;"></span>
                </p>
            </div>

            <!-- Tab : inviter par e-mail -->
            <div id="pe-panel-invite" style="display:none; padding:15px; background:#f9f9f9; border:1px solid #ddd;">
                <table class="form-table" style="margin:0;">
                    <tr>
                        <th style="width:200px;"><label for="pe-inv-email"><?php esc_html_e('Adresse e-mail *', 'portail-entreprises'); ?></label></th>
                        <td><input type="email" id="pe-inv-email" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th><label for="pe-inv-firstname"><?php esc_html_e('Prénom', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="pe-inv-firstname" class="regular-text" /></td>
                    </tr>
                    <tr>

                        <th><label for="pe-inv-lastname"><?php esc_html_e('Nom', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="pe-inv-lastname" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Rôle', 'portail-entreprises'); ?></th>
                        <td>
                            <select id="pe-inv-role">
                                <?php foreach ($all_roles as $rk => $rl) : ?>
                                <option value="<?php echo esc_attr($rk); ?>"><?php echo esc_html($rl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Budget mensuel (€)', 'portail-entreprises'); ?></th>
                        <td><input type="number" id="pe-inv-bm" class="small-text" min="0" step="0.01"
                                   placeholder="<?php esc_attr_e('Illimité', 'portail-entreprises'); ?>" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Budget annuel (€)', 'portail-entreprises'); ?></th>
                        <td><input type="number" id="pe-inv-ba" class="small-text" min="0" step="0.01"
                                   placeholder="<?php esc_attr_e('Illimité', 'portail-entreprises'); ?>" /></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Budget par commande (€)', 'portail-entreprises'); ?></th>
                        <td><input type="number" id="pe-inv-bpo" class="small-text" min="0" step="0.01"
                                   placeholder="<?php esc_attr_e('Illimité', 'portail-entreprises'); ?>" /></td>
                    </tr>
                </table>
                <p style="margin-top:10px;">
                    <button type="button" class="button button-primary" id="pe-invite-btn"
                            data-company="<?php echo esc_attr($company_id); ?>"
                            data-nonce="<?php echo esc_attr(wp_create_nonce('pe_b2b_ajax')); ?>">
                        <?php esc_html_e('Inviter et envoyer l\'e-mail', 'portail-entreprises'); ?>
                    </button>
                    <span id="pe-invite-msg" style="margin-left:10px;"></span>
                </p>
            </div>
        </div>

        <script>
        jQuery(function($) {
            // Tab switching
            $('.pe-user-tab-btn').on('click', function() {
                var tab = $(this).data('tab');
                $('.pe-user-tab-btn').removeClass('pe-tab-active button-primary');
                $(this).addClass('pe-tab-active button-primary');
                $('#pe-panel-existing, #pe-panel-invite').hide();
                $('#pe-panel-' + tab).show();
            }).first().trigger('click');

            // User search
            var searchTimer;
            var companyId = <?php echo (int) $company_id; ?>;
            var ajaxNonce = '<?php echo esc_js(wp_create_nonce('pe_b2b_ajax')); ?>';

            $('#pe-user-search-input').on('keyup', function() {
                clearTimeout(searchTimer);
                var q = $(this).val().trim();
                if (q.length < 2) { $('#pe-user-search-results').hide().empty(); return; }
                searchTimer = setTimeout(function() {
                    $.post(ajaxurl, { action: 'pe_admin_search_users', nonce: ajaxNonce, search: q, company_id: companyId }, function(res) {
                        var $list = $('#pe-user-search-results').empty();
                        if (res.success && res.data.users.length) {
                            $.each(res.data.users, function(i, u) {
                                $('<div>').text(u.name + ' — ' + u.email)
                                    .css({ padding: '7px 12px', cursor: 'pointer', borderBottom: '1px solid #f0f0f0' })
                                    .on('mouseenter', function() { $(this).css('background', '#f0f6ff'); })
                                    .on('mouseleave', function() { $(this).css('background', ''); })
                                    .on('click', function() {
                                        $('#pe-selected-user-id').val(u.id);
                                        $('#pe-selected-user-label').text(u.name + ' (' + u.email + ')');
                                        $('#pe-user-search-input').val('');
                                        $list.hide().empty();
                                    }).appendTo($list);
                            });
                            $list.show();
                        } else {
                            $list.append($('<div>').text('<?php echo esc_js(__('Aucun résultat.', 'portail-entreprises')); ?>').css({ padding: '7px 12px', color: '#888' })).show();
                        }
                    });
                }, 300);
            });

            $(document).on('click', function(e) {
                if (!$(e.target).closest('#pe-user-search-input, #pe-user-search-results').length) {
                    $('#pe-user-search-results').hide();
                }
            });

            // Add existing user
            $('#pe-add-existing-btn').on('click', function() {
                var userId = $('#pe-selected-user-id').val();
                if (!userId) {
                    $('#pe-add-existing-msg').css('color', '#b32d2e').text('<?php echo esc_js(__('Sélectionnez d\'abord un utilisateur.', 'portail-entreprises')); ?>');
                    return;
                }
                var $btn = $(this).prop('disabled', true).text('<?php echo esc_js(__('Ajout en cours…', 'portail-entreprises')); ?>');
                $.post(ajaxurl, {
                    action: 'pe_admin_add_user_to_company',
                    nonce: ajaxNonce,
                    user_id: userId,
                    company_id: companyId,
                    role: $('#pe-existing-role').val(),
                    budget_monthly: $('#pe-existing-bm').val(),
                    budget_annual: $('#pe-existing-ba').val(),
                    budget_per_order: $('#pe-existing-bpo').val()
                }, function(res) {
                    if (res.success) {
                        $('#pe-add-existing-msg').css('color', '#28a745').text(res.data.message);
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        $('#pe-add-existing-msg').css('color', '#b32d2e').text(res.data.message);
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Ajouter à la société', 'portail-entreprises')); ?>');
                    }
                });
            });

            // Invite by email
            $('#pe-invite-btn').on('click', function() {
                var email = $('#pe-inv-email').val().trim();
                if (!email) {
                    $('#pe-invite-msg').css('color', '#b32d2e').text('<?php echo esc_js(__('L\'adresse e-mail est requise.', 'portail-entreprises')); ?>');
                    return;
                }
                var $btn = $(this).prop('disabled', true).text('<?php echo esc_js(__('Envoi en cours…', 'portail-entreprises')); ?>');
                $.post(ajaxurl, {
                    action: 'pe_admin_invite_user_to_company',
                    nonce: ajaxNonce,
                    company_id: companyId,
                    email: email,
                    first_name: $('#pe-inv-firstname').val(),
                    last_name: $('#pe-inv-lastname').val(),
                    role: $('#pe-inv-role').val(),
                    budget_monthly: $('#pe-inv-bm').val(),
                    budget_annual: $('#pe-inv-ba').val(),
                    budget_per_order: $('#pe-inv-bpo').val()
                }, function(res) {
                    if (res.success) {
                        $('#pe-invite-msg').css('color', '#28a745').text(res.data.message);
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        $('#pe-invite-msg').css('color', '#b32d2e').text(res.data.message);
                        $btn.prop('disabled', false).text('<?php echo esc_js(__('Inviter et envoyer l\'e-mail', 'portail-entreprises')); ?>');
                    }
                });
            });
        });
        </script>
    </div>

    <!-- Dates dernières pièces -->
    <div class="pe-form-card" style="margin-top:20px;">
        <h2><?php esc_html_e('Dates dernières pièces', 'portail-entreprises'); ?></h2>
        <?php
        $last_order_date = $manager->get_last_order_date($company_id);
        $last_quote_date = $manager->get_last_quote_date($company_id);
        ?>
        <table class="form-table" style="margin:0;">
            <tr>
                <th style="width:200px;"><?php esc_html_e('Dernier devis', 'portail-entreprises'); ?></th>
                <td>
                    <?php if ($last_quote_date) : ?>
                        <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($last_quote_date))); ?>
                    <?php else : ?>
                        <em><?php esc_html_e('Aucun devis trouvé.', 'portail-entreprises'); ?></em>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Dernière commande', 'portail-entreprises'); ?></th>
                <td>
                    <?php if ($last_order_date) : ?>
                        <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($last_order_date))); ?>
                    <?php else : ?>
                        <em><?php esc_html_e('Aucune commande trouvée.', 'portail-entreprises'); ?></em>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>

    <!-- Commandes associées -->
    <div class="pe-form-card" style="margin-top:20px;">
        <h2><?php esc_html_e('Commandes associées', 'portail-entreprises'); ?></h2>
        <?php
        $company_orders = (class_exists('PE_Core') && function_exists('wc_get_order'))
            ? PE_Core::get_company_orders($company_id, 20)
            : [];
        ?>
        <?php if (empty($company_orders)) : ?>
            <p><?php esc_html_e('Aucune commande trouvée pour cette société.', 'portail-entreprises'); ?></p>
        <?php else : ?>
        <div style="max-height:380px; overflow-y:auto; border:1px solid #e2e4e7; border-radius:4px;">
        <table class="wp-list-table widefat fixed striped" style="margin:0; border:none;">
            <thead style="position:sticky; top:0; background:#f9f9f9; z-index:1;">
                <tr>
                    <th style="width:100px;"><?php esc_html_e('Commande', 'portail-entreprises'); ?></th>
                    <th><?php esc_html_e('Client', 'portail-entreprises'); ?></th>
                    <th style="width:120px;"><?php esc_html_e('Date', 'portail-entreprises'); ?></th>
                    <th style="width:130px;"><?php esc_html_e('Statut', 'portail-entreprises'); ?></th>
                    <th style="width:100px;"><?php esc_html_e('Total', 'portail-entreprises'); ?></th>
                    <th style="width:80px;"><?php esc_html_e('Actions', 'portail-entreprises'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($company_orders as $order) : ?>
                <tr>
                    <td><strong>#<?php echo esc_html($order->get_order_number()); ?></strong></td>
                    <td><?php echo esc_html($order->get_formatted_billing_full_name()); ?></td>
                    <td><?php echo esc_html(date_i18n(get_option('date_format'), $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0)); ?></td>
                    <td><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td>
                    <td><?php echo wp_kses_post(wc_price($order->get_total())); ?></td>
                    <td>
                        <a href="<?php echo esc_url($order->get_edit_order_url()); ?>" class="button button-small">
                            <?php esc_html_e('Voir', 'portail-entreprises'); ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if (20 === count($company_orders)) : ?>
        <p style="margin-top:8px;">
            <a href="<?php echo esc_url(admin_url('edit.php?post_type=shop_order')); ?>">
                <?php esc_html_e('Voir toutes les commandes →', 'portail-entreprises'); ?>
            </a>
        </p>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Journal d'activité -->
    <div class="pe-form-card" style="margin-top:20px;">
        <h2><?php esc_html_e('Journal d\'activité', 'portail-entreprises'); ?></h2>
        <?php
        $audit_logs = PE_Audit_Log::get_instance()->get_logs([
            'company_id' => $company_id,
            'per_page'   => 25,
        ]);
        $audit_action_labels = [
            'create_company'           => __('Société créée', 'portail-entreprises'),
            'update_company'           => __('Société modifiée', 'portail-entreprises'),
            'delete_company'           => __('Société supprimée', 'portail-entreprises'),
            'add_user_to_company'      => __('Utilisateur ajouté', 'portail-entreprises'),
            'remove_user_from_company' => __('Utilisateur retiré', 'portail-entreprises'),
            'create_agency'            => __('Agence créée', 'portail-entreprises'),
            'create_sub_account'       => __('Invitation envoyée', 'portail-entreprises'),
            'suspend_user'             => __('Utilisateur suspendu', 'portail-entreprises'),
            'reactivate_user'          => __('Utilisateur réactivé', 'portail-entreprises'),
            'enable_b2b'               => __('B2B activé', 'portail-entreprises'),
            'disable_b2b'              => __('B2B désactivé', 'portail-entreprises'),
            'save_approval_rule'       => __('Règle d\'approbation modifiée', 'portail-entreprises'),
        ];
        ?>
        <?php
        $audit_field_labels = [
            'name'                  => __('Nom', 'portail-entreprises'),
            'customer_code'         => __('Code client', 'portail-entreprises'),
            'siret'                 => __('SIRET', 'portail-entreprises'),
            'vat_number'            => __('N° TVA', 'portail-entreprises'),
            'naf_code'              => __('Code NAF', 'portail-entreprises'),
            'phone'                 => __('Téléphone société', 'portail-entreprises'),
            'fax'                   => __('Fax société', 'portail-entreprises'),
            'contact_function'      => __('Fonction du contact', 'portail-entreprises'),
            'contact_first_name'    => __('Prénom du contact', 'portail-entreprises'),
            'contact_last_name'     => __('Nom du contact', 'portail-entreprises'),
            'payment_method_code'   => __('Code règlement', 'portail-entreprises'),
            'payment_method_label'  => __('Libellé règlement', 'portail-entreprises'),
            'category'              => __('Catégorie', 'portail-entreprises'),
            'activity'              => __('Activité', 'portail-entreprises'),
            'comments'              => __('Commentaires', 'portail-entreprises'),
            'billing_address'       => __('Adresse de facturation', 'portail-entreprises'),
            'shipping_address'      => __('Adresse de livraison', 'portail-entreprises'),
            'discount_rate'         => __('Remise', 'portail-entreprises'),
            'credit_limit'          => __('Limite de crédit', 'portail-entreprises'),
            'payment_terms'         => __('Délai de paiement', 'portail-entreprises'),
            'assigned_rep_id'       => __('Commercial', 'portail-entreprises'),
            'status'                => __('Statut', 'portail-entreprises'),
            'modules_enabled'       => __('Modules', 'portail-entreprises'),
            'tdw_profile_slug'      => __('Profil TDW', 'portail-entreprises'),
        ];
        $audit_status_labels = [
            'active'    => __('Actif', 'portail-entreprises'),
            'suspended' => __('Suspendu', 'portail-entreprises'),
        ];
        ?>
        <?php if (empty($audit_logs)) : ?>
            <p><?php esc_html_e('Aucune activité enregistrée pour cette société.', 'portail-entreprises'); ?></p>
        <?php else : ?>
        <div style="max-height:420px; overflow-y:auto; border:1px solid #e2e4e7; border-radius:4px;">
        <table class="wp-list-table widefat fixed striped" style="font-size:13px; margin:0; border:none;">
            <thead style="position:sticky; top:0; background:#f9f9f9; z-index:1;">
                <tr>
                    <th style="width:150px;"><?php esc_html_e('Date', 'portail-entreprises'); ?></th>
                    <th style="width:150px;"><?php esc_html_e('Utilisateur', 'portail-entreprises'); ?></th>
                    <th><?php esc_html_e('Action', 'portail-entreprises'); ?></th>
                    <th style="width:120px;"><?php esc_html_e('Adresse IP', 'portail-entreprises'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($audit_logs as $log_entry) : ?>
                <?php
                $log_user   = $log_entry->user_id ? get_userdata((int) $log_entry->user_id) : null;
                $log_data   = $log_entry->data ? (array) json_decode($log_entry->data, true) : [];
                $action_lbl = $audit_action_labels[$log_entry->action] ?? esc_html($log_entry->action);
                $details    = [];

                if ($log_entry->action === 'update_company' && !empty($log_data)) {
                    foreach ($log_data as $field => $change) {
                        $label   = $audit_field_labels[$field] ?? $field;
                        $is_diff = is_array($change) && array_key_exists('from', $change) && array_key_exists('to', $change);
                        $from    = $is_diff ? $change['from'] : null;
                        $to      = $is_diff ? $change['to'] : $change;

                        if ($field === 'billing_address' || $field === 'shipping_address') {
                            $details[] = $label . ' modifiée';
                        } elseif ($field === 'modules_enabled') {
                            $new_mods = is_string($to) ? (array) json_decode((string) $to, true) : (array) $to;
                            if ($is_diff) {
                                $old_mods  = is_string($from) ? (array) json_decode((string) $from, true) : (array) $from;
                                $details[] = $label . ' : ' . (empty($old_mods) ? __('aucun', 'portail-entreprises') : implode(', ', $old_mods)) . ' → ' . (empty($new_mods) ? __('aucun', 'portail-entreprises') : implode(', ', $new_mods));
                            } else {
                                $details[] = $label . ' : ' . (empty($new_mods) ? __('aucun', 'portail-entreprises') : implode(', ', $new_mods));
                            }
                        } elseif ($field === 'status') {
                            $lbl_to = $audit_status_labels[$to] ?? $to;
                            if ($is_diff && $from !== '') {
                                $details[] = $label . ' : ' . ($audit_status_labels[$from] ?? $from) . ' → ' . $lbl_to;
                            } else {
                                $details[] = $label . ' → ' . $lbl_to;
                            }
                        } elseif ($field === 'discount_rate') {
                            if ($is_diff && $from !== '') {
                                $details[] = $label . ' : ' . $from . '% → ' . $to . '%';
                            } else {
                                $details[] = $label . ' → ' . $to . '%';
                            }
                        } elseif ($field === 'credit_limit') {
                            $fmt_to = number_format((float) $to, 2, ',', ' ') . ' €';
                            if ($is_diff && $from !== '') {
                                $details[] = $label . ' : ' . number_format((float) $from, 2, ',', ' ') . ' € → ' . $fmt_to;
                            } else {
                                $details[] = $label . ' → ' . $fmt_to;
                            }
                        } elseif ($field === 'payment_terms') {
                            if ($is_diff && $from !== '') {
                                $details[] = $label . ' : ' . $from . ' → ' . $to . ' jours';
                            } else {
                                $details[] = $label . ' → ' . $to . ' jours';
                            }
                        } elseif ($field === 'assigned_rep_id') {
                            $fmt_rep = function($v) {
                                if (empty($v)) {
                                    return __('non assigné', 'portail-entreprises');
                                }
                                $u = get_userdata((int) $v);
                                return $u ? $u->display_name : '#' . $v;
                            };
                            if ($is_diff && $from !== '') {
                                $details[] = $label . ' : ' . $fmt_rep($from) . ' → ' . $fmt_rep($to);
                            } else {
                                $details[] = $label . ' → ' . $fmt_rep($to);
                            }
                        } else {
                            if ($is_diff && (string) $from !== '') {
                                $details[] = $label . ' : ' . $from . ' → ' . $to;
                            } else {
                                $details[] = $label . ' → ' . $to;
                            }
                        }
                    }
                } elseif (!empty($log_data['email'])) {
                    $details[] = $log_data['email'];
                    if (!empty($log_data['role']) && isset($all_roles[$log_data['role']])) {
                        $details[] = $all_roles[$log_data['role']];
                    }
                }
                ?>
                <tr>
                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' H:i', strtotime($log_entry->created_at))); ?></td>
                    <td>
                        <?php if ($log_user) : ?>
                            <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . (int) $log_entry->user_id)); ?>">
                                <?php echo esc_html($log_user->display_name); ?>
                            </a>
                        <?php else : ?>
                            <em><?php esc_html_e('Système', 'portail-entreprises'); ?></em>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo esc_html($action_lbl); ?>
                        <?php if (!empty($details)) : ?>
                            <small style="color:#666;"> — <?php echo esc_html(implode(' | ', $details)); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($log_entry->ip_address ?: '—'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <?php endif; // !$is_new ?>
</div>

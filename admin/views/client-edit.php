<?php
/**
 * Vue : édition/création d'un client B2B (identité indépendante des sociétés).
 */

defined('ABSPATH') || exit;

$manager   = PE_Client_Manager::get_instance();
$client_id = isset($_GET['client_id']) ? absint($_GET['client_id']) : 0;
$is_new    = 0 === $client_id;
$client    = $is_new ? null : $manager->get_client($client_id);

if (!$is_new && !$client) {
    wp_die(esc_html__('Client introuvable.', 'portail-entreprises'));
}

$billing_address  = $client ? (array) json_decode($client->billing_address ?? '', true) : [];
$shipping_address = $client ? (array) json_decode($client->shipping_address ?? '', true) : [];
$client_companies = $is_new ? [] : $manager->get_client_companies($client_id);

$nonce_action = $is_new ? 'pe_create_client' : 'pe_save_client_' . $client_id;
$form_action  = $is_new ? 'pe_create_client' : 'pe_update_client';
?>
<div class="wrap pe-admin-wrap">
    <?php
    $pe_notice = isset($_GET['pe_notice']) ? sanitize_key($_GET['pe_notice']) : '';
    $pe_error  = isset($_GET['pe_error']) ? sanitize_text_field(wp_unslash($_GET['pe_error'])) : '';
    if ('created' === $pe_notice) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Client créé avec succès.', 'portail-entreprises'); ?></p></div>
    <?php elseif ('updated' === $pe_notice) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Client mis à jour avec succès.', 'portail-entreprises'); ?></p></div>
    <?php elseif ($pe_error) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($pe_error); ?></p></div>
    <?php endif; ?>

    <h1>
        <?php if ($is_new) : ?>
            <?php esc_html_e('Créer un client', 'portail-entreprises'); ?>
        <?php else : ?>
            <?php echo esc_html(sprintf(__('Modifier : %s', 'portail-entreprises'), $client->name ?? '')); ?>
        <?php endif; ?>
    </h1>
    <a href="<?php echo esc_url(admin_url('admin.php?page=portail-b2b-clients')); ?>" class="button">
        &larr; <?php esc_html_e('Retour à la liste', 'portail-entreprises'); ?>
    </a>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pe-company-form">
        <?php wp_nonce_field($nonce_action, '_wpnonce'); ?>
        <input type="hidden" name="action" value="<?php echo esc_attr($form_action); ?>" />
        <?php if (!$is_new) : ?>
        <input type="hidden" name="client_id" value="<?php echo esc_attr($client_id); ?>" />
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
                                   value="<?php echo esc_attr($client->customer_code ?? ''); ?>" />
                            <p class="description">
                                <?php esc_html_e('Identifiant interne, utile lorsque ce client possède plusieurs sociétés.', 'portail-entreprises'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="client_name"><?php esc_html_e('Libellé client *', 'portail-entreprises'); ?></label></th>
                        <td>
                            <input type="text" id="client_name" name="client_name" class="regular-text" required
                                   value="<?php echo esc_attr($client->name ?? ''); ?>" />
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Éléments de communication -->
            <div class="pe-form-card">
                <h2><?php esc_html_e('Éléments de communication', 'portail-entreprises'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="phone"><?php esc_html_e('Téléphone', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="phone" name="phone" class="regular-text"
                                   value="<?php echo esc_attr($client->phone ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="fax"><?php esc_html_e('Fax', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="fax" name="fax" class="regular-text"
                                   value="<?php echo esc_attr($client->fax ?? ''); ?>" /></td>
                    </tr>
                </table>
            </div>

            <!-- Contact principal -->
            <div class="pe-form-card">
                <h2><?php esc_html_e('Contact', 'portail-entreprises'); ?></h2>
                <p class="description" style="margin-top:-6px;">
                    <?php esc_html_e('Contact principal du client.', 'portail-entreprises'); ?>
                </p>
                <table class="form-table">
                    <tr>
                        <th><label for="contact_function"><?php esc_html_e('Fonction', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="contact_function" name="contact_function" class="regular-text"
                                   value="<?php echo esc_attr($client->contact_function ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="contact_last_name"><?php esc_html_e('Nom', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="contact_last_name" name="contact_last_name" class="regular-text"
                                   value="<?php echo esc_attr($client->contact_last_name ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="contact_first_name"><?php esc_html_e('Prénom', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="contact_first_name" name="contact_first_name" class="regular-text"
                                   value="<?php echo esc_attr($client->contact_first_name ?? ''); ?>" /></td>
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
                                   value="<?php echo esc_attr($client->discount_rate ?? '0'); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="credit_limit"><?php esc_html_e('Plafond de crédit (€)', 'portail-entreprises'); ?></label></th>
                        <td><input type="number" id="credit_limit" name="credit_limit" class="regular-text"
                                   min="0" step="0.01"
                                   value="<?php echo esc_attr($client->credit_limit ?? '0'); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="payment_terms"><?php esc_html_e('Délai de paiement (jours)', 'portail-entreprises'); ?></label></th>
                        <td><input type="number" id="payment_terms" name="payment_terms" class="small-text"
                                   min="0" max="365"
                                   value="<?php echo esc_attr($client->payment_terms ?? '30'); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="payment_method_code"><?php esc_html_e('Code règlement', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="payment_method_code" name="payment_method_code" class="regular-text"
                                   value="<?php echo esc_attr($client->payment_method_code ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="payment_method_label"><?php esc_html_e('Libellé règlement', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="payment_method_label" name="payment_method_label" class="regular-text"
                                   placeholder="<?php esc_attr_e('Ex. : Virement à 45 jours fin de mois', 'portail-entreprises'); ?>"
                                   value="<?php echo esc_attr($client->payment_method_label ?? ''); ?>" /></td>
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
                                   value="<?php echo esc_attr($client->category ?? ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="activity"><?php esc_html_e('Activité', 'portail-entreprises'); ?></label></th>
                        <td><input type="text" id="activity" name="activity" class="regular-text"
                                   value="<?php echo esc_attr($client->activity ?? ''); ?>" /></td>
                    </tr>
                </table>
            </div>

            <!-- Commentaires -->
            <div class="pe-form-card">
                <h2><?php esc_html_e('Commentaires', 'portail-entreprises'); ?></h2>
                <textarea name="comments" rows="4" class="large-text"><?php echo esc_textarea($client->comments ?? ''); ?></textarea>
            </div>
        </div>

        <p class="submit">
            <input type="submit" class="button button-primary"
                   value="<?php echo $is_new ? esc_attr__('Créer le client', 'portail-entreprises') : esc_attr__('Enregistrer les modifications', 'portail-entreprises'); ?>" />
        </p>
    </form>

    <?php if (!$is_new) : ?>
    <!-- Sociétés rattachées -->
    <div class="pe-form-card" style="margin-top:20px;">
        <h2><?php esc_html_e('Sociétés rattachées', 'portail-entreprises'); ?></h2>
        <?php if (empty($client_companies)) : ?>
            <p><?php esc_html_e('Ce client n\'a pas encore de société.', 'portail-entreprises'); ?></p>
        <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Nom', 'portail-entreprises'); ?></th>
                    <th><?php esc_html_e('SIRET', 'portail-entreprises'); ?></th>
                    <th><?php esc_html_e('Statut', 'portail-entreprises'); ?></th>
                    <th><?php esc_html_e('Actions', 'portail-entreprises'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($client_companies as $co) : ?>
                <tr>
                    <td><strong><?php echo esc_html($co->name); ?></strong></td>
                    <td><?php echo esc_html($co->siret ?: '—'); ?></td>
                    <td>
                        <?php if ('active' === $co->status) : ?>
                            <span class="pe-badge pe-badge-active"><?php esc_html_e('Actif', 'portail-entreprises'); ?></span>
                        <?php else : ?>
                            <span class="pe-badge pe-badge-suspended"><?php esc_html_e('Suspendu', 'portail-entreprises'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=portail-b2b&action=edit&company_id=' . (int) $co->id)); ?>"
                           class="button button-small">
                            <?php esc_html_e('Modifier', 'portail-entreprises'); ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <p style="margin-top:15px;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=portail-b2b&action=new&client_id=' . $client_id)); ?>" class="button button-primary">
                <?php esc_html_e('+ Nouvelle société pour ce client', 'portail-entreprises'); ?>
            </a>
            <?php if (empty($client_companies)) : ?>
            <button type="button" class="button pe-delete-client" style="margin-left:8px;color:#b32d2e;border-color:#b32d2e;"
                    data-client-id="<?php echo esc_attr($client_id); ?>"
                    data-client-name="<?php echo esc_attr($client->name); ?>"
                    data-nonce="<?php echo esc_attr(wp_create_nonce('pe_b2b_ajax')); ?>">
                <?php esc_html_e('Supprimer ce client', 'portail-entreprises'); ?>
            </button>
            <?php endif; ?>
        </p>
    </div>

    <script>
    jQuery(function($) {
        $('.pe-delete-client').on('click', function() {
            var $btn = $(this);
            var name = $btn.data('client-name');
            if (!confirm('<?php echo esc_js(__('Êtes-vous sûr de vouloir supprimer le client', 'portail-entreprises')); ?> « ' + name + ' » ?')) {
                return;
            }
            $btn.prop('disabled', true);
            $.post(ajaxurl, {
                action: 'pe_admin_delete_client',
                nonce: $btn.data('nonce'),
                client_id: $btn.data('client-id')
            }, function(res) {
                if (res.success) {
                    window.location.href = res.data.redirect;
                } else {
                    alert(res.data.message);
                    $btn.prop('disabled', false);
                }
            });
        });
    });
    </script>
    <?php endif; ?>
</div>

<?php
/**
 * Vue : liste des entreprises B2B.
 */

defined('ABSPATH') || exit;

$manager    = PE_Company_Manager::get_instance();
$per_page   = 20;
$current    = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
$search     = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
$status_flt = isset($_GET['status_filter']) ? sanitize_key($_GET['status_filter']) : '';

$args = [
    'per_page' => $per_page,
    'page'     => $current,
    'search'   => $search,
    'status'   => $status_flt,
];

$companies = $manager->get_all_companies($args);
$total     = $manager->count_companies($args);
$pages     = ceil($total / $per_page);

settings_errors('pe_messages');
?>
<div class="wrap pe-admin-wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Sociétés B2B', 'portail-entreprises'); ?></h1>
    <a href="<?php echo esc_url(admin_url('admin.php?page=portail-b2b&action=new')); ?>" class="page-title-action">
        <?php esc_html_e('Ajouter une société', 'portail-entreprises'); ?>
    </a>
    <hr class="wp-header-end">

    <form method="get" action="">
        <input type="hidden" name="page" value="portail-b2b" />
        <p class="search-box">
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>"
                   placeholder="<?php esc_attr_e('Rechercher par nom ou SIRET…', 'portail-entreprises'); ?>" />
            <select name="status_filter">
                <option value=""><?php esc_html_e('Tous les statuts', 'portail-entreprises'); ?></option>
                <option value="active" <?php selected($status_flt, 'active'); ?>><?php esc_html_e('Actif', 'portail-entreprises'); ?></option>
                <option value="suspended" <?php selected($status_flt, 'suspended'); ?>><?php esc_html_e('Suspendu', 'portail-entreprises'); ?></option>
            </select>
            <?php submit_button(__('Rechercher', 'portail-entreprises'), 'button', false, false); ?>
        </p>
    </form>

    <?php if (empty($companies)) : ?>
        <p><?php esc_html_e('Aucune société trouvée.', 'portail-entreprises'); ?></p>
    <?php else : ?>
    <table class="wp-list-table widefat fixed striped pe-companies-table">
        <thead>
            <tr>
                <th class="column-name"><?php esc_html_e('Nom', 'portail-entreprises'); ?></th>
                <th class="column-siret"><?php esc_html_e('SIRET', 'portail-entreprises'); ?></th>
                <th class="column-users"><?php esc_html_e('Utilisateurs', 'portail-entreprises'); ?></th>
                <th class="column-status"><?php esc_html_e('Statut', 'portail-entreprises'); ?></th>
                <th class="column-created"><?php esc_html_e('Créé le', 'portail-entreprises'); ?></th>
                <th class="column-actions"><?php esc_html_e('Actions', 'portail-entreprises'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($companies as $company) : ?>
            <tr>
                <td class="column-name">
                    <strong>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=portail-b2b&action=edit&company_id=' . (int) $company->id)); ?>">
                            <?php echo esc_html($company->name); ?>
                        </a>
                    </strong>
                </td>
                <td class="column-siret"><?php echo esc_html($company->siret ?: '—'); ?></td>
                <td class="column-users"><?php echo esc_html($company->user_count ?? 0); ?></td>
                <td class="column-status">
                    <?php if ('active' === $company->status) : ?>
                        <span class="pe-badge pe-badge-active"><?php esc_html_e('Actif', 'portail-entreprises'); ?></span>
                    <?php else : ?>
                        <span class="pe-badge pe-badge-suspended"><?php esc_html_e('Suspendu', 'portail-entreprises'); ?></span>
                    <?php endif; ?>
                </td>
                <td class="column-created">
                    <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($company->created_at))); ?>
                </td>
                <td class="column-actions">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=portail-b2b&action=edit&company_id=' . (int) $company->id)); ?>"
                       class="button button-small">
                        <?php esc_html_e('Modifier', 'portail-entreprises'); ?>
                    </a>
                    <button type="button" class="button button-small pe-delete-company"
                            data-company-id="<?php echo esc_attr($company->id); ?>"
                            data-company-name="<?php echo esc_attr($company->name); ?>"
                            data-nonce="<?php echo esc_attr(wp_create_nonce('pe_b2b_ajax')); ?>"
                            style="color:#b32d2e;border-color:#b32d2e;">
                        <?php esc_html_e('Supprimer', 'portail-entreprises'); ?>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1) : ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php
            $base_url = admin_url('admin.php?page=portail-b2b');
            if ($search) {
                $base_url = add_query_arg('s', urlencode($search), $base_url);
            }
            if ($status_flt) {
                $base_url = add_query_arg('status_filter', $status_flt, $base_url);
            }

            echo paginate_links([
                'base'    => $base_url . '%_%',
                'format'  => '&paged=%#%',
                'current' => $current,
                'total'   => $pages,
            ]);
            ?>
        </div>
        <div class="displaying-num">
            <?php
            printf(
                /* translators: %d: number of items */
                esc_html(_n('%d société', '%d sociétés', $total, 'portail-entreprises')),
                (int) $total
            );
            ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php
/**
 * Vue : liste des clients B2B (identité indépendante des sociétés).
 */

defined('ABSPATH') || exit;

$manager    = PE_Client_Manager::get_instance();
$per_page   = 20;
$current    = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
$search     = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

$args = [
    'per_page' => $per_page,
    'page'     => $current,
    'search'   => $search,
];

$clients = $manager->get_all_clients($args);
$total   = $manager->count_clients($args);
$pages   = ceil($total / $per_page);

settings_errors('pe_messages');
?>
<div class="wrap pe-admin-wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Clients', 'portail-entreprises'); ?></h1>
    <a href="<?php echo esc_url(admin_url('admin.php?page=portail-b2b-clients&action=new')); ?>" class="page-title-action">
        <?php esc_html_e('Ajouter un client', 'portail-entreprises'); ?>
    </a>
    <hr class="wp-header-end">

    <p class="description">
        <?php esc_html_e('Un client peut être créé ici avant même d\'avoir une société : vous pourrez ensuite le sélectionner pour créer et lui rattacher une société.', 'portail-entreprises'); ?>
    </p>

    <form method="get" action="">
        <input type="hidden" name="page" value="portail-b2b-clients" />
        <p class="search-box">
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>"
                   placeholder="<?php esc_attr_e('Rechercher par nom ou code client…', 'portail-entreprises'); ?>" />
            <?php submit_button(__('Rechercher', 'portail-entreprises'), 'button', false, false); ?>
        </p>
    </form>

    <?php if (empty($clients)) : ?>
        <p><?php esc_html_e('Aucun client trouvé.', 'portail-entreprises'); ?></p>
    <?php else : ?>
    <table class="wp-list-table widefat fixed striped pe-companies-table">
        <thead>
            <tr>
                <th class="column-name"><?php esc_html_e('Libellé client', 'portail-entreprises'); ?></th>
                <th class="column-customer-code"><?php esc_html_e('Code client', 'portail-entreprises'); ?></th>
                <th class="column-companies"><?php esc_html_e('Sociétés', 'portail-entreprises'); ?></th>
                <th class="column-created"><?php esc_html_e('Créé le', 'portail-entreprises'); ?></th>
                <th class="column-actions"><?php esc_html_e('Actions', 'portail-entreprises'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($clients as $client) : ?>
            <tr>
                <td class="column-name">
                    <strong>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=portail-b2b-clients&action=edit&client_id=' . (int) $client->id)); ?>">
                            <?php echo esc_html($client->name); ?>
                        </a>
                    </strong>
                </td>
                <td class="column-customer-code"><?php echo esc_html($client->customer_code ?: '—'); ?></td>
                <td class="column-companies">
                    <?php if ((int) $client->company_count > 0) : ?>
                        <?php echo esc_html((string) $client->company_count); ?>
                    <?php else : ?>
                        <em style="color:#b32d2e;"><?php esc_html_e('Aucune', 'portail-entreprises'); ?></em>
                    <?php endif; ?>
                </td>
                <td class="column-created">
                    <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($client->created_at))); ?>
                </td>
                <td class="column-actions">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=portail-b2b-clients&action=edit&client_id=' . (int) $client->id)); ?>"
                       class="button button-small">
                        <?php esc_html_e('Modifier', 'portail-entreprises'); ?>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=portail-b2b&action=new&client_id=' . (int) $client->id)); ?>"
                       class="button button-small">
                        <?php esc_html_e('+ Société', 'portail-entreprises'); ?>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($pages > 1) : ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php
            $base_url = admin_url('admin.php?page=portail-b2b-clients');
            if ($search) {
                $base_url = add_query_arg('s', urlencode($search), $base_url);
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
                esc_html(_n('%d client', '%d clients', $total, 'portail-entreprises')),
                (int) $total
            );
            ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php

/*
 * This file is part of the Shop emails plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\shopemails\Admin\Screens;
use mindstellar\shopemails\Newsletter;
use mindstellar\shopemails\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}
if (!Screens::allowed()) {
    osc_admin_empty(array('icon' => 'bi-shield-lock', 'title' => __('Only an administrator can manage the newsletter list.', 'shop-emails')));

    return;
}

$seStore  = Plugin::store();
$seQuery  = strtolower(trim(Params::getParamString('q')));
$seAfter  = Params::getParamString('after');
$sePage   = Newsletter::page($seStore, $seQuery, $seAfter !== '' ? $seAfter : null, Screens::PER_PAGE);
$seCounts = Newsletter::counts($seStore);
$seList   = osc_route_admin_url(Screens::ROUTE);
$seLink   = static function (array $args) use ($seList): string {
    $args = array_filter($args, static fn ($v): bool => $v !== '' && $v !== null);

    return $seList . ($args === array() ? '' : '&' . http_build_query($args, '', '&', PHP_QUERY_RFC3986));
};

osc_admin_page_head(__('Newsletter subscribers', 'shop-emails'));
?>
<p class="text-muted">
    <?php echo osc_admin_count($seCounts['confirmed']); ?> <?php _e('confirmed', 'shop-emails'); ?>
    &middot;
    <?php echo osc_admin_count($seCounts['pending']); ?> <?php _e('waiting for their confirm link', 'shop-emails'); ?>
</p>
<?php
osc_admin_toolbar_open();
osc_admin_list_filter(array(
    'page'   => 'plugins',
    'hidden' => array('action' => 'renderplugin', 'route' => Screens::ROUTE),
    'fields' => array(array(
        'type'        => 'search',
        'name'        => 'q',
        'value'       => $seQuery,
        'placeholder' => __('Address starts with', 'shop-emails'),
        'id'          => 'se-q',
    )),
    'active' => $seQuery !== '',
    'reset'  => $seList,
));
?>
<form method="post" action="<?php echo osc_esc_html($seList); ?>">
    <input type="hidden" name="se_do" value="export">
    <button type="submit" class="btn btn-sm btn-dim"<?php echo $seCounts['confirmed'] === 0 ? ' disabled' : ''; ?>>
        <i class="bi bi-download" aria-hidden="true"></i> <?php _e('Export CSV', 'shop-emails'); ?>
    </button>
</form>
<?php osc_admin_toolbar_close(); ?>
<div class="table-contains-actions osc-table-stack">
    <table class="table">
        <thead>
        <tr>
            <th><?php _e('E-mail', 'shop-emails'); ?></th>
            <th><?php _e('Status', 'shop-emails'); ?></th>
            <th><?php _e('Signed up', 'shop-emails'); ?></th>
            <th><?php _e('From', 'shop-emails'); ?></th>
        </tr>
        </thead>
        <tbody>
        <?php if ($sePage['rows'] === array()) {
            osc_admin_table_empty(4, array(
                'icon'  => 'bi-envelope',
                'title' => $seQuery !== '' ? __('No address starts with that', 'shop-emails') : __('No one has signed up yet', 'shop-emails'),
                'text'  => $seQuery !== '' ? '' : __('Addresses appear here when visitors use the newsletter form on your home page.', 'shop-emails'),
            ));
        }
        foreach ($sePage['rows'] as $seRow) { ?>
            <tr>
                <td data-col-name="<?php echo osc_esc_html(__('E-mail', 'shop-emails')); ?>">
                    <strong><?php echo osc_esc_html($seRow['email']); ?></strong>
                    <div class="actions"><ul>
                        <li><a href="#" data-email="<?php echo osc_esc_html($seRow['email']); ?>"
                               onclick="var d=document.getElementById('se-delete');d.querySelector('input[name=email]').value=this.dataset.email;d.showModal();return false;"><?php _e('Delete', 'shop-emails'); ?></a></li>
                    </ul></div></td>
                <td data-col-name="<?php echo osc_esc_html(__('Status', 'shop-emails')); ?>"><?php
                    if ($seRow['status'] === Newsletter::CONFIRMED) {
                        osc_admin_status('active', __('Confirmed', 'shop-emails'));
                    } else {
                        osc_admin_status('expired', __('Pending', 'shop-emails'));
                    } ?></td>
                <td data-col-name="<?php echo osc_esc_html(__('Signed up', 'shop-emails')); ?>"><?php
                    echo $seRow['dt'] > 0 ? osc_esc_html(osc_format_date(gmdate('Y-m-d H:i:s', $seRow['dt']))) : '—'; ?></td>
                <td data-col-name="<?php echo osc_esc_html(__('From', 'shop-emails')); ?>"><?php echo osc_esc_html($seRow['source'] !== '' ? $seRow['source'] : '—'); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
</div>
<?php if ($seAfter !== '' || $sePage['next'] !== null) { ?>
    <p class="d-flex gap-2">
        <?php if ($seAfter !== '') { ?>
            <a class="btn btn-sm btn-dim" href="<?php echo osc_esc_html($seLink(array('q' => $seQuery))); ?>"><?php _e('First page', 'shop-emails'); ?></a>
        <?php } ?>
        <?php if ($sePage['next'] !== null) { ?>
            <a class="btn btn-sm btn-dim" href="<?php echo osc_esc_html($seLink(array('q' => $seQuery, 'after' => $sePage['next']))); ?>"><?php _e('Next page', 'shop-emails'); ?></a>
        <?php } ?>
    </p>
<?php }
osc_admin_confirm_dialog(array(
    'id'     => 'se-delete',
    'url'    => $seList,
    'fields' => array('se_do' => 'delete', 'email' => ''),
    'title'  => __('Delete this address from the list?', 'shop-emails'),
    'text'   => __('It is removed at once and gets no more newsletters from an export you make after this.', 'shop-emails'),
    'confirm' => __('Delete', 'shop-emails'),
));

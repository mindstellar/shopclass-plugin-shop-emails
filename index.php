<?php
/*
Plugin Name: Shop emails
Plugin URI: https://github.com/mindstellar/shopclass-plugin-shop-emails
Description: A newsletter sign-up list with double opt-in and CSV export, and one reminder e-mail for a signed-in shopper who leaves a cart.
Version: 0.1.1
Author: Navjot Tomer (Mindstellar)
Author URI: https://mindstellar.com
Short Name: shop-emails
Requires Shopclass: 7.1.0
Tested up to: 7.1
Requires PHP: 8.2
Support URI: https://github.com/mindstellar/shopclass-plugin-shop-emails/issues
Modes: classifieds, shop
*/

/*
 * This file is part of the Shop emails plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\billing\event\OrderPlaced;
use mindstellar\shopemails\Admin\Screens;
use mindstellar\shopemails\Plugin;
use mindstellar\shopemails\Reminders;
use mindstellar\shopemails\Web;
use mindstellar\shopemails\Widget;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\shopemails\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

osc_register_plugin(osc_plugin_path(__FILE__), array(Plugin::class, 'install'));
osc_add_hook(osc_plugin_path(__FILE__) . '_uninstall', array(Plugin::class, 'uninstall'));

// Needs Shopclass 7.1's key-value list, job queue, settings pages and saved carts. On a core
// without them it loads and does nothing but say so.
if (!function_exists('osc_kv_list')
    || !function_exists('osc_job_enqueue')
    || !function_exists('osc_register_settings_page')
    || !function_exists('osc_add_route_hook')
    || !function_exists('osc_admin_plugin_page')
    || !class_exists('mindstellar\\security\\RateLimit')
) {
    osc_add_hook('admin_page_header', static function (): void {
        echo '<div class="flashmessage flashmessage-warning" role="status">'
            . osc_esc_html(__('Shop emails needs Shopclass 7.1 or later and is switched off. Update Shopclass to use it.', 'shop-emails'))
            . '</div>';
    }, 10);

    return;
}

osc_register_settings_page(Plugin::PAGE, require __DIR__ . '/settings.php');
osc_add_hook(osc_plugin_path(__FILE__) . '_configure', static function () {
    osc_redirect_to(osc_route_admin_url(Screens::ROUTE));
});

// The newsletter: a theme that has a sign-up form asks for the address to post to.
osc_add_filter('aisle_newsletter_action', array(Web::class, 'formAction'), 10);
Widget::register();
osc_add_route_hook(Web::SUBSCRIBE, 'shop-emails/subscribe/?(?:\?.*)?$', 'shop-emails/subscribe');
osc_add_route_hook(Web::CONFIRM, 'shop-emails/confirm/?(?:\?.*)?$', 'shop-emails/confirm');
osc_add_route_hook(Web::UNSUBSCRIBE, 'shop-emails/unsubscribe/?(?:\?.*)?$', 'shop-emails/unsubscribe');
osc_add_hook(Web::SUBSCRIBE, array(Web::class, 'subscribe'));
osc_add_hook(Web::CONFIRM, array(Web::class, 'confirm'));
osc_add_hook(Web::UNSUBSCRIBE, array(Web::class, 'unsubscribe'));

// The cart reminder is for shops only: found on the hourly cron, sent from the job queue.
if (Plugin::shopMode()) {
    osc_add_hook('cron_hourly', array(Reminders::class, 'cron'));
    osc_add_hook('register_jobs', array(Reminders::class, 'registerJob'));
    // Core 7.0 beta 5 and earlier have no typed events: they pass the old arguments.
    osc_add_hook('billing_order_placed', array(Reminders::class, class_exists(OrderPlaced::class) ? 'onOrderPlaced' : 'orderPlaced'), 10);
}

// A person's rows in the export and the account erasure.
osc_add_filter('personal_data_export', array(Plugin::class, 'export'));
osc_add_hook('delete_user', array(Plugin::class, 'erase'));

// The Subscribers screen.
osc_add_route(Screens::ROUTE, 'shop-emails/subscribers', 'shop-emails/subscribers', osc_plugin_folder(__FILE__) . 'admin/subscribers.php');
osc_add_hook('init_admin', array(Screens::class, 'handlePost'));
osc_add_hook('admin_menu_init', array(Screens::class, 'menu'));
osc_add_hook('init_admin', array(Screens::class, 'pages'));

if (!function_exists('shop_emails_subscribe_url')) {
    /**
     * The address a newsletter form posts its `email` field to, for a theme that prints its own
     * form.
     */
    function shop_emails_subscribe_url(): string
    {
        return Web::formAction('');
    }
}

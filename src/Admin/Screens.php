<?php

/*
 * This file is part of the Shop emails plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\shopemails\Admin;

use Closure;
use mindstellar\shopemails\Newsletter;
use mindstellar\shopemails\Plugin;
use Params;

/**
 * The Subscribers screen: its menu entry, its header and its posts (delete, export). Only an
 * administrator reaches them, and every post checks the CSRF token first.
 */
final class Screens
{
    public const ROUTE = 'shop-emails-subscribers';

    /** Rows per page of the list. */
    public const PER_PAGE = 50;

    /** fn(string $body, array $headers): void -- replaces sending the export and stopping, in tests. */
    public static ?Closure $output = null;

    /** The entry under Users: the admin_menu_init hook. */
    public static function menu(): void
    {
        osc_add_admin_submenu_page('users', __('Newsletter subscribers', 'shop-emails'), osc_route_admin_url(self::ROUTE), self::ROUTE, 'administrator');
    }

    /** The header core draws around the screen: the init_admin hook. */
    public static function pages(): void
    {
        osc_admin_plugin_page(self::ROUTE, array(
            'title'   => __('Newsletter subscribers', 'shop-emails'),
            'help'    => static function () {
                echo '<p>' . osc_esc_html(__('Addresses that signed up through the newsletter form. A pending address has not clicked its confirm link yet and is removed after 3 days. The export holds confirmed addresses only, ready for a mailing tool.', 'shop-emails')) . '</p>';
            },
            'actions' => array(
                array('icon' => 'bi-gear-fill', 'url' => osc_settings_page_url(Plugin::PAGE), 'title' => __('Settings', 'shop-emails')),
            ),
        ));
    }

    /** Whether the signed-in user may see and change the list. */
    public static function allowed(): bool
    {
        return osc_is_admin_user_logged_in() && !osc_is_moderator();
    }

    /** Act on a post to the screen: the init_admin hook. */
    public static function handlePost(): void
    {
        if (Params::getParamString('route') !== self::ROUTE
            || strtoupper((string) Params::getServerParam('REQUEST_METHOD')) !== 'POST'
            || Params::getParamString('se_do') === ''
        ) {
            return;
        }
        osc_csrf_check();
        if (!self::allowed()) {
            osc_add_flash_error_message(__('Only an administrator can manage the newsletter list.', 'shop-emails'), 'admin');
            osc_redirect_to(osc_admin_base_url(true));

            return;
        }
        switch (Params::getParamString('se_do')) {
            case 'export':
                self::export();

                return;
            case 'delete':
                if (Newsletter::remove(Plugin::store(), Params::getParamString('email'))) {
                    osc_add_flash_ok_message(__('The address is deleted from the list.', 'shop-emails'), 'admin');
                } else {
                    osc_add_flash_error_message(__('That address is not on the list.', 'shop-emails'), 'admin');
                }
                break;
        }
        osc_redirect_to(osc_route_admin_url(self::ROUTE));
    }

    /** The confirmed addresses as a CSV download. */
    private static function export(): void
    {
        $body = Csv::line(Csv::HEADER);
        Newsletter::each(Plugin::store(), static function (array $row) use (&$body): void {
            if ($row['status'] === Newsletter::CONFIRMED) {
                $body .= Csv::row($row);
            }
        });
        $headers = array(
            'Content-Type: text/csv; charset=utf-8',
            'Content-Disposition: attachment; filename="newsletter-' . gmdate('Y-m-d', Plugin::now()) . '.csv"',
            'Cache-Control: no-store',
            'X-Content-Type-Options: nosniff',
        );
        if (self::$output !== null) {
            (self::$output)($body, $headers);

            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        foreach ($headers as $header) {
            header($header);
        }
        echo $body;
        exit;
    }
}

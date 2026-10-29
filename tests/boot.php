<?php
/*
 * This file is part of the Shop emails plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * index.php's wiring: on Shopclass 7.1 it adds the theme filter, the three public routes, the
 * cron and job hooks and the admin screen; on an older core it adds only the warning.
 * Each case runs in a process of its own, as a plugin loads once per request.
 */

if (($argv[1] ?? '') === 'child') {
    define('ABS_PATH', __DIR__ . '/');
    $GLOBALS['hooks'] = array();
    $record = static function (string $kind) {
        return static function (...$args) use ($kind): void {
            $GLOBALS['hooks'][] = $kind . ':' . $args[0];
        };
    };
    function __($t, $d = '')
    {
        return $t;
    }
    function osc_esc_html($s)
    {
        return (string) $s;
    }
    function osc_plugin_path($f)
    {
        return 'shop-emails/index.php';
    }
    function osc_plugin_folder($f)
    {
        return 'shop-emails/';
    }
    function osc_register_plugin($p, $cb)
    {
    }
    function osc_add_hook($name, $cb, $prio = 10)
    {
        $GLOBALS['hooks'][] = 'hook:' . $name;
    }
    function osc_add_filter($name, $cb, $prio = 10)
    {
        $GLOBALS['hooks'][] = 'filter:' . $name;
    }
    if (in_array($argv[2] ?? '', array('new', 'classifieds'), true)) {
        function osc_kv_list()
        {
        }
        function osc_job_enqueue()
        {
        }
        function osc_register_settings_page($id, $spec)
        {
            $GLOBALS['hooks'][] = 'settings:' . $id . ':' . count($spec['fields']);
        }
        function osc_add_route_hook($id, $re, $url)
        {
            $GLOBALS['hooks'][] = 'route:' . $id;
        }
        function osc_add_route($id, $re, $url, $file)
        {
            $GLOBALS['hooks'][] = 'route:' . $id;
        }
        function osc_admin_plugin_page()
        {
        }
        function osc_site_mode()
        {
            return ($GLOBALS['argv'][2] ?? '') === 'classifieds' ? 'classifieds' : 'shop';
        }
        eval('namespace mindstellar\billing; final class CartStore {}');
        eval('namespace mindstellar\security; final class RateLimit {}');
    }
    spl_autoload_register(static function (string $class): void {
        $prefix = 'mindstellar\\shopemails\\';
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    });
    require __DIR__ . '/../index.php';
    echo json_encode($GLOBALS['hooks']);
    exit(0);
}

require __DIR__ . '/lib/harness.php';

$run = static function (string $core): array {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' child ' . $core . ' 2>&1');

    return json_decode((string) $out, true) ?? array('error: ' . $out);
};

harness_section('on Shopclass 7.1');
$hooks = $run('new');
foreach (array(
    'settings:shop-emails:11',
    'filter:aisle_newsletter_action',
    'route:shop-emails-subscribe', 'route:shop-emails-confirm', 'route:shop-emails-unsubscribe',
    'hook:shop-emails-subscribe', 'hook:shop-emails-confirm', 'hook:shop-emails-unsubscribe',
    'hook:cron_hourly', 'hook:register_jobs', 'hook:billing_order_placed',
    'filter:personal_data_export', 'hook:delete_user',
    'route:shop-emails-subscribers', 'hook:init_admin', 'hook:admin_menu_init',
) as $want) {
    check('wires ' . $want, in_array($want, $hooks, true), implode(', ', $hooks));
}
check('no warning', !in_array('hook:admin_page_header', $hooks, true));

harness_section('on a classifieds site');
$hooks = $run('classifieds');
foreach (array('settings:shop-emails:4', 'filter:aisle_newsletter_action', 'route:shop-emails-subscribe', 'hook:delete_user') as $want) {
    check('wires ' . $want, in_array($want, $hooks, true), implode(', ', $hooks));
}
foreach (array('hook:cron_hourly', 'hook:register_jobs', 'hook:billing_order_placed') as $not) {
    check('no ' . $not, !in_array($not, $hooks, true));
}

harness_section('on an older core');
pin('only the uninstall hook and the warning', array('hook:shop-emails/index.php_uninstall', 'hook:admin_page_header'), $run('old'));

exit(harness_result());

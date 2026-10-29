<?php
/*
 * This file is part of the Shop emails plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The plugin against core's real key-value store, job queue, users, variants and a scratch
 * database: sign-up, confirm and the expiry column; the hourly run queues a day-old cart and
 * not a fresh one; the job names the real listing and price and writes its mark.
 *
 * Skips (exit 0) when core has no osc_kv_list() yet. Usage:
 *   SHOPCLASS_CORE=/path/to/core php tests/integration/flow.php
 */

$core = rtrim(getenv('SHOPCLASS_CORE') ?: dirname(__DIR__, 3) . '/shopclass', '/');
if (!is_file($core . '/oc-includes/osclass/helpers/hKv.php') || strpos((string) file_get_contents($core . '/oc-includes/osclass/helpers/hKv.php'), 'osc_kv_list') === false) {
    echo "SKIP  core has no osc_kv_list()\n";
    exit(getenv('REQUIRE_CORE') === '1' ? 1 : 0);
}
require_once $core . '/tests/lib/harness.php';
require_once $core . '/tests/lib/api-admin-kit.php';

use mindstellar\listing\VariantStore;
use mindstellar\shopemails\Mails;
use mindstellar\shopemails\Newsletter;
use mindstellar\shopemails\Plugin;
use mindstellar\shopemails\Reminders;

$admin = api_admin_boot('osc_shop_emails_plugin');
$p     = DB_TABLE_PREFIX;

spl_autoload_register(static function (string $class): void {
    $prefix = 'mindstellar\\shopemails\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
// Page helpers the kit does not load.
if (!function_exists('osc_page_title')) {
    function osc_page_title()
    {
        return 'Test shop';
    }
}
if (!function_exists('osc_route_url')) {
    function osc_route_url($id, $args = array())
    {
        return WEB_PATH . $id . ($args === array() ? '' : '?' . http_build_query($args));
    }
}
if (!function_exists('osc_mailBeauty')) {
    function osc_mailBeauty($text, $params)
    {
        return \mindstellar\mail\Mailer::beauty($text, $params);
    }
}

$locale = seed_locale($admin);
seed_currency($admin);
$cat = seed_category($admin, 'Tea', null, $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
$tom = seed_user($admin, 'tom', 'tom@example.test');
$tee = seed_item($admin, $cat, null, 'Assam tea', 12.5);
$v   = VariantStore::saveDefault($tee, array('i_stock' => null, 'i_price' => 12500000));

Plugin::$settings = array();
$mails = array();
Plugin::$mailer = static function (array $params) use (&$mails): bool {
    $mails[] = $params;

    return true;
};
$col = static function (string $sql) use ($admin) {
    $r = $admin->query($sql);
    $row = $r instanceof mysqli_result ? $r->fetch_row() : null;

    return $row[0] ?? null;
};

harness_section('newsletter on t_key_value');
$status = Newsletter::subscribe(Plugin::store(), 'Ann@Example.test', '', 'home', 'h', true, true, time(), static fn (string $e, string $t): bool => Mails::confirm($e, $t));
pin('a sign-up is sent', 'sent', $status);
pin('one confirm mail went', 1, count($mails));
pin('the row is pending with an expiry', 1, (int) $col("SELECT COUNT(*) FROM {$p}t_key_value WHERE s_group = 'newsletter' AND s_key = 'ann@example.test' AND dt_expires IS NOT NULL"));
preg_match('/token=([0-9a-f]{32})/', $mails[0]['body'] ?? '', $m);
pin('the confirm link confirms it', 'ann@example.test', Newsletter::confirm(Plugin::store(), $m[1] ?? '', time()));
pin('and clears the expiry of the row and its link', 2, (int) $col("SELECT COUNT(*) FROM {$p}t_key_value WHERE s_group IN ('newsletter', 'newsletter_token') AND dt_expires IS NULL"));
pin('the list reads it through osc_kv_list', array('ann@example.test'), array_column(Newsletter::page(Plugin::store(), 'an')['rows'], 'email'));
pin('a wrong token changes nothing', null, Newsletter::confirm(Plugin::store(), str_repeat('c', 32), time()));

harness_section('the hourly run');
$lines = array(array('variant' => $v, 'qty' => 2));
osc_kv_set('cart', (string) $sue, array('lines' => $lines, 'updated_at' => gmdate('c', time() - 25 * 3600)), 7776000);
osc_kv_set('cart', (string) $tom, array('lines' => $lines, 'updated_at' => gmdate('c', time() - 2 * 3600)), 7776000);
$admin->query("UPDATE {$p}t_key_value SET dt_created = UTC_TIMESTAMP() - INTERVAL 25 HOUR WHERE s_group = 'cart' AND s_key = '{$sue}'");
$admin->query("UPDATE {$p}t_key_value SET dt_created = UTC_TIMESTAMP() - INTERVAL 2 HOUR WHERE s_group = 'cart' AND s_key = '{$tom}'");
pin('one cart is old enough', 1, Reminders::scan(time()));
pin('its job is on the queue for that shopper', array('{"user_id":' . $sue . '}'), array_column($admin->query("SELECT s_payload FROM {$p}t_job_queue WHERE s_type = 'shop_emails.cart_reminder'")->fetch_all(MYSQLI_ASSOC), 's_payload'));
pin('a second run folds into the waiting job', array(1, 1), array(Reminders::scan(time()), (int) $col("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = 'shop_emails.cart_reminder'")));

harness_section('the job');
$mails = array();
pin('the job sends the reminder', true, Reminders::send($sue, time()));
$body = $mails[0]['body'] ?? '';
check('naming the listing, the quantity and the line price', strpos($body, 'Assam tea') !== false && strpos($body, 'Qty 2') !== false && strpos($body, '25.00 USD') !== false, $body);
pin('to the shopper', 'sue@example.test', $mails[0]['to'] ?? null);
pin('the mark is in t_key_value for 30 days', 1, (int) $col("SELECT COUNT(*) FROM {$p}t_key_value WHERE s_group = 'cart_reminder' AND s_key = '{$sue}' AND dt_expires > UTC_TIMESTAMP() + INTERVAL 29 DAY"));
pin('the next run skips the reminded cart', 0, Reminders::scan(time()));

harness_section('personal data');
$out = Plugin::export(array('data' => array()), $sue);
pin('the export lists the reminder mark', 'sent', $out['data']['shop_emails_cart_reminder']['rows'][0]['kind'] ?? null);
Plugin::erase($sue);
pin('erasing the account removes the mark', 0, (int) $col("SELECT COUNT(*) FROM {$p}t_key_value WHERE s_group = 'cart_reminder' AND s_key = '{$sue}'"));

exit(harness_result());

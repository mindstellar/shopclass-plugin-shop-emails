<?php
/*
 * This file is part of the Shop emails plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The Subscribers screen's posts: the CSV export and delete need the CSRF token and an
 * administrator, and the export holds confirmed addresses only.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\shopemails\Admin\Screens;
use mindstellar\shopemails\Newsletter as N;

$s = fresh();
$noMail = static fn (string $e, string $t): bool => true;
N::subscribe($s, 'conf@example.test', '', 'home', 'h', true, false, $s->now, $noMail);
N::subscribe($s, '=cmd@example.test', '', 'home', 'h', true, false, $s->now, $noMail);
N::subscribe($s, 'pend@example.test', '', 'home', 'h', true, true, $s->now, $noMail);

$GLOBALS['export'] = null;
Screens::$output = static function (string $body, array $headers): void {
    $GLOBALS['export'] = array($body, $headers);
};

/** Post to the screen and return what happened. */
function post(array $request, bool $csrf, bool $admin): array
{
    $GLOBALS['request']  = $request + array('route' => Screens::ROUTE);
    $GLOBALS['server']   = array('REQUEST_METHOD' => 'POST');
    $GLOBALS['csrf_ok']  = $csrf;
    $GLOBALS['is_admin'] = $admin;
    $GLOBALS['calls']    = array();
    $GLOBALS['export']   = null;
    try {
        Screens::handlePost();
    } catch (StopRequest $e) {
    }

    return $GLOBALS['calls'];
}

harness_section('export');
pin('without a CSRF token nothing is exported', array(array('csrf'), null), array(post(array('se_do' => 'export'), false, true), $GLOBALS['export']));
$calls = post(array('se_do' => 'export'), true, false);
pin('a signed-out or non-admin request is refused after the token check', array('csrf', 'error:Only an administrator can manage the newsletter list.'), array_slice($calls, 0, 2));
pin('and gets no file', null, $GLOBALS['export']);
post(array('se_do' => 'export'), true, true);
[$body, $headers] = $GLOBALS['export'] ?? array('', array());
pin('an admin gets confirmed addresses only, a formula made text', "email,status,source,signed_up,confirmed_at\r\n'=cmd@example.test,confirmed,home,2025-10-09 08:53:20,2025-10-09 08:53:20\r\nconf@example.test,confirmed,home,2025-10-09 08:53:20,2025-10-09 08:53:20\r\n", $body);
check('as a CSV download that is never cached', in_array('Content-Type: text/csv; charset=utf-8', $headers, true)
    && in_array('Cache-Control: no-store', $headers, true)
    && (bool) preg_grep('/^Content-Disposition: attachment; filename="newsletter-\d{4}-\d\d-\d\d\.csv"$/', $headers));

harness_section('delete');
pin('without a token nothing is deleted', array(array('csrf'), true), array(post(array('se_do' => 'delete', 'email' => 'pend@example.test'), false, true), N::find($s, 'pend@example.test') !== null));
post(array('se_do' => 'delete', 'email' => 'pend@example.test'), true, false);
pin('nor by a non-admin', true, N::find($s, 'pend@example.test') !== null);
$calls = post(array('se_do' => 'delete', 'email' => 'pend@example.test'), true, true);
pin('an admin deletes it', array(null, 'ok:The address is deleted from the list.'), array(N::find($s, 'pend@example.test'), $calls[1] ?? null));

harness_section('other requests');
$GLOBALS['request'] = array('route' => Screens::ROUTE, 'se_do' => 'export');
$GLOBALS['server']  = array('REQUEST_METHOD' => 'GET');
$GLOBALS['calls']   = array();
Screens::handlePost();
pin('a GET does nothing', array(), $GLOBALS['calls']);
pin('another route does nothing', array(), post(array('route' => 'other', 'se_do' => 'export'), true, true));

exit(harness_result());

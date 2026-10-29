<?php
/*
 * This file is part of the Shop emails plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The sign-up list: subscribe, confirm, unsubscribe, the limits and the admin list's paging.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\shopemails\Mails;
use mindstellar\shopemails\Newsletter as N;

$mail = static fn (string $email, string $token): bool => Mails::confirm($email, $token);
$sub  = static function (ArrayStore $s, string $email, array $o = array()) use ($mail): string {
    return N::subscribe($s, $email, $o['hp'] ?? '', $o['source'] ?? 'home', 'iphash', $o['allowed'] ?? true, $o['optin'] ?? true, $s->now, $mail);
};
$tokenOf = static function (array $sent): string {
    preg_match('/token=([0-9a-f]{32})/', $sent['body'], $m);

    return $m[1] ?? '';
};

harness_section('subscribe');
$s = fresh();
pin('a new address is sent a confirm mail', 'sent', $sub($s, '  Sue@Example.TEST '));
$row = N::find($s, 'sue@example.test');
pin('it is stored lower-cased, pending, from the home page', array('pending', 'home', 'iphash'), array($row['status'] ?? null, $row['source'] ?? null, $row['ip_hash'] ?? null));
pin('pending rows expire in three days', $s->now + N::PENDING_TTL, $s->expiry(N::GROUP, 'sue@example.test'));
pin('one mail, to the address', array(1, 'sue@example.test'), array(count($GLOBALS['mails']), $GLOBALS['mails'][0]['to'] ?? null));
$token = $tokenOf($GLOBALS['mails'][0]);
check('the mail carries a 32-hex token', strlen($token) === 32);
pin('only the token\'s hash is stored', hash('sha256', $token), $row['token'] ?? null);
check('the stored row holds no raw token', strpos(json_encode($s->rows), $token) === false);
check('the mail is marked as carrying a secret link', !empty($GLOBALS['mails'][0]['secret_link']));
check('the mail links the confirm and unsubscribe routes', strpos($GLOBALS['mails'][0]['body'], 'shop-emails-confirm?token=' . $token) !== false
    && strpos($GLOBALS['mails'][0]['body'], 'shop-emails-unsubscribe?token=' . $token) !== false);
pin('the subject names the site as plain text', 'Please confirm your e-mail for Tea & Co <Shop>', $GLOBALS['mails'][0]['subject']);
check('the body escapes the site name', strpos($GLOBALS['mails'][0]['body'], 'Tea &amp; Co &lt;Shop&gt;') !== false && strpos($GLOBALS['mails'][0]['body'], '<Shop>') === false);

pin('signing up again at once sends nothing more', array('sent', 1), array($sub($s, 'sue@example.test'), count($GLOBALS['mails'])));
$s->now += N::RESEND_GAP;
pin('later it sends a fresh link', array('sent', 2), array($sub($s, 'sue@example.test'), count($GLOBALS['mails'])));
$newer = $tokenOf($GLOBALS['mails'][1]);
check('the new link has a new token', $newer !== '' && $newer !== $token);
pin('the old link no longer works', null, N::confirm($s, $token, $s->now));
pin('the old link\'s row is deleted', false, isset($s->rows[N::TOKENS][hash('sha256', $token)]));
$s->set(N::TOKENS, hash('sha256', $token), 'sue@example.test', N::PENDING_TTL);
pin('an old token row left behind still does not match the address\'s current link', null, N::byToken($s, $token));

harness_section('confirm');
$s->now += 60;
pin('the link confirms the address', 'sue@example.test', N::confirm($s, $newer, $s->now));
$row = N::find($s, 'sue@example.test');
pin('confirmed, with the time', array('confirmed', $s->now), array($row['status'], $row['confirmed_at']));
pin('a confirmed row no longer expires', null, $s->expiry(N::GROUP, 'sue@example.test'));
pin('nor does its link', null, $s->expiry(N::TOKENS, hash('sha256', $newer)));
pin('a second click is fine', 'sue@example.test', N::confirm($s, $newer, $s->now));
$before = json_encode($s->rows);
pin('a wrong token confirms nothing', null, N::confirm($s, str_repeat('a', 32), $s->now));
pin('a malformed token confirms nothing', null, N::confirm($s, '../../x', $s->now));
pin('and changes nothing', $before, json_encode($s->rows));
$sent = count($GLOBALS['mails']);
pin('a confirmed address signing up again is told it exists', 'exists', $sub($s, 'SUE@example.test'));
pin('and is sent nothing', $sent, count($GLOBALS['mails']));

harness_section('refusals');
$s    = fresh();
$hits = 0;
$limited = static function () use (&$hits): bool {
    return ++$hits <= 10;
};
for ($i = 1; $i <= 10; $i++) {
    $sub($s, "p$i@example.test", array('allowed' => $limited()));
}
pin('ten sign-ups from one visitor in an hour go through', 10, count($s->rows[N::GROUP]));
pin('the eleventh is told to wait', 'wait', $sub($s, 'p11@example.test', array('allowed' => $limited())));
pin('and nothing is stored for it', null, N::find($s, 'p11@example.test'));

$s = fresh();
pin('a filled honeypot answers sent', 'sent', $sub($s, 'bot@example.test', array('hp' => 'http://spam.test')));
pin('and stores nothing, sends nothing', array(array(), 0), array($s->rows, count($GLOBALS['mails'])));
pin('a bad address is invalid', 'invalid', $sub($s, 'not-an-address'));
pin('an empty one too', 'invalid', $sub($s, ''));
pin('a header-injection try too', 'invalid', $sub($s, "a@example.test\r\nBcc: x@example.test"));
$long = 'a@' . str_repeat(str_repeat('b', 60) . '.', 3) . 'example.com';
check('the long address is otherwise valid', strlen($long) > 191 && filter_var($long, FILTER_VALIDATE_EMAIL) !== false);
pin('one longer than 191 characters too', 'invalid', $sub($s, $long));
pin('none of them stored anything', array(), $s->rows);
pin('an unknown source falls back to home', 'home', ($sub($s, 'src@example.test', array('source' => '<b>x</b>')) !== '' ? N::find($s, 'src@example.test')['source'] : ''));

$s = fresh();
$GLOBALS['mail_fails'] = true;
pin('when the mail cannot go out, try again later', 'wait', $sub($s, 'down@example.test'));
pin('and nothing is kept', array(array(), array()), array($s->rows[N::GROUP] ?? array(), $s->rows[N::TOKENS] ?? array()));

harness_section('without double opt-in');
$s = fresh();
pin('the address joins at once', 'confirmed', $sub($s, 'quick@example.test', array('optin' => false)));
pin('confirmed, kept, no mail', array('confirmed', null, 0), array(N::find($s, 'quick@example.test')['status'], $s->expiry(N::GROUP, 'quick@example.test'), count($GLOBALS['mails'])));

harness_section('expiry');
$s = fresh();
$sub($s, 'late@example.test');
$token = $tokenOf($GLOBALS['mails'][0]);
$s->now += N::PENDING_TTL;
pin('an unconfirmed address is gone after three days', null, N::find($s, 'late@example.test'));
pin('and so is its link', null, N::confirm($s, $token, $s->now));
pin('it may sign up again', 'sent', $sub($s, 'late@example.test'));

harness_section('unsubscribe');
$s = fresh();
$sub($s, 'bye@example.test');
$token = $tokenOf($GLOBALS['mails'][0]);
N::confirm($s, $token, $s->now);
pin('a wrong token removes nothing', null, N::unsubscribe($s, str_repeat('b', 32)));
pin('the link removes the address', 'bye@example.test', N::unsubscribe($s, $token));
pin('the row and its link are gone', array(array(), array()), array($s->rows[N::GROUP], $s->rows[N::TOKENS]));
pin('the link does nothing a second time', null, N::unsubscribe($s, $token));

harness_section('the admin list');
$s = fresh(array('double_optin' => false));
foreach (array('amy', 'ann', 'anna', 'bob', 'an') as $name) {
    $sub($s, $name . '@example.test', array('optin' => false));
}
$p = N::page($s, 'an', null, 2);
pin('a prefix search pages in address order', array('an@example.test', 'ann@example.test'), array_column($p['rows'], 'email'));
pin('with the next page\'s cursor', 'ann@example.test', $p['next']);
$p = N::page($s, 'an', $p['next'], 2);
pin('the next page ends at the prefix', array(array('anna@example.test'), null), array(array_column($p['rows'], 'email'), $p['next']));
pin('no prefix lists everyone', 5, count(N::page($s, '', null, 50)['rows']));
pin('the counts', array('confirmed' => 5, 'pending' => 0), N::counts($s));
pin('remove deletes one address and its link', array(true, 4, 4), array(N::remove($s, 'BOB@example.test'), count($s->rows[N::GROUP]), count($s->rows[N::TOKENS])));
pin('removing it again says so', false, N::remove($s, 'bob@example.test'));

harness_section('a list longer than one read');
$s = fresh(array('double_optin' => false));
for ($i = 0; $i < 2105; $i++) {
    $value = array('status' => $i % 3 === 0 ? 'pending' : 'confirmed', 'token' => '', 'source' => 'home', 'dt' => $s->now, 'ip_hash' => '');
    $s->set(N::GROUP, sprintf('u%05d@example.test', $i), $value);
}
$seen = array();
N::each($s, static function (array $row) use (&$seen): void {
    if (count($seen) > 5000) {
        throw new RuntimeException('each() does not move on');
    }
    $seen[] = $row['email'];
});
pin('each() visits every row once, across three reads', array(2105, 2105, 'u02104@example.test'), array(count($seen), count(array_unique($seen)), end($seen)));
pin('the counts cover them all', array('confirmed' => 1403, 'pending' => 702), N::counts($s));

exit(harness_result());

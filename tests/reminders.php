<?php
/*
 * This file is part of the Shop emails plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The cart reminder: which carts the hourly run picks, the per-run cap, and what the job sends.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\shopemails\Reminders as R;

const HOUR = 3600;

/** A saved cart as core writes it, last changed $age seconds ago. */
function cart(ArrayStore $s, int $userId, int $age, array $lines = array(array('variant' => 5, 'qty' => 2))): void
{
    $now    = $s->now;
    $s->now = $now - $age;
    $s->set('cart', (string) $userId, array('lines' => $lines, 'updated_at' => gmdate('c', $s->now)), 7776000);
    $s->now = $now;
}

function setup(array $settings = array()): ArrayStore
{
    $s = fresh($settings);
    $GLOBALS['queued'] = array();
    R::$users = static function (array $ids): array {
        $all = array(
            1 => array('email' => 'one@example.test', 'name' => 'One'),
            2 => array('email' => 'two@example.test', 'name' => ''),
            3 => array('email' => 'three@example.test', 'name' => 'Three'),
            4 => array('email' => 'four@example.test', 'name' => 'Four'),
            5 => array('email' => 'five@example.test', 'name' => 'Five'),
            6 => array('email' => 'six@example.test', 'name' => 'Six'),
        );

        return array_intersect_key($all, array_flip($ids));
    };
    R::$enqueue = static function (int $userId): bool {
        $GLOBALS['queued'][] = $userId;

        return true;
    };
    R::$cart = static function (int $userId) use ($s): array {
        $row = $s->get('cart', (string) $userId);

        return is_array($row) ? $row : array('lines' => array(), 'updated_at' => null);
    };
    R::$price = static function (array $lines): array {
        $out = array();
        foreach ($lines as $l) {
            $out[] = array('title' => 'Tea <' . $l['variant'] . '>', 'options' => 'Size: M', 'qty' => $l['qty'], 'price' => '10.00 INR');
        }

        return $out;
    };

    return $s;
}

harness_section('which carts the hourly run picks');
$s = setup();
cart($s, 1, 25 * HOUR);                    // left a day ago: picked
cart($s, 2, 2 * HOUR);                     // still shopping: skipped
cart($s, 3, 25 * HOUR, array());           // empty: skipped
cart($s, 4, 25 * HOUR);                    // reminded already: skipped
$s->set(R::MARKS, '4', array('why' => 'sent', 'at' => $s->now - HOUR), R::MARK_TTL);
cart($s, 9, 25 * HOUR);                    // no e-mail: skipped
cart($s, 5, 25 * HOUR);                    // ordered after the cart's last change: skipped
$s->set(R::MARKS, '5', array('why' => 'ordered', 'at' => $s->now - 24 * HOUR), R::MARK_TTL);
cart($s, 6, 40 * 86400);                   // left weeks ago: skipped
pin('only the day-old cart with lines, no mark and an e-mail is queued', array(1, array(1)), array(R::scan($s->now), $GLOBALS['queued']));
pin('a run that read every cart starts again from the first next time', null, $s->get(R::STATE, 'cursor'));

$s = setup(array('reminder_on' => false));
cart($s, 1, 25 * HOUR);
pin('switched off, nothing is queued', 0, R::scan($s->now));

$s = setup(array('reminder_delay' => 1));
cart($s, 2, 2 * HOUR);
pin('a shorter delay picks the two-hour cart', array(2), (R::scan($s->now) ? $GLOBALS['queued'] : array()));

$s = setup();
cart($s, 1, 25 * HOUR);
$s->set(R::MARKS, '1', array('why' => 'ordered', 'at' => $s->now - 30 * HOUR), R::MARK_TTL);
pin('a cart changed after the last order is picked', array(1), (R::scan($s->now) ? $GLOBALS['queued'] : array()));

harness_section('at most reminder_max per run');
$s = setup(array('reminder_max' => 2));
foreach (array(1, 2, 3, 4, 5, 6) as $id) {
    cart($s, $id, 30 * HOUR);
}
pin('one run queues at most two', array(2, array(1, 2)), array(R::scan($s->now), $GLOBALS['queued']));
pin('it remembers where it stopped', '2', $s->get(R::STATE, 'cursor'));
$GLOBALS['queued'] = array();
pin('the next run carries on after it', array(2, array(3, 4)), array(R::scan($s->now), $GLOBALS['queued']));

harness_section('the job');
$s = setup();
cart($s, 1, 25 * HOUR, array(array('variant' => 5, 'qty' => 2), array('variant' => 6, 'qty' => 1)));
pin('the job sends one mail', array(true, 1), array(R::send(1, $s->now), count($GLOBALS['mails'])));
$m = $GLOBALS['mails'][0];
pin('to the shopper', array('one@example.test', 'One'), array($m['to'], $m['to_name']));
pin('with the reminder subject', 'You left something in your cart', $m['subject']);
check('naming each line, escaped', strpos($m['body'], 'Tea &lt;5&gt;') !== false && strpos($m['body'], 'Tea &lt;6&gt;') !== false && strpos($m['body'], 'Qty 2') !== false);
check('greeting the shopper by name', strpos($m['body'], 'Hi One,') !== false);
check('linking the cart (the home page with #cart by default)', strpos($m['body'], 'href="https://shop.example/#cart"') !== false);
$mark = $s->get(R::MARKS, '1');
pin('the mark is written for 30 days', array('sent', $s->now + R::MARK_TTL), array($mark['why'] ?? null, $s->expiry(R::MARKS, '1')));
pin('a second run of the job sends nothing', array(false, 1), array(R::send(1, $s->now), count($GLOBALS['mails'])));
$GLOBALS['queued'] = array();
pin('and the hourly run skips the cart now', array(0, array()), array(R::scan($s->now), $GLOBALS['queued']));

$s = setup(array('cart_url' => 'https://shop.example/checkout'));
cart($s, 1, 25 * HOUR);
R::send(1, $s->now);
check('the owner\'s cart link is used', strpos($GLOBALS['mails'][0]['body'], 'href="https://shop.example/checkout"') !== false);
$s = setup(array('cart_url' => 'https://evil.example/checkout'));
cart($s, 1, 25 * HOUR);
R::send(1, $s->now);
check('a link off the site is not', strpos($GLOBALS['mails'][0]['body'], 'evil.example') === false);

$s = setup();
cart($s, 2, 25 * HOUR, array_map(static fn (int $v): array => array('variant' => $v, 'qty' => 1), range(1, 7)));
R::send(2, $s->now);
$body = $GLOBALS['mails'][0]['body'];
pin('at most five lines are named', 5, substr_count($body, 'Size: M'));
check('and the rest are counted', strpos($body, 'And 2 more in your cart.') !== false);
check('no name greets by the address', strpos($body, 'Hi two@example.test,') !== false);

$s = setup();
cart($s, 1, 25 * HOUR);
$s->delete('cart', '1');
pin('a cart emptied before the job runs sends nothing', array(false, 0, null), array(R::send(1, $s->now), count($GLOBALS['mails']), $s->get(R::MARKS, '1')));
cart($s, 1, 25 * HOUR);
R::orderPlaced(70, 1);
pin('nor does a cart ordered before the job runs', array(false, 0), array(R::send(1, $s->now), count($GLOBALS['mails'])));
$s = setup();
cart($s, 1, 10 * 60);
pin('nor a cart changed since it was queued (no mark on it)', array(false, 0, null), array(R::send(1, $s->now), count($GLOBALS['mails']), $s->get(R::MARKS, '1')));
cart($s, 9, 25 * HOUR);
pin('nor a shopper with no e-mail', array(false, 0), array(R::send(9, $s->now), count($GLOBALS['mails'])));

$s = setup();
cart($s, 1, 25 * HOUR);
$GLOBALS['mail_fails'] = true;
$threw = false;
try {
    R::send(1, $s->now);
} catch (RuntimeException $e) {
    $threw = true;
}
check('a failed send throws, so the queue tries again', $threw);
pin('and writes no mark', null, $s->get(R::MARKS, '1'));

$s = setup();
cart($s, 1, 25 * HOUR);
R::$price = static fn (array $lines): array => array();
pin('a cart whose lines can no longer be priced sends nothing', array(false, 0, null), array(R::send(1, $s->now), count($GLOBALS['mails']), $s->get(R::MARKS, '1')));

harness_section('more carts than one page, and the read cap');
$s = setup(array('reminder_max' => 500));
R::$users = static function (array $ids): array {
    $out = array();
    foreach ($ids as $id) {
        $out[$id] = array('email' => 'u' . $id . '@example.test', 'name' => '');
    }

    return $out;
};
foreach (range(1000, 1249) as $id) {
    cart($s, $id, 25 * HOUR);
}
pin('a run reads every page: 250 carts, 250 queued', array(250, 250, 1249), array(R::scan($s->now), count($GLOBALS['queued']), $GLOBALS['queued'] ? max($GLOBALS['queued']) : 0));
pin('and starts from the first next time', null, $s->get(R::STATE, 'cursor'));

$s = setup(array('reminder_max' => 500));
R::$users = static function (array $ids): array {
    return array_fill_keys($ids, array('email' => 'u@example.test', 'name' => ''));
};
foreach (range(1000, 1549) as $id) {
    cart($s, $id, 25 * HOUR, array());
}
foreach (range(1550, 1599) as $id) {
    cart($s, $id, 25 * HOUR);
}
pin('one run reads at most ' . R::SCAN . ' carts', array(0, '1499'), array(R::scan($s->now), $s->get(R::STATE, 'cursor')));
pin('the next run carries on and finds the rest', array(50, 1550, 1599, null), array(R::scan($s->now), $GLOBALS['queued'] ? min($GLOBALS['queued']) : 0, $GLOBALS['queued'] ? max($GLOBALS['queued']) : 0, $s->get(R::STATE, 'cursor')));

harness_section('an order');
$s = setup();
R::orderPlaced(10, 3);
pin('placing an order marks the shopper', 'ordered', $s->get(R::MARKS, '3')['why'] ?? null);
$s->set(R::MARKS, '4', array('why' => 'sent', 'at' => 1), R::MARK_TTL);
R::orderPlaced(11, 4);
pin('an order keeps a sent mark as it is', 'sent', $s->get(R::MARKS, '4')['why'] ?? null);
R::orderPlaced(12, 0);
pin('a guest order marks nobody', array('3', '4'), array_map('strval', array_keys($s->rows[R::MARKS])));

if (!class_exists('mindstellar\\billing\\event\\OrderPlaced')) {
    eval('namespace mindstellar\\billing\\event; final class OrderPlaced { public function __construct(private int $o, private int $u) {} public function orderId(): int { return $this->o; } public function userId(): int { return $this->u; } }');
}
$s = setup();
R::onOrderPlaced(new \mindstellar\billing\event\OrderPlaced(13, 5));
pin('the typed event marks the buyer too', 'ordered', $s->get(R::MARKS, '5')['why'] ?? null);

exit(harness_result());

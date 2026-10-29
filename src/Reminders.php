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

namespace mindstellar\shopemails;

use Closure;
use mindstellar\billing\CartPricer;
use mindstellar\billing\CartStore;
use mindstellar\billing\event\OrderPlaced;
use mindstellar\currency\Money;
use mindstellar\job\Job;
use mindstellar\listing\VariantStore;
use mindstellar\user\UserStore;
use RuntimeException;
use Throwable;

/**
 * The cart reminder. The hourly cron walks the saved carts (core's `cart` group) a few pages at
 * a time from where the last run stopped, and queues one job per cart left long enough; the job
 * sends the mail and writes a mark in group `cart_reminder` (key: the user id) that lives 30
 * days. Placing an order writes a mark too, so the cart it came from gets no reminder.
 */
final class Reminders
{
    public const JOB = 'shop_emails.cart_reminder';

    public const MARKS = 'cart_reminder';

    /** Core's saved carts, CartStore::GROUP. */
    public const CARTS = 'cart';

    /** Group of the plugin's own state: where the last run stopped. */
    public const STATE = 'shop_emails';

    public const MARK_TTL = 2592000;

    /** Carts read per run, at most. */
    public const SCAN = 500;

    public const PAGE = 100;

    /** Lines named in the mail. */
    public const LINES = 5;

    /** fn(int[] $ids): array<int,array{email:string,name:string}> -- replaces the user lookup in tests. */
    public static ?Closure $users = null;

    /** fn(int $userId): bool -- replaces the job queue in tests. */
    public static ?Closure $enqueue = null;

    /** fn(int $userId): array{lines:array,updated_at:?string} -- replaces CartStore::read() in tests. */
    public static ?Closure $cart = null;

    /** fn(array $lines): list<array{title:string,options:string,qty:int,price:string}> -- replaces the pricing in tests. */
    public static ?Closure $price = null;

    /** `cron_hourly`. Never throws: a failure waits for the next hour. */
    public static function cron(): void
    {
        try {
            self::scan(Plugin::now());
        } catch (Throwable $e) {
            trigger_error('Shop emails: the cart reminder run failed: ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    /** `register_jobs`. */
    public static function registerJob(): void
    {
        osc_job_register_handler(self::JOB, array(self::class, 'handle'));
        if (function_exists('osc_job_describe')) {
            osc_job_describe(self::JOB, __('Cart reminder e-mail', 'shop-emails'), static function (array $payload): string {
                return sprintf(__('User #%d', 'shop-emails'), (int) ($payload['user_id'] ?? 0));
            });
        }
    }

    /**
     * Queue reminders for carts left at least the delay, at most `reminder_max` of them.
     *
     * @return int how many were queued
     */
    public static function scan(int $now): int
    {
        if (!Plugin::flag('reminder_on')) {
            return 0;
        }
        $delay  = Plugin::number('reminder_delay', 1, 720) * 3600;
        $oldest = $now - $delay - Plugin::number('reminder_max_age', 1, 90) * 86400;
        $max    = Plugin::number('reminder_max', 1, 500);
        $store  = Plugin::store();
        $before = gmdate('Y-m-d H:i:s', $now - $delay);
        $cursor = $store->get(self::STATE, 'cursor');
        $cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;
        $queued = 0;
        $read   = 0;

        while ($read < self::SCAN && $queued < $max) {
            $batch = $store->page(self::CARTS, self::PAGE, $before, $cursor);
            $read += count($batch);
            $found = array();
            foreach ($batch as $kv) {
                $userId  = ctype_digit($kv['key']) ? (int) $kv['key'] : 0;
                $value   = is_array($kv['value']) ? $kv['value'] : array();
                $updated = self::updated($value['updated_at'] ?? null, $kv['updated_at']);
                if ($userId > 0 && !empty($value['lines']) && $updated >= $oldest && $updated <= $now - $delay
                    && !self::covered($store->get(self::MARKS, (string) $userId), $updated)
                ) {
                    $found[$kv['key']] = $userId;
                }
            }
            $users = $found === array() ? array() : self::users(array_values($found));
            $last  = null;
            foreach ($batch as $kv) {
                if ($queued >= $max) {
                    break;
                }
                $last = $kv['key'];
                $userId = $found[$kv['key']] ?? 0;
                if ($userId > 0 && isset($users[$userId]) && self::queue($userId)) {
                    $queued++;
                }
            }
            if ($queued >= $max) {
                // Start the next run with the cart after the last one queued.
                $cursor = $last;
                break;
            }
            $cursor = $last;
            if (count($batch) < self::PAGE) {
                $cursor = null;
                break;
            }
        }

        if ($cursor === null) {
            $store->delete(self::STATE, 'cursor');
        } else {
            $store->set(self::STATE, 'cursor', $cursor, 7 * 86400);
        }

        return $queued;
    }

    /**
     * The job: send the reminder unless the cart was emptied, changed, ordered or already
     * reminded since it was queued. Throws when the mail could not be sent, so the queue
     * tries again.
     *
     * @param Job|array<string,mixed> $job
     */
    public static function handle($job): void
    {
        $userId = (int) ($job instanceof Job ? $job->get('user_id', 0) : ($job['user_id'] ?? 0));
        self::send($userId, Plugin::now());
    }

    /**
     * @return bool whether a mail went
     * @throws RuntimeException when the mail could not be sent
     */
    public static function send(int $userId, int $now): bool
    {
        if ($userId < 1 || !Plugin::flag('reminder_on')) {
            return false;
        }
        $store   = Plugin::store();
        $cart    = self::$cart !== null ? (self::$cart)($userId) : CartStore::read($userId);
        $lines   = is_array($cart['lines'] ?? null) ? $cart['lines'] : array();
        $updated = self::updated($cart['updated_at'] ?? null, null);
        $delay   = Plugin::number('reminder_delay', 1, 720) * 3600;
        if ($lines === array() || $updated === 0 || $updated > $now - $delay
            || self::covered($store->get(self::MARKS, (string) $userId), $updated)
        ) {
            return false;
        }
        $user = self::users(array($userId))[$userId] ?? null;
        if ($user === null) {
            return false;
        }
        $priced = self::$price !== null ? (self::$price)($lines) : self::price($lines);
        if ($priced === array()) {
            return false;
        }
        if (!Mails::reminder($user, array_slice($priced, 0, self::LINES), max(0, count($priced) - self::LINES))) {
            throw new RuntimeException('The cart reminder mail could not be sent.');
        }
        $store->set(self::MARKS, (string) $userId, array('why' => 'sent', 'at' => $now, 'cart' => $updated), self::MARK_TTL);

        return true;
    }

    /** Typed listener for `billing_order_placed`. */
    public static function onOrderPlaced(OrderPlaced $e): void
    {
        self::orderPlaced($e->orderId(), $e->userId());
    }

    /**
     * `billing_order_placed`, old arguments: the cart this order came from gets no reminder.
     *
     * @param mixed $orderId
     * @param mixed $userId
     */
    public static function orderPlaced($orderId = 0, $userId = 0): void
    {
        $userId = (int) $userId;
        if ($userId < 1) {
            return;
        }
        try {
            $store = Plugin::store();
            $mark  = $store->get(self::MARKS, (string) $userId);
            if (!is_array($mark) || ($mark['why'] ?? '') !== 'sent') {
                $store->set(self::MARKS, (string) $userId, array('why' => 'ordered', 'at' => Plugin::now()), self::MARK_TTL);
            }
        } catch (Throwable $e) {
        }
    }

    /**
     * Whether a mark rules out a reminder for a cart last changed at $updated: one was sent in
     * the last 30 days, or an order was placed after the cart's last change.
     *
     * @param mixed $mark
     */
    public static function covered($mark, int $updated): bool
    {
        if (!is_array($mark)) {
            return false;
        }
        $why = (string) ($mark['why'] ?? '');

        return $why === 'sent' || ($why === 'ordered' && $updated <= (int) ($mark['at'] ?? 0));
    }

    /**
     * Live accounts with an e-mail address, by id.
     *
     * @param int[] $ids
     *
     * @return array<int,array{email:string,name:string}>
     */
    public static function users(array $ids): array
    {
        if (self::$users !== null) {
            return (self::$users)($ids);
        }
        $out = array();
        foreach (UserStore::byIds($ids, array('pk_i_id', 's_email', 's_name'), true) as $row) {
            $email = trim((string) ($row['s_email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $out[(int) $row['pk_i_id']] = array('email' => $email, 'name' => trim((string) ($row['s_name'] ?? '')));
            }
        }

        return $out;
    }

    /**
     * The cart's lines that can still be bought, with their titles and line prices.
     *
     * @param array<int,array{variant:int,qty:int}> $lines
     *
     * @return list<array{title:string,options:string,qty:int,price:string}>
     */
    public static function price(array $lines): array
    {
        $variants = VariantStore::byIds(array_map(static fn (array $l): int => (int) ($l['variant'] ?? 0), $lines));
        $itemIds  = array_values(array_unique(array_map(static fn (array $v): int => (int) $v['fk_i_item_id'], $variants)));
        $listings = CartPricer::listings($itemIds, '');
        $out      = array();
        foreach ($lines as $line) {
            $variant = $variants[(int) ($line['variant'] ?? 0)] ?? null;
            $listing = $variant === null ? null : ($listings[(int) $variant['fk_i_item_id']] ?? null);
            $unit    = $variant === null ? null : ($variant['i_price'] ?? $listing['i_price'] ?? null);
            $qty     = max(1, (int) ($line['qty'] ?? 1));
            if ($listing === null || !is_numeric($unit)) {
                continue;
            }
            $options = is_string($variant['s_options'] ?? null) ? json_decode($variant['s_options'], true) : null;
            $parts   = array();
            foreach (is_array($options) ? $options : array() as $name => $value) {
                if (is_scalar($value)) {
                    $parts[] = $name . ': ' . $value;
                }
            }
            $out[] = array(
                'title'   => (string) $listing['title'],
                'options' => implode(', ', $parts),
                'qty'     => $qty,
                'price'   => Money::format((int) $unit * $qty, strtoupper((string) ($listing['fk_c_currency_code'] ?? osc_currency()))),
            );
        }

        return $out;
    }

    /** Put a reminder on the queue; a waiting one for the same shopper is kept, not doubled. */
    private static function queue(int $userId): bool
    {
        if (self::$enqueue !== null) {
            return (bool) (self::$enqueue)($userId);
        }

        return osc_job_enqueue(self::JOB, array('user_id' => $userId), array('unique_key' => 'user:' . $userId)) > 0;
    }

    /**
     * When a cart was last changed: its own ISO time, else the row's.
     *
     * @param mixed $iso
     */
    private static function updated($iso, ?int $row): int
    {
        $t = is_string($iso) && $iso !== '' ? strtotime($iso) : false;

        return $t !== false ? (int) $t : (int) $row;
    }
}

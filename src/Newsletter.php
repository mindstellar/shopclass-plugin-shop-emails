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

/**
 * The sign-up list. One row per address in group `newsletter` (key: the lower-cased address),
 * and one row per link in group `newsletter_token` (key: the SHA-256 of the link's token, value:
 * the address), so a confirm or unsubscribe link carries no address. Only the token's hash is
 * stored. A pending row and its token expire after three days.
 */
final class Newsletter
{
    public const GROUP = 'newsletter';

    public const TOKENS = 'newsletter_token';

    public const PENDING   = 'pending';
    public const CONFIRMED = 'confirmed';

    /** What a sign-up answers; the theme shows a line for each. */
    public const SENT    = 'sent';
    public const EXISTS  = 'exists';
    public const INVALID = 'invalid';
    public const WAIT    = 'wait';

    /** Seconds an unconfirmed sign-up is kept. */
    public const PENDING_TTL = 259200;

    /** Seconds before a second sign-up of a pending address sends the mail again. */
    public const RESEND_GAP = 600;

    /** The longest address kept: the key column's length. */
    public const MAX_LENGTH = 191;

    /** The address as stored, or null when it is not one. */
    public static function normalise(string $email): ?string
    {
        $email = strtolower(trim($email));
        if ($email === '' || strlen($email) > self::MAX_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    /**
     * Add an address to the list.
     *
     * @param bool                         $allowed false when the visitor is over the hourly limit
     * @param Closure(string,string):bool  $mail    sends the confirm mail to an address with a token
     *
     * @return string one of SENT, CONFIRMED, EXISTS, INVALID, WAIT
     */
    public static function subscribe(Store $store, string $email, string $honeypot, string $source, string $ipHash, bool $allowed, bool $doubleOptIn, int $now, Closure $mail): string
    {
        if (!$allowed) {
            return self::WAIT;
        }
        // A form a bot filled in: answer as if it worked, keep nothing.
        if (trim($honeypot) !== '') {
            return self::SENT;
        }
        $email = self::normalise($email);
        if ($email === null) {
            return self::INVALID;
        }
        $row = self::find($store, $email);
        if ($row !== null && $row['status'] === self::CONFIRMED) {
            return self::EXISTS;
        }
        if ($row !== null && $doubleOptIn && $now - $row['dt'] < self::RESEND_GAP) {
            return self::SENT;
        }

        $token = bin2hex(random_bytes(16));
        $hash  = hash('sha256', $token);
        if ($row !== null && $row['token'] !== '') {
            $store->delete(self::TOKENS, $row['token']);
        }
        $value = array(
            'status'  => $doubleOptIn ? self::PENDING : self::CONFIRMED,
            'token'   => $hash,
            'source'  => preg_match('/^[a-z0-9_-]{1,20}$/', $source) === 1 ? $source : 'home',
            'dt'      => $now,
            'ip_hash' => $ipHash,
        );
        if (!$doubleOptIn) {
            $value['confirmed_at'] = $now;
        }
        $ttl = $doubleOptIn ? self::PENDING_TTL : null;
        $store->set(self::TOKENS, $hash, $email, $ttl);
        $store->set(self::GROUP, $email, $value, $ttl);
        if (!$doubleOptIn) {
            return self::CONFIRMED;
        }
        if (!$mail($email, $token)) {
            // Nothing reached them, so nothing is kept and they may try again.
            $store->delete(self::TOKENS, $hash);
            $store->delete(self::GROUP, $email);

            return self::WAIT;
        }

        return self::SENT;
    }

    /**
     * Confirm the address a link's token belongs to. A second click on the same link is fine.
     *
     * @return string|null the address, or null when the link is wrong or has expired
     */
    public static function confirm(Store $store, string $token, int $now): ?string
    {
        $found = self::byToken($store, $token);
        if ($found === null) {
            return null;
        }
        [$email, $row] = $found;
        if ($row['status'] !== self::CONFIRMED) {
            $store->set(self::TOKENS, $row['token'], $email);
            $store->set(self::GROUP, $email, array(
                'status'       => self::CONFIRMED,
                'token'        => $row['token'],
                'source'       => $row['source'],
                'dt'           => $row['dt'],
                'ip_hash'      => $row['ip_hash'],
                'confirmed_at' => $now,
            ));
        }

        return $email;
    }

    /**
     * Take the address a link's token belongs to off the list.
     *
     * @return string|null the address removed, or null when the link is wrong
     */
    public static function unsubscribe(Store $store, string $token): ?string
    {
        $found = self::byToken($store, $token);
        if ($found === null) {
            return null;
        }
        self::remove($store, $found[0]);

        return $found[0];
    }

    /**
     * The address and row a link's token belongs to.
     *
     * @return array{0:string,1:array<string,mixed>}|null
     */
    public static function byToken(Store $store, string $token): ?array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            return null;
        }
        $hash  = hash('sha256', $token);
        $email = $store->get(self::TOKENS, $hash);
        $row   = is_string($email) ? self::find($store, $email) : null;
        if ($row === null || !hash_equals($row['token'], $hash)) {
            return null;
        }

        return array($row['email'], $row);
    }

    /** Remove an address and its link. */
    public static function remove(Store $store, string $email): bool
    {
        $email = strtolower(trim($email));
        $row   = $email === '' || strlen($email) > self::MAX_LENGTH ? null : self::find($store, $email);
        if ($row === null) {
            return false;
        }
        if ($row['token'] !== '') {
            $store->delete(self::TOKENS, $row['token']);
        }

        return $store->delete(self::GROUP, $email);
    }

    /**
     * One stored row in a fixed shape.
     *
     * @return array{email:string,status:string,token:string,source:string,dt:int,confirmed_at:int,ip_hash:string}|null
     */
    public static function find(Store $store, string $email): ?array
    {
        if ($email === '' || strlen($email) > self::MAX_LENGTH) {
            return null;
        }

        return self::shape($email, $store->get(self::GROUP, $email));
    }

    /**
     * @param mixed $value
     *
     * @return array{email:string,status:string,token:string,source:string,dt:int,confirmed_at:int,ip_hash:string}|null
     */
    public static function shape(string $email, $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        return array(
            'email'        => $email,
            'status'       => ($value['status'] ?? '') === self::CONFIRMED ? self::CONFIRMED : self::PENDING,
            'token'        => is_string($value['token'] ?? null) ? $value['token'] : '',
            'source'       => is_string($value['source'] ?? null) ? $value['source'] : '',
            'dt'           => (int) ($value['dt'] ?? 0),
            'confirmed_at' => (int) ($value['confirmed_at'] ?? 0),
            'ip_hash'      => is_string($value['ip_hash'] ?? null) ? $value['ip_hash'] : '',
        );
    }

    /**
     * One page of the list in address order, optionally only addresses starting with $prefix.
     *
     * @return array{rows:list<array<string,mixed>>,next:?string} next is the $after for the following page
     */
    public static function page(Store $store, string $prefix = '', ?string $after = null, int $limit = 50): array
    {
        $prefix = strtolower(trim($prefix));
        $rows   = array();
        // Keys starting with the prefix sort together, right after the prefix itself.
        if ($prefix !== '' && $after === null) {
            $exact = self::find($store, $prefix);
            if ($exact !== null) {
                $rows[] = $exact;
            }
            $after = $prefix;
        }
        $batch = $store->page(self::GROUP, $limit + 1 - count($rows), null, $after);
        $ended = false;
        foreach ($batch as $kv) {
            if ($prefix !== '' && strncmp($kv['key'], $prefix, strlen($prefix)) !== 0) {
                $ended = true;
                break;
            }
            $row = self::shape($kv['key'], $kv['value']);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        $next = null;
        if (!$ended && count($rows) > $limit) {
            array_pop($rows);
            $next = $rows[count($rows) - 1]['email'];
        }

        return array('rows' => $rows, 'next' => $next);
    }

    /**
     * Walk the whole list in address order, a thousand rows a read.
     *
     * @param callable(array<string,mixed>):void $each
     */
    public static function each(Store $store, callable $each): void
    {
        $after = null;
        do {
            $batch = $store->page(self::GROUP, 1000, null, $after);
            foreach ($batch as $kv) {
                $row = self::shape($kv['key'], $kv['value']);
                if ($row !== null) {
                    $each($row);
                }
                $after = $kv['key'];
            }
        } while (count($batch) === 1000);
    }

    /** @return array{confirmed:int,pending:int} */
    public static function counts(Store $store): array
    {
        $out = array('confirmed' => 0, 'pending' => 0);
        self::each($store, static function (array $row) use (&$out): void {
            $out[$row['status'] === self::CONFIRMED ? 'confirmed' : 'pending']++;
        });

        return $out;
    }
}

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
use mindstellar\privacy\PersonalData;
use mindstellar\user\UserStore;
use Throwable;

/**
 * Settings, the store, the clock and the mailer, and the personal-data hooks. Tests replace
 * the store, clock, mailer and settings.
 */
final class Plugin
{
    public const PAGE = 'shop-emails';

    /** Whether the site is a shop with carts; the cart reminder runs only then. */
    public static function shopMode(): bool
    {
        return function_exists('osc_site_mode') && osc_site_mode() === 'shop'
            && class_exists('mindstellar\\billing\\CartStore');
    }

    /** Settings used until the owner saves the page. */
    public static function defaults(): array
    {
        return array(
            'double_optin'     => true,
            'confirm_subject'  => __('Please confirm your e-mail for {WEB_TITLE}', 'shop-emails'),
            'confirm_text'     => __('Thanks for signing up to news from {WEB_TITLE}.', 'shop-emails') . "\n\n"
                . __('Please confirm this is your e-mail. If you did not ask for this, ignore this mail and you will hear nothing more.', 'shop-emails'),
            'rate_limit'       => 10,
            'reminder_on'      => true,
            'reminder_delay'   => 24,
            'reminder_max_age' => 7,
            'reminder_subject' => __('You left something in your cart', 'shop-emails'),
            'reminder_intro'   => __('Hi {USER_NAME},', 'shop-emails') . "\n\n"
                . __('You left these in your cart at {WEB_TITLE}. They are still there when you are ready.', 'shop-emails'),
            'cart_url'         => '',
            'reminder_max'     => 50,
        );
    }

    /** Replaces core's key-value store in tests. */
    public static ?Store $store = null;

    /** fn(): int -- replaces the clock in tests. */
    public static ?Closure $clock = null;

    /** fn(array $params): bool -- replaces osc_sendMail() in tests. */
    public static ?Closure $mailer = null;

    /** @var array<string,mixed>|null replaces the saved settings in tests */
    public static ?array $settings = null;

    public static function install(): void
    {
    }

    /** Remove the settings, the list, the tokens and the reminder marks. */
    public static function uninstall(): void
    {
        foreach (array_keys(self::defaults()) as $name) {
            osc_delete_preference($name, self::PAGE);
        }
        osc_delete_preference('salt', self::PAGE);
        foreach (array(Newsletter::GROUP, Newsletter::TOKENS, Reminders::MARKS, Reminders::STATE) as $group) {
            osc_kv_delete_group($group);
        }
    }

    public static function store(): Store
    {
        return self::$store ??= new KvStore();
    }

    public static function now(): int
    {
        return self::$clock !== null ? (int) (self::$clock)() : time();
    }

    /** @param array<string,mixed> $params osc_sendMail() parameters */
    public static function send(array $params): bool
    {
        if (self::$mailer !== null) {
            return (bool) (self::$mailer)($params);
        }
        try {
            // The same sender core's own shop mails use.
            if (!isset($params['from']) && class_exists('mindstellar\\mail\\CoreEmails')) {
                $params['from'] = (string) \mindstellar\mail\CoreEmails::fromAddress();
            }

            return (bool) osc_sendMail($params);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * A setting, or its default before the page is saved.
     *
     * @return mixed
     */
    public static function setting(string $name)
    {
        $value = self::$settings !== null
            ? (self::$settings[$name] ?? null)
            : (function_exists('osc_settings_value') ? osc_settings_value(self::PAGE, $name) : null);

        return $value ?? self::defaults()[$name] ?? null;
    }

    public static function flag(string $name): bool
    {
        $value = self::setting($name);

        return $value === true || $value === 1 || $value === '1';
    }

    /** A number setting kept between two limits. */
    public static function number(string $name, int $min, int $max): int
    {
        $value = self::setting($name);
        $n     = is_numeric($value) ? (int) $value : (int) self::defaults()[$name];

        return max($min, min($max, $n));
    }

    public static function text(string $name): string
    {
        $value = trim((string) self::setting($name));

        return $value !== '' ? $value : (string) self::defaults()[$name];
    }

    /** Whether an absolute address is on this site: same scheme, host and port, under its path. */
    public static function sameSite(string $url): bool
    {
        return self::under($url, (string) osc_base_url());
    }

    /** Whether $url is $base or below it. */
    public static function under(string $url, string $base): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return false;
        }
        $u = parse_url($url);
        $b = parse_url($base);
        if (!is_array($u) || !is_array($b) || !isset($u['scheme'], $u['host'], $b['scheme'], $b['host'])) {
            return false;
        }
        $port = static fn (array $p): int => (int) ($p['port'] ?? (strtolower((string) $p['scheme']) === 'https' ? 443 : 80));
        $path = rtrim((string) ($b['path'] ?? '/'), '/') . '/';

        return strtolower((string) $u['scheme']) === strtolower((string) $b['scheme'])
            && strtolower((string) $u['host']) === strtolower((string) $b['host'])
            && $port($u) === $port($b)
            && !isset($u['user'])
            && strpos(rtrim((string) ($u['path'] ?? '/'), '/') . '/', $path) === 0;
    }

    /** A short keyed hash of the visitor's address: enough to spot abuse, not to find the person. */
    public static function ipHash(string $ip): string
    {
        $salt = (string) osc_get_preference('salt', self::PAGE);
        if ($salt === '') {
            $salt = bin2hex(random_bytes(16));
            osc_set_preference('salt', $salt, self::PAGE);
        }

        return substr(hash_hmac('sha256', $ip, $salt), 0, 16);
    }

    /**
     * `personal_data_export`: the user's newsletter row and reminder mark.
     *
     * @param mixed $out
     * @param mixed $userId
     *
     * @return mixed
     */
    public static function export($out, $userId = 0)
    {
        $userId = (int) $userId;
        if (!is_array($out) || $userId < 1) {
            return $out;
        }
        $user = UserStore::find($userId);
        $row  = is_array($user) ? Newsletter::find(self::store(), strtolower(trim((string) ($user['s_email'] ?? '')))) : null;
        if ($row !== null) {
            $out['data']['shop_emails_newsletter'] = array(
                'retention' => PersonalData::ERASE_DELETED,
                'why'       => 'Their newsletter sign-up: the address, whether it is confirmed, and when.',
                'rows'      => array(array(
                    'email'        => $row['email'],
                    'status'       => $row['status'],
                    'source'       => $row['source'],
                    'signed_up'    => $row['dt'] > 0 ? gmdate('c', $row['dt']) : null,
                    'confirmed_at' => $row['confirmed_at'] > 0 ? gmdate('c', $row['confirmed_at']) : null,
                )),
            );
        }
        $mark = self::store()->get(Reminders::MARKS, (string) $userId);
        if (is_array($mark)) {
            $out['data']['shop_emails_cart_reminder'] = array(
                'retention' => PersonalData::ERASE_DELETED,
                'why'       => 'When they were last reminded about their cart, or last ordered. Kept 30 days.',
                'rows'      => array(array('kind' => (string) ($mark['why'] ?? ''), 'at' => isset($mark['at']) ? gmdate('c', (int) $mark['at']) : null)),
            );
        }

        return $out;
    }

    /**
     * `delete_user`: the reminder mark and the account address's newsletter row go with it.
     *
     * @param mixed $userId
     */
    public static function erase($userId): void
    {
        $userId = (int) $userId;
        if ($userId < 1) {
            return;
        }
        try {
            self::store()->delete(Reminders::MARKS, (string) $userId);
            $user = UserStore::find($userId);
            if (is_array($user)) {
                Newsletter::remove(self::store(), (string) ($user['s_email'] ?? ''));
            }
        } catch (Throwable $e) {
        }
    }
}

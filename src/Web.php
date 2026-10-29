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

use mindstellar\security\RateLimit;
use Params;

/**
 * The public routes: the sign-up post, and the confirm and unsubscribe links. They work on any
 * theme; the pages are drawn inside the theme's header and footer.
 */
final class Web
{
    public const SUBSCRIBE   = 'shop-emails-subscribe';
    public const CONFIRM     = 'shop-emails-confirm';
    public const UNSUBSCRIBE = 'shop-emails-unsubscribe';

    /** The form field a person never sees; a bot that fills it gets "sent" and nothing is kept. */
    public const HONEYPOT = 'website';

    /** What a page asked for when it last answered, for the views. */
    public static string $state = '';

    /** The token the unsubscribe page posts back. */
    public static string $token = '';

    /**
     * `aisle_newsletter_action`: the address the theme's sign-up form posts to.
     *
     * @param mixed $action
     */
    public static function formAction($action = ''): string
    {
        $url = (string) osc_route_url(self::SUBSCRIBE);

        return $url !== '' ? $url : (is_string($action) ? $action : '');
    }

    /** A link route's address with its token. */
    public static function url(string $route, string $token): string
    {
        $url = (string) osc_route_url($route);

        return $url . (strpos($url, '?') === false ? '?' : '&') . 'token=' . rawurlencode($token);
    }

    /** POST shop-emails/subscribe: add the address, then go back to the page with the outcome. */
    public static function subscribe(): void
    {
        if (strtoupper((string) Params::getServerParam('REQUEST_METHOD')) !== 'POST') {
            osc_redirect_to(osc_base_url());

            return;
        }
        $ip     = (string) Params::getServerParam('REMOTE_ADDR');
        $limit  = Plugin::number('rate_limit', 1, 1000);
        $status = Newsletter::subscribe(
            Plugin::store(),
            Params::getParamString('email'),
            Params::getParamString(self::HONEYPOT),
            Params::getParamString('source'),
            Plugin::ipHash($ip),
            RateLimit::hit('shop_emails_subscribe', $ip, $limit, 3600),
            Plugin::flag('double_optin'),
            Plugin::now(),
            static fn (string $email, string $token): bool => Mails::confirm($email, $token)
        );
        osc_redirect_to(self::back((string) Params::getServerParam('HTTP_REFERER'), (string) osc_base_url(), $status));
    }

    /** GET shop-emails/confirm?token=: confirm the address and say so. */
    public static function confirm(): void
    {
        $email        = Newsletter::confirm(Plugin::store(), Params::getParamString('token'), Plugin::now());
        self::$state = $email !== null ? 'confirmed' : 'invalid';
        self::page(
            $email !== null ? __('You are signed up', 'shop-emails') : __('This link does not work', 'shop-emails'),
            'confirm'
        );
    }

    /**
     * shop-emails/unsubscribe?token=: GET asks, POST removes. A mail app that opens links to
     * check them cannot take anyone off the list.
     */
    public static function unsubscribe(): void
    {
        $token       = Params::getParamString('token');
        self::$token = preg_match('/^[0-9a-f]{32}$/', $token) === 1 ? $token : '';
        if (strtoupper((string) Params::getServerParam('REQUEST_METHOD')) === 'POST') {
            self::$state = Newsletter::unsubscribe(Plugin::store(), $token) !== null ? 'removed' : 'invalid';
        } else {
            self::$state = Newsletter::byToken(Plugin::store(), $token) !== null ? 'ask' : 'invalid';
        }
        $titles = array(
            'ask'     => __('Leave the newsletter?', 'shop-emails'),
            'removed' => __('You are unsubscribed', 'shop-emails'),
            'invalid' => __('This link does not work', 'shop-emails'),
        );
        self::page($titles[self::$state], 'unsubscribe');
    }

    /**
     * The page to go back to: the referring page when it is on this site, else the home page,
     * with `newsletter=<status>` in its query.
     */
    public static function back(string $referer, string $base, string $status): string
    {
        $url = Plugin::under($referer, $base) ? $referer : $base;
        $url = (string) preg_replace('/#.*$/s', '', $url);
        $q   = strpos($url, '?');
        $out = array();
        if ($q !== false) {
            parse_str(substr($url, $q + 1), $out);
            $url = substr($url, 0, $q);
        }
        unset($out['newsletter']);
        $out['newsletter'] = $status;

        return $url . '?' . http_build_query($out, '', '&', PHP_QUERY_RFC3986);
    }

    private static function page(string $heading, string $view): void
    {
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex');
        }
        osc_gui_view('', dirname(__DIR__) . '/views/' . $view . '.php', array(
            'heading' => $heading,
            'title'   => $heading . ' - ' . osc_page_title(),
        ));
    }
}

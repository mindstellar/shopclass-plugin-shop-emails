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

/**
 * The two mails: the newsletter confirmation and the cart reminder. The owner's text is plain:
 * it is escaped, and blank lines make paragraphs. Each goes through core's mail layout.
 *
 * Filters, as core's order mails have: `shop_emails_confirm_title` and
 * `shop_emails_confirm_description` ($text, $email), `shop_emails_reminder_title` and
 * `shop_emails_reminder_description` ($text, $user, $lines). The description filters get the
 * finished HTML body.
 */
final class Mails
{
    /** Send the confirm mail with its link. */
    public static function confirm(string $email, string $token): bool
    {
        $confirm = Web::url(Web::CONFIRM, $token);
        $leave   = Web::url(Web::UNSUBSCRIBE, $token);
        $words   = self::words(array());
        $body    = self::paragraphs(Plugin::text('confirm_text'))
            . '<p><a href="' . osc_esc_html($confirm) . '"><strong>' . osc_esc_html(__('Yes, confirm my e-mail', 'shop-emails')) . '</strong></a></p>'
            . '<p><small>' . osc_esc_html(__('Changed your mind later? This link takes you off the list:', 'shop-emails'))
            . ' <a href="' . osc_esc_html($leave) . '">' . osc_esc_html(__('unsubscribe', 'shop-emails')) . '</a></small></p>';

        $subject = osc_apply_filter('email_title', osc_apply_filter('shop_emails_confirm_title', Plugin::text('confirm_subject'), $email));
        $body    = osc_apply_filter('email_description', osc_apply_filter('shop_emails_confirm_description', $body, $email));

        return Plugin::send(array(
            'to'          => $email,
            'to_name'     => '',
            'subject'     => self::subject((string) osc_mailBeauty((string) $subject, $words[0])),
            'body'        => (string) osc_mailBeauty((string) $body, $words[1]),
            'secret_link' => true,
        ));
    }

    /**
     * Send the cart reminder.
     *
     * @param array{email:string,name:string}                                $user
     * @param list<array{title:string,options:string,qty:int,price:string}>  $lines at most five
     * @param int                                                            $more  lines not shown
     */
    public static function reminder(array $user, array $lines, int $more): bool
    {
        $items = '';
        foreach ($lines as $line) {
            $items .= '<li><strong>' . osc_esc_html($line['title']) . '</strong>'
                . ($line['options'] !== '' ? ' (' . osc_esc_html($line['options']) . ')' : '')
                . ' · ' . osc_esc_html(sprintf(__('Qty %d', 'shop-emails'), $line['qty']))
                . ' · ' . osc_esc_html($line['price']) . '</li>';
        }
        $list = '<ul>' . $items . '</ul>';
        if ($more > 0) {
            $list .= '<p>' . osc_esc_html(sprintf(__('And %d more in your cart.', 'shop-emails'), $more)) . '</p>';
        }
        $url  = self::cartUrl();
        $body = self::paragraphs(Plugin::text('reminder_intro'))
            . $list
            . '<p><a href="' . osc_esc_html($url) . '"><strong>' . osc_esc_html(__('Go to your cart', 'shop-emails')) . '</strong></a></p>'
            . '<p><small>' . osc_esc_html(__('We send this reminder once. Prices and stock are checked again when you order.', 'shop-emails')) . '</small></p>';

        $name    = $user['name'] !== '' ? $user['name'] : $user['email'];
        $words   = self::words(array('{USER_NAME}' => $name));
        $subject = osc_apply_filter('email_title', osc_apply_filter('shop_emails_reminder_title', Plugin::text('reminder_subject'), $user, $lines));
        $body    = osc_apply_filter('email_description', osc_apply_filter('shop_emails_reminder_description', $body, $user, $lines));

        return Plugin::send(array(
            'to'      => $user['email'],
            'to_name' => $user['name'],
            'subject' => self::subject((string) osc_mailBeauty((string) $subject, $words[0])),
            'body'    => (string) osc_mailBeauty((string) $body, $words[1]),
        ));
    }

    /** Where the reminder's button goes: the owner's cart link on this site, else the home page's #cart, which opens Aisle's cart. */
    public static function cartUrl(): string
    {
        $url = trim((string) Plugin::setting('cart_url'));

        return $url !== '' && Plugin::sameSite($url) ? $url : (string) osc_base_url() . '#cart';
    }

    /** Plain text as escaped HTML paragraphs: a blank line starts a paragraph, a line break stays. */
    public static function paragraphs(string $text): string
    {
        $out = '';
        foreach (preg_split('/\R\s*\R/', trim($text)) ?: array() as $para) {
            $para = trim($para);
            if ($para !== '') {
                $out .= '<p>' . nl2br(osc_esc_html($para), false) . '</p>';
            }
        }

        return $out;
    }

    /** A subject on one line, with no control characters. */
    public static function subject(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text));
    }

    /**
     * The placeholders: plain values for the subject, escaped ones for the body. They come
     * before core's site-wide words, so {WEB_TITLE} is escaped in the body.
     *
     * @param array<string,string> $extra
     *
     * @return array{0:array{0:string[],1:string[]},1:array{0:string[],1:string[]}}
     */
    private static function words(array $extra): array
    {
        $plain = $extra + array('{WEB_TITLE}' => (string) osc_page_title());
        $html  = array_map(static fn (string $v): string => osc_esc_html($v), $plain);

        return array(
            array(array_keys($plain), array_values($plain)),
            array(array_keys($html), array_values($html)),
        );
    }
}

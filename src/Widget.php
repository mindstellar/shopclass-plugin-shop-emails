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

use Params;

/**
 * The sign-up form as a widget, so any theme with a widget area can show it.
 */
final class Widget
{
    public const TYPE = 'shop_emails.newsletter';

    /** Register the widget type. */
    public static function register(): void
    {
        if (!function_exists('osc_register_widget')) {
            return;
        }
        osc_register_widget(self::TYPE, array(
            'label'       => __('Newsletter sign-up', 'shop-emails'),
            'description' => __('An e-mail field and a button that add the visitor to the newsletter list.', 'shop-emails'),
            'fields'      => array(
                array('name' => 'title', 'label' => __('Heading', 'shop-emails'), 'type' => 'text', 'default' => __('Newsletter', 'shop-emails')),
                array('name' => 'label', 'label' => __('E-mail field label', 'shop-emails'), 'type' => 'text', 'default' => __('Your e-mail', 'shop-emails')),
                array('name' => 'button', 'label' => __('Button text', 'shop-emails'), 'type' => 'text', 'default' => __('Sign up', 'shop-emails')),
            ),
            'render'      => array(self::class, 'render'),
        ));
    }

    /**
     * The form, with the answer to the last sign-up above it.
     *
     * @param array<string,mixed> $config
     */
    public static function render($config = array()): string
    {
        $config = is_array($config) ? $config : array();
        $text   = static function (string $key, string $default) use ($config): string {
            $value = isset($config[$key]) && is_string($config[$key]) ? trim($config[$key]) : '';

            return $value !== '' ? $value : $default;
        };
        $heading = $text('title', __('Newsletter', 'shop-emails'));
        $label   = $text('label', __('Your e-mail', 'shop-emails'));
        $button  = $text('button', __('Sign up', 'shop-emails'));
        $answer  = self::answers()[Params::getParamString('newsletter')] ?? '';

        return '<section class="shop-emails-widget">'
            . '<h3 class="shop-emails-title">' . osc_esc_html($heading) . '</h3>'
            . ($answer !== '' ? '<p class="shop-emails-answer" role="status">' . osc_esc_html($answer) . '</p>' : '')
            . '<form method="post" action="' . osc_esc_html(Web::formAction('')) . '">'
            . '<label>' . osc_esc_html($label) . ' '
            . '<input type="email" name="email" maxlength="191" required autocomplete="email"></label> '
            . '<span style="position:absolute;left:-9999px" aria-hidden="true">'
            . '<input type="text" name="' . Web::HONEYPOT . '" value="" tabindex="-1" autocomplete="off"></span>'
            . '<input type="hidden" name="source" value="widget">'
            . '<button type="submit">' . osc_esc_html($button) . '</button>'
            . '</form></section>';
    }

    /** @return array<string,string> the `?newsletter=` status => what to tell the visitor */
    private static function answers(): array
    {
        return array(
            'sent'      => __('Check your e-mail to confirm.', 'shop-emails'),
            'confirmed' => __('You are signed up.', 'shop-emails'),
            'exists'    => __('This address is already signed up.', 'shop-emails'),
            'invalid'   => __('Please enter a valid e-mail address.', 'shop-emails'),
            'wait'      => __('Please wait a while and try again.', 'shop-emails'),
        );
    }
}

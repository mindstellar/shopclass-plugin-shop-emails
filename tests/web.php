<?php
/*
 * This file is part of the Shop emails plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The way back after a sign-up (same site only), the mail text helpers and the CSV cells.
 */

require __DIR__ . '/lib/bootstrap.php';

use mindstellar\shopemails\Admin\Csv;
use mindstellar\shopemails\Mails;
use mindstellar\shopemails\Plugin;
use mindstellar\shopemails\Web;
use mindstellar\shopemails\Widget;

$base = 'https://shop.example/';

harness_section('back to the page');
pin('the referring page gets the status', 'https://shop.example/?newsletter=sent', Web::back('https://shop.example/', $base, 'sent'));
pin('its query is kept, an old status replaced, the fragment dropped', 'https://shop.example/search?q=tea&newsletter=wait',
    Web::back('https://shop.example/search?q=tea&newsletter=sent#news', $base, 'wait'));
pin('no referer goes home', 'https://shop.example/?newsletter=invalid', Web::back('', $base, 'invalid'));
foreach (array(
    'another site'          => 'https://evil.example/',
    'a look-alike host'     => 'https://shop.example.evil.test/',
    'another scheme'        => 'http://shop.example/',
    'another port'          => 'https://shop.example:8443/',
    'user info'             => 'https://shop.example@evil.example/',
    'a protocol-relative'   => '//evil.example/',
    'javascript'            => 'javascript:alert(1)',
    'a backslash trick'     => 'https://shop.example\\@evil.example/',
    'a header break'        => "https://shop.example/\r\nSet-Cookie: x=1",
) as $label => $referer) {
    pin($label . ' goes home', 'https://shop.example/?newsletter=sent', Web::back($referer, $base, 'sent'));
}
pin('a site in a folder keeps to it', 'https://h.example/shop/?newsletter=sent', Web::back('https://h.example/other/', 'https://h.example/shop/', 'sent'));
pin('and accepts its own pages', 'https://h.example/shop/item/1?newsletter=sent', Web::back('https://h.example/shop/item/1', 'https://h.example/shop/', 'sent'));
check('the default port is the same port', Plugin::under('https://shop.example:443/x', $base));

harness_section('mail text');
pin('plain text becomes escaped paragraphs', "<p>Hi &lt;b&gt;you&lt;/b&gt;,<br>\nline two</p><p>Bye &amp; thanks</p>",
    Mails::paragraphs("Hi <b>you</b>,\nline two\n\n\nBye & thanks"));
pin('a subject is one line', 'Hello Bcc: x@example.test', Mails::subject("Hello\r\nBcc: x@example.test"));

harness_section('the form');
pin('the theme form posts to the subscribe route', 'https://shop.example/route/shop-emails-subscribe', Web::formAction(''));

harness_section('the widget');
$html = Widget::render(array('title' => '<b>News</b>', 'label' => 'Mail "me"', 'button' => '<Go>'));
check('posts to the subscribe route', strpos($html, '<form method="post" action="https://shop.example/route/shop-emails-subscribe">') !== false, $html);
check('the heading is escaped', strpos($html, '&lt;b&gt;News&lt;/b&gt;') !== false && strpos($html, '<b>News') === false, $html);
check('the label is escaped', strpos($html, 'Mail &quot;me&quot;') !== false, $html);
check('the button is escaped', strpos($html, '&lt;Go&gt;</button>') !== false, $html);
check('has the honeypot and the email field', strpos($html, 'name="website"') !== false && strpos($html, 'name="email"') !== false, $html);
check('blank settings use the defaults', strpos(Widget::render(array('button' => ' ')), '>Sign up</button>') !== false);
$GLOBALS['request']['newsletter'] = 'sent';
check('shows the answer to the last sign-up', strpos(Widget::render(array()), 'Check your e-mail to confirm.') !== false);
$GLOBALS['request']['newsletter'] = '<script>';
check('an unknown status shows nothing', strpos(Widget::render(array()), 'shop-emails-answer') === false);

harness_section('CSV');
pin('a plain row', "sue@example.test,confirmed,home,2025-10-09 08:53:20,\r\n",
    Csv::row(array('email' => 'sue@example.test', 'status' => 'confirmed', 'source' => 'home', 'dt' => 1760000000, 'confirmed_at' => 0)));
pin('a cell a spreadsheet would run is made text', "\"'=HYPERLINK(\"\"x\"\")\",'+1,'-2,'@x\r\n", Csv::line(array('=HYPERLINK("x")', '+1', '-2', '@x')));
pin('a comma is quoted', "\"a,b\"\r\n", Csv::line(array('a,b')));

exit(harness_result());

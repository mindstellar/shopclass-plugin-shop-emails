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

namespace mindstellar\shopemails\Admin;

/**
 * The subscriber export as CSV. A cell that a spreadsheet would read as a formula gets a
 * leading apostrophe.
 */
final class Csv
{
    public const HEADER = array('email', 'status', 'source', 'signed_up', 'confirmed_at');

    /** @param array<string,mixed> $row a Newsletter row */
    public static function row(array $row): string
    {
        return self::line(array(
            (string) $row['email'],
            (string) $row['status'],
            (string) $row['source'],
            $row['dt'] > 0 ? gmdate('Y-m-d H:i:s', (int) $row['dt']) : '',
            $row['confirmed_at'] > 0 ? gmdate('Y-m-d H:i:s', (int) $row['confirmed_at']) : '',
        ));
    }

    /** @param string[] $cells */
    public static function line(array $cells): string
    {
        $out = array();
        foreach ($cells as $cell) {
            $cell = (string) preg_replace('/[\r\n]+/', ' ', $cell);
            if ($cell !== '' && strpbrk($cell[0], "=+-@\t") !== false) {
                $cell = "'" . $cell;
            }
            $out[] = preg_match('/[",]/', $cell) ? '"' . str_replace('"', '""', $cell) . '"' : $cell;
        }

        return implode(',', $out) . "\r\n";
    }
}

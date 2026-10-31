<?php

namespace App\Support;

use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads the first sheet of an .xlsx / .csv into rows keyed by header name,
 * and turns cells typed the Indonesian way (1.250.000, 29/10/2026, 14.32)
 * into plain values.
 */
class SpreadsheetRows
{
    /**
     * Rows below the first row that contains $anchor, keyed by the header
     * (upper case, single spaces). Fully empty rows are left out.
     *
     * @return array<int, array<string, mixed>> keyed by spreadsheet row number
     */
    public static function read(string $path, string $anchor, ?string $extension = null): array
    {
        $extension = strtolower($extension ?? pathinfo($path, PATHINFO_EXTENSION));
        $reader = $extension === 'csv' ? new CsvReader(self::csvOptions($path)) : new XlsxReader;
        $reader->open($path);

        $header = null;
        $rows = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $number => $row) {
                    $values = $row->toArray();

                    if ($header === null) {
                        $names = array_map(fn ($value): string => self::headerName($value), $values);
                        $header = in_array($anchor, $names, true) ? $names : null;

                        continue;
                    }

                    $assoc = [];

                    foreach ($header as $i => $name) {
                        if ($name !== '' && ! array_key_exists($name, $assoc)) {
                            $assoc[$name] = self::clean($values[$i] ?? null);
                        }
                    }

                    if (array_filter($assoc, fn ($value): bool => $value !== null) !== []) {
                        $rows[$number] = $assoc;
                    }
                }

                break; // first sheet only
            }
        } finally {
            $reader->close();
        }

        return $header === null ? [] : $rows;
    }

    public static function headerName(mixed $value): string
    {
        return is_string($value) ? strtoupper(trim(preg_replace('/\s+/u', ' ', $value))) : '';
    }

    /**
     * 1.250.000 · 1,250,000.50 · Rp 1.250.000 · -500 · 1500.5 · 5% → "1250000.00"
     * style strings; empty or "-" → null; anything else → false.
     */
    public static function money(mixed $value): string|false|null
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        $text = str_ireplace(['rp', '%', ' ', "\u{00A0}"], '', (string) $value);

        if ($text === '' || $text === '-') {
            return null;
        }

        $lastDot = strrpos($text, '.');
        $lastComma = strrpos($text, ',');

        if ($lastDot !== false && $lastComma !== false) {
            // Both: the later one is the decimal separator.
            $text = $lastComma > $lastDot
                ? str_replace(['.', ','], ['', '.'], $text)
                : str_replace(',', '', $text);
        } elseif ($lastDot !== false) {
            $text = preg_match('/^-?\d{1,3}(\.\d{3})+$/', $text) ? str_replace('.', '', $text) : $text;
        } elseif ($lastComma !== false) {
            $text = preg_match('/^-?\d{1,3}(,\d{3})+$/', $text) ? str_replace(',', '', $text) : str_replace(',', '.', $text);
        }

        return is_numeric($text) ? number_format((float) $text, 2, '.', '') : false;
    }

    /**
     * A date cell, an Excel serial number, or 29/10/2026, 29-10-2026,
     * 2026-10-29 (a time after it is allowed) → Carbon; unreadable → false.
     */
    public static function date(mixed $value): Carbon|false|null
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_int($value) || is_float($value)) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->addSeconds((int) round(fmod((float) $value, 1) * 86400));
        }

        $text = trim((string) $value);

        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'd/m/y', 'd-m-y'] as $format) {
            foreach (['', ' H:i', ' H:i:s', ' H.i', ' H.i.s'] as $time) {
                try {
                    $date = Carbon::createFromFormat('!'.$format.$time, $text);
                } catch (InvalidFormatException) {
                    continue;
                }

                if ($date && $date->format($format.$time) === $text) {
                    return $date;
                }
            }
        }

        return false;
    }

    /**
     * A time / date-time cell, an Excel day fraction, or 14:32, 14:32:10,
     * 14.32 → "14:32:10"; unreadable → false.
     */
    public static function time(mixed $value): string|false|null
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('H:i:s');
        }

        if (is_int($value) || is_float($value)) {
            $seconds = (int) round(fmod((float) $value, 1) * 86400) % 86400;

            return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        }

        if (preg_match('/(\d{1,2})[:.](\d{2})(?:[:.](\d{2}))?\s*$/', trim((string) $value), $m) && (int) $m[1] < 24 && (int) $m[2] < 60) {
            return sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3] ?? 0);
        }

        return false;
    }

    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $text = trim(is_float($value) && floor($value) === $value ? (string) (int) $value : (string) $value);

        return $text === '' ? null : $text;
    }

    private static function clean(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }

    /**
     * Excel in Indonesian Windows saves CSV with ";" between fields.
     */
    private static function csvOptions(string $path): CsvOptions
    {
        $options = new CsvOptions;
        $handle = fopen($path, 'r');
        $first = (string) fgets($handle);
        fclose($handle);
        $options->FIELD_DELIMITER = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        return $options;
    }
}

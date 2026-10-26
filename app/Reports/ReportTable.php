<?php

namespace App\Reports;

/**
 * What a laporan shows: the same object feeds the screen, Excel and PDF.
 *
 * A row is an array keyed by column key, plus optional:
 *   _style  'section' | 'total' | 'muted'
 *   _warn   list of column keys to highlight (e.g. a non-zero selisih)
 */
final class ReportTable
{
    /**
     * @param  array<int, ReportColumn>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $notes
     */
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $notes = [],
        public readonly bool $landscape = false,
    ) {}

    /**
     * Rows keyed 1..n, as Filament's custom-data tables expect stable keys.
     *
     * @return array<string, array<string, mixed>>
     */
    public function keyedRows(): array
    {
        $keyed = [];

        foreach (array_values($this->rows) as $i => $row) {
            $keyed['r'.($i + 1)] = $row;
        }

        return $keyed;
    }

    public function fileName(): string
    {
        return str($this->title.' '.$this->subtitle)->slug()->toString();
    }

    /**
     * Sum the given numeric columns over the plain rows (sections and totals
     * excluded) into a TOTAL row.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public static function totalRow(array $rows, array $keys, string $labelKey, string $label = 'TOTAL'): array
    {
        $total = [$labelKey => $label, '_style' => 'total'];

        foreach ($keys as $key) {
            $total[$key] = array_reduce(
                array_filter($rows, fn (array $row): bool => ! isset($row['_style'])),
                fn (string $sum, array $row) => bcadd($sum, (string) ($row[$key] ?? 0), 2),
                '0',
            );
        }

        return $total;
    }
}

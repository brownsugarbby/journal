<?php

namespace App\Reports;

/**
 * One column of a laporan. `money` and `number` are numeric in Excel and
 * right-aligned on screen and in PDF.
 */
final class ReportColumn
{
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly int $decimals = 0,
    ) {}

    public static function text(string $key, string $label): self
    {
        return new self($key, __($label), 'text');
    }

    public static function money(string $key, string $label, int $decimals = 0): self
    {
        return new self($key, __($label), 'money', $decimals);
    }

    public static function number(string $key, string $label): self
    {
        return new self($key, __($label), 'number');
    }

    public function isNumeric(): bool
    {
        return $this->type !== 'text';
    }

    /**
     * Display value: 1.250.000 for money, as-is for text.
     */
    public function format(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->isNumeric() && is_numeric($value)
            ? number_format((float) $value, $this->decimals, ',', '.')
            : (string) $value;
    }
}

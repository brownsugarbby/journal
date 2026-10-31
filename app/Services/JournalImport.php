<?php

namespace App\Services;

use App\Enums\BonusKind;
use App\Enums\JournalType;
use App\Models\BonusType;
use App\Models\Brand;
use App\Models\CompanyAccount;
use App\Models\Journal;
use App\Models\User;
use App\Support\SpreadsheetRows as Cell;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Bulk entry from Excel instead of row by row: a day of PGA (QRIS) rows on a
 * gateway account, or a week of cashback for a brand. Columns are found by
 * header name, so the order may differ and extra columns are ignored.
 *
 * All or nothing: if one row is wrong nothing is saved and every problem is
 * listed with its Excel row number. Rows imported before (same file
 * uploaded twice) are skipped, never doubled.
 */
class JournalImport
{
    /** Same order as the sheet, so the PGA's green columns paste in one block. */
    public const GATEWAY_COLUMNS = ['TGL', 'NAMA REKENING', 'KELUAR', 'MASUK', 'POTONGAN', 'SISA WD', 'SISA DP', 'SALDO', 'USERNAME', 'WAKTU', 'OP', 'KETERANGAN'];

    public const CASHBACK_COLUMNS = ['TGL', 'NAMA BONUS', 'WINLOSE', '%', 'BONUS', 'USERNAME', 'KETERANGAN'];

    private const MAX_ERRORS = 20;

    public function __construct(private readonly JournalService $journals) {}

    /**
     * KELUAR = WD, MASUK = DP (gross, POTONGAN apart). SISA WD / SISA DP are
     * recalculated; SALDO of the latest row is returned for comparison.
     */
    public function gateway(CompanyAccount $account, string $path, User $user, ?string $extension = null): ImportResult
    {
        $rows = $this->rows($path, $extension, ['TGL', 'USERNAME'], ['KELUAR', 'MASUK']);
        $brands = Brand::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [strtoupper($code) => $id]);
        $errors = [];
        $entries = [];
        $latest = null;

        foreach ($rows as $line => $row) {
            if ($this->isNotEntry($row)) {
                continue;
            }

            $problems = [];
            [$date, $time] = $this->dateAndTime($row, $problems);
            $keluar = $this->amount($row, 'KELUAR', $problems);
            $masuk = $this->amount($row, 'MASUK', $problems);
            $fee = $this->amount($row, 'POTONGAN', $problems);
            $username = Cell::text($row['USERNAME'] ?? null);

            if ($username === null) {
                $problems[] = __('USERNAME is empty');
            }

            if ($keluar !== null && $masuk !== null) {
                $problems[] = __('fill KELUAR or MASUK, not both');
            } elseif ($keluar === null && $masuk === null && ! array_filter($problems)) {
                $problems[] = __('KELUAR and MASUK are both empty');
            }

            $brandId = $account->brand_id;

            if ($brandId === null) {
                $code = strtoupper((string) Cell::text($row['BRAND'] ?? null));
                $brandId = $brands[$code] ?? null;

                if ($brandId === null) {
                    $problems[] = __('BRAND column is needed on a shared account (e.g. KES)');
                }
            }

            if ($problems !== []) {
                $errors[] = $this->rowError($line, $problems);

                continue;
            }

            $type = $keluar !== null ? JournalType::Withdraw : JournalType::Deposit;
            $entries[] = [$line, $type, [
                'entry_date' => $date->toDateString(),
                'company_account_id' => $account->id,
                'brand_id' => $brandId,
                'member_id' => $username,
                'member_account_name' => Cell::text($row['NAMA REKENING'] ?? null),
                'amount' => $keluar ?? $masuk,
                'fee' => $fee ?? 0,
                'note' => Cell::text($row['KETERANGAN'] ?? null),
                'source_time' => $time,
                'source_operator' => mb_substr((string) Cell::text($row['OP'] ?? null), 0, 50) ?: null,
            ]];

            $saldo = Cell::money($row['SALDO'] ?? null);
            $moment = $date->toDateString().' '.($time ?? '00:00:00');

            if (is_string($saldo) && ($latest === null || $moment >= $latest[0])) {
                $latest = [$moment, $saldo];
            }
        }

        $this->failIf($errors);

        [$imported, $skipped] = $this->post($entries, $user, fn (array $data): array => [
            'gateway', $account->id, $data['entry_date'], $data['source_time'], strtolower($data['member_id']),
            $data['amount'], $data['fee'], $data['brand_id'],
        ]);

        return new ImportResult($imported, $skipped, $latest[1] ?? null);
    }

    /**
     * BONUS = WINLOSE × %. NAMA BONUS may be empty when the brand has only one
     * cashback type; % may be empty (the type's default). Only a superadmin
     * or leader may use a different %, like on the entry row.
     */
    public function cashback(Brand $brand, string $path, User $user, ?string $extension = null): ImportResult
    {
        $rows = $this->rows($path, $extension, ['TGL', 'WINLOSE', 'USERNAME']);
        $types = $this->cashbackTypes($brand);
        $errors = [];
        $entries = [];

        foreach ($rows as $line => $row) {
            if ($this->isNotEntry($row)) {
                continue;
            }

            $problems = [];
            [$date] = $this->dateAndTime($row, $problems);
            $winlose = $this->amount($row, 'WINLOSE', $problems);
            $percentage = $this->amount($row, '%', $problems);
            $username = Cell::text($row['USERNAME'] ?? null);
            $name = Cell::text($row['NAMA BONUS'] ?? null);

            $type = $name === null
                ? ($types->count() === 1 ? $types->first() : null)
                : $types->first(fn (BonusType $type): bool => strcasecmp($type->name, $name) === 0);

            if ($type === null) {
                $problems[] = $name === null
                    ? __('NAMA BONUS is empty (choose one of: :names)', ['names' => $types->pluck('name')->join(', ')])
                    : __('NAMA BONUS ":name" is not a cashback type of :brand', ['name' => $name, 'brand' => $brand->code]);
            }

            if ($username === null) {
                $problems[] = __('USERNAME is empty');
            }

            if ($winlose === null && ! array_filter($problems)) {
                $problems[] = __('WINLOSE is empty');
            }

            if ($type && $percentage !== null && bccomp($percentage, (string) $type->default_percentage, 2) !== 0 && ! $user->hasFullAccess()) {
                $problems[] = __('only a superadmin or leader may change % (default :percentage)', ['percentage' => (float) $type->default_percentage]);
            }

            if ($problems !== []) {
                $errors[] = $this->rowError($line, $problems);

                continue;
            }

            $entries[] = [$line, JournalType::Cashback, [
                'entry_date' => $date->toDateString(),
                'brand_id' => $brand->id,
                'bonus_type_id' => $type->id,
                'member_id' => $username,
                'winlose' => $winlose,
                'percentage' => $percentage ?? $type->default_percentage,
                'note' => Cell::text($row['KETERANGAN'] ?? null),
            ]];
        }

        $this->failIf($errors);

        [$imported, $skipped] = $this->post($entries, $user, fn (array $data): array => [
            'cashback', $brand->id, $data['entry_date'], strtolower($data['member_id']),
            $data['bonus_type_id'], $data['winlose'], (string) $data['percentage'],
        ]);

        return new ImportResult($imported, $skipped);
    }

    /**
     * An empty .xlsx with the header row only.
     */
    public function template(string $kind): string
    {
        $path = tempnam(sys_get_temp_dir(), 'template').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($kind === 'cashback' ? self::CASHBACK_COLUMNS : self::GATEWAY_COLUMNS, (new Style)->setFontBold()));
        $writer->close();

        return $path;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $path, ?string $extension, array $required, array $oneOf = []): array
    {
        $rows = Cell::read($path, 'TGL', $extension);
        $columns = array_keys(array_merge(...array_values($rows) ?: [[]]));

        if ($rows === []) {
            $this->failIf([__('No data found: the first sheet needs a header row with TGL and rows below it.')]);
        }

        $missing = array_values(array_diff($required, $columns));

        if ($oneOf !== [] && array_intersect($oneOf, $columns) === []) {
            $missing[] = implode(' / ', $oneOf);
        }

        if ($missing !== []) {
            $this->failIf([__('Missing column(s): :columns. Use the template.', ['columns' => implode(', ', $missing)])]);
        }

        return $rows;
    }

    /**
     * Rows with neither TGL nor USERNAME (totals, notes under the table).
     *
     * @param  array<string, mixed>  $row
     */
    private function isNotEntry(array $row): bool
    {
        return ($row['TGL'] ?? null) === null && ($row['USERNAME'] ?? null) === null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $problems
     * @return array{0: ?Carbon, 1: ?string}
     */
    private function dateAndTime(array $row, array &$problems): array
    {
        $date = Cell::date($row['TGL'] ?? null);

        if (! $date) {
            $problems[] = $date === null ? __('TGL is empty') : __('TGL ":value" is not a date (use 29/10/2026)', ['value' => Cell::text($row['TGL'])]);

            return [null, null];
        }

        $time = Cell::time($row['WAKTU'] ?? null);

        if ($time === false) {
            $problems[] = __('WAKTU ":value" is not a time (use 14:32)', ['value' => Cell::text($row['WAKTU'])]);
            $time = null;
        }

        // A TGL with a time in it (29/10/2026 14:32) also gives the WAKTU.
        $time ??= $date->format('H:i:s') !== '00:00:00' ? $date->format('H:i:s') : null;

        return [$date, $time];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $problems
     */
    private function amount(array $row, string $column, array &$problems): ?string
    {
        $value = Cell::money($row[$column] ?? null);

        if ($value === false) {
            $problems[] = __(':column ":value" is not a number', ['column' => $column, 'value' => Cell::text($row[$column])]);

            return null;
        }

        if ($value !== null && bccomp($value, '0', 2) < 0) {
            $problems[] = __(':column cannot be negative', ['column' => $column]);

            return null;
        }

        return $value !== null && bccomp($value, '0', 2) === 0 ? null : $value;
    }

    /**
     * Post every row in one transaction; skip rows already imported.
     *
     * @param  array<int, array{0: int, 1: JournalType, 2: array<string, mixed>}>  $entries
     * @param  callable(array<string, mixed>): array<int, mixed>  $identity  what makes two rows the same
     * @return array{0: int, 1: int}
     */
    private function post(array $entries, User $user, callable $identity): array
    {
        // Identical rows in one file are real (two DPs of 50.000): number them.
        $seen = [];

        foreach ($entries as $i => [, , $data]) {
            $base = implode('|', $identity($data));
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $entries[$i][2]['import_key'] = hash('sha256', $base.'|'.$seen[$base]);
        }

        $existing = Journal::query()->whereIn('import_key', array_map(fn (array $entry): string => $entry[2]['import_key'], $entries))->pluck('import_key')->flip();

        return DB::transaction(function () use ($entries, $existing, $user): array {
            $imported = 0;
            $errors = [];

            foreach ($entries as [$line, $type, $data]) {
                if (isset($existing[$data['import_key']])) {
                    continue;
                }

                try {
                    $this->journals->post($type, $data, $user);
                    $imported++;
                } catch (ValidationException $exception) {
                    $errors[] = $this->rowError($line, collect($exception->errors())->flatten()->all());
                }
            }

            $this->failIf($errors);

            return [$imported, count($entries) - $imported];
        });
    }

    /**
     * @return Collection<int, BonusType>
     */
    private function cashbackTypes(Brand $brand): Collection
    {
        return BonusType::query()
            ->where('kind', BonusKind::Cashback)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('brand_id')->orWhere('brand_id', $brand->id))
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<int, string>  $problems
     */
    private function rowError(int $line, array $problems): string
    {
        return __('Row :row: :problems', ['row' => $line, 'problems' => implode('; ', $problems)]);
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function failIf(array $errors): void
    {
        if ($errors === []) {
            return;
        }

        $shown = array_slice($errors, 0, self::MAX_ERRORS);

        if (count($errors) > self::MAX_ERRORS) {
            $shown[] = __('…and :count more.', ['count' => count($errors) - self::MAX_ERRORS]);
        }

        throw ValidationException::withMessages(['file' => $shown]);
    }
}

<?php

namespace App\Support;

use App\Enums\PayColumnKind;
use App\Models\PayColumn;
use App\Models\PayItem;
use App\Models\PayRun;
use App\Services\PayrollService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payslips (one page per employee) and the pay run recap, as PDF downloads.
 */
class PayrollPdf
{
    public function payslips(PayRun $run, ?PayItem $only = null): StreamedResponse
    {
        $items = $run->items()
            ->with(['employee.company', 'employee.bank', 'currency', 'values.payColumn'])
            ->when($only, fn ($query) => $query->whereKey($only->id))
            ->orderBy('id')
            ->get();

        $name = $only ? "slip-{$run->pay_type->value}-{$run->year}-{$run->month}-".Str::slug($only->employee->name) : "slips-{$run->pay_type->value}-{$run->year}-{$run->month}";

        return $this->download('pdf.payslips', [
            'run' => $run,
            'items' => $items,
            'columns' => $this->columns($run),
        ], $name);
    }

    public function recap(PayRun $run): StreamedResponse
    {
        return $this->download('pdf.pay-run-recap', [
            'run' => $run,
            'items' => $run->items()->with(['employee.company', 'employee.bank', 'currency', 'values.payColumn'])->orderBy('id')->get(),
            'columns' => $this->columns($run),
            'totals' => app(PayrollService::class)->totals($run),
        ], "recap-{$run->pay_type->value}-{$run->year}-{$run->month}", landscape: true);
    }

    /**
     * @return Collection<int, PayColumn>
     */
    private function columns(PayRun $run): Collection
    {
        return PayColumn::query()->where('pay_type', $run->pay_type)->where('is_active', true)->orderBy('sort_order')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function download(string $view, array $data, string $name, bool $landscape = false): StreamedResponse
    {
        $settings = app(AppSettings::class);
        $logo = $settings->get('company_logo');

        $pdf = Pdf::loadView($view, [
            ...$data,
            'company' => $settings->get('company_name'),
            'header' => $settings->get('pdf_header'),
            'logo' => $logo && Storage::disk('public')->exists($logo) ? Storage::disk('public')->path($logo) : null,
            'additionKind' => PayColumnKind::Addition,
            'deductionKind' => PayColumnKind::Deduction,
        ])->setPaper('a4', $landscape ? 'landscape' : 'portrait');

        return response()->streamDownload(fn () => print ($pdf->output()), "{$name}.pdf", ['Content-Type' => 'application/pdf']);
    }
}

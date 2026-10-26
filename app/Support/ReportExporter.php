<?php

namespace App\Support;

use App\Reports\ReportTable;
use Barryvdh\DomPDF\Facade\Pdf;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a laporan as Excel (.xlsx, numbers stay numbers) or PDF.
 */
class ReportExporter
{
    public function excel(ReportTable $report): StreamedResponse
    {
        return response()->streamDownload(function () use ($report): void {
            // Build in a temp file, then stream it: writing to php://output
            // directly bypasses output buffering.
            $path = tempnam(sys_get_temp_dir(), 'laporan');
            $writer = new Writer;
            $writer->openToFile($path);

            $bold = (new Style)->setFontBold();
            $writer->addRow(Row::fromValues([$report->title], $bold));
            $writer->addRow(Row::fromValues([$report->subtitle]));
            $writer->addRow(Row::fromValues([]));
            $writer->addRow(Row::fromValues(array_map(fn ($column) => $column->label, $report->columns), (new Style)->setFontBold()->setBackgroundColor('D1FAE5')));

            foreach ($report->rows as $row) {
                $style = in_array($row['_style'] ?? null, ['section', 'total'], true) ? (new Style)->setFontBold() : null;
                $cells = [];

                foreach ($report->columns as $column) {
                    $value = $row[$column->key] ?? null;

                    if ($column->isNumeric() && is_numeric($value)) {
                        $cells[] = Cell::fromValue((float) $value, (new Style)->setFormat($column->decimals > 0 ? '#,##0.'.str_repeat('0', $column->decimals) : '#,##0'));
                    } else {
                        $cells[] = Cell::fromValue($value === null ? '' : (string) $value);
                    }
                }

                $writer->addRow(new Row($cells, $style));
            }

            foreach ($report->notes as $note) {
                $writer->addRow(Row::fromValues([]));
                $writer->addRow(Row::fromValues([$note]));
            }

            $writer->close();

            readfile($path);
            unlink($path);
        }, $report->fileName().'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function pdf(ReportTable $report): StreamedResponse
    {
        $settings = app(AppSettings::class);

        $pdf = Pdf::loadView('pdf.report', [
            'report' => $report,
            'company' => $settings->get('company_name'),
            'header' => $settings->get('pdf_header'),
        ])->setPaper('a4', $report->landscape ? 'landscape' : 'portrait');

        return response()->streamDownload(fn () => print ($pdf->output()), $report->fileName().'.pdf', ['Content-Type' => 'application/pdf']);
    }
}

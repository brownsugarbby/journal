<?php

namespace App\Console\Commands;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Turns the Markdown docs into printable PDFs in docs/pdf/, so the manual,
 * UAT checklist and deploy guide can be handed to H D. Re-run after editing.
 */
#[Signature('docs:pdf')]
#[Description('Build docs/pdf/*.pdf from the Markdown docs')]
class BuildDocsPdf extends Command
{
    /**
     * Markdown source => [PDF name, title, landscape].
     *
     * @var array<string, array{string, string, bool}>
     */
    private const DOCS = [
        'MANUAL-MASTER-DATA.md' => ['Manual-Master-Data.pdf', 'User Manual — Master Data', false],
        'UAT.md' => ['UAT-Checklist.pdf', 'UAT — User Acceptance Test', true],
        'DEPLOY.md' => ['Deploy-Guide.pdf', 'Go-live on H D\'s server', false],
    ];

    public function handle(): int
    {
        $out = base_path('docs/pdf');
        File::ensureDirectoryExists($out);

        foreach (self::DOCS as $source => [$file, $title, $landscape]) {
            $html = Str::markdown(File::get(base_path("docs/{$source}")), ['html_input' => 'strip']);

            Pdf::loadView('pdf.document', ['title' => $title, 'html' => $html])
                ->setPaper('a4', $landscape ? 'landscape' : 'portrait')
                ->save("{$out}/{$file}");

            $this->components->info("docs/pdf/{$file}");
        }

        return self::SUCCESS;
    }
}

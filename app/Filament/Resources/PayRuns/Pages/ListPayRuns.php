<?php

namespace App\Filament\Resources\PayRuns\Pages;

use App\Enums\PayType;
use App\Filament\Resources\PayRuns\PayRunResource;
use App\Models\User;
use App\Services\PayrollService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class ListPayRuns extends ListRecords
{
    protected static string $resource = PayRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newRun')
                ->label('New pay run')
                ->icon(Heroicon::OutlinedPlus)
                ->modalDescription('Creates the sheet with every active employee who has an amount for this pay type.')
                ->schema([
                    Select::make('pay_type')
                        ->label('Type')
                        ->options(PayType::class)
                        ->default(PayType::Gaji)
                        ->required()
                        ->native(),
                    Select::make('month')
                        ->options(collect(range(-1, 11))->mapWithKeys(fn (int $i): array => [
                            today()->startOfMonth()->subMonths($i)->format('Y-m') => today()->startOfMonth()->subMonths($i)->translatedFormat('F Y'),
                        ])->all())
                        ->default(today()->format('Y-m'))
                        ->required()
                        ->native(),
                    DatePicker::make('pay_date')
                        ->label('Pay date')
                        ->native()
                        ->default(today())
                        ->required()
                        ->helperText('UM is usually paid on the 5th.'),
                ])
                ->action(function (array $data, Action $action): void {
                    [$year, $month] = array_map('intval', explode('-', $data['month']));
                    $type = $data['pay_type'] instanceof PayType ? $data['pay_type'] : PayType::from($data['pay_type']);

                    try {
                        /** @var User $user */
                        $user = auth()->user();
                        $run = app(PayrollService::class)->create($type, $year, $month, $data['pay_date'], $user);
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Not created')->body(collect($exception->errors())->flatten()->join(' '))->danger()->send();
                        $action->halt();
                    }

                    $this->redirect(PayRunResource::getUrl('sheet', ['record' => $run]));
                }),
        ];
    }
}

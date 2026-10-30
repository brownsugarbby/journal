<?php

namespace App\Filament\Resources\PayRuns;

use App\Enums\PayRunStatus;
use App\Enums\PayType;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Filament\Resources\PayRuns\Pages\ListPayRuns;
use App\Filament\Resources\PayRuns\Pages\PayRunSheet;
use App\Filament\Support\Fields;
use App\Models\PayRun;
use App\Services\PayrollService;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PayRunResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = PayRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Payroll (Gaji / UM)';

    protected static ?string $modelLabel = 'pay run';

    protected static ?string $slug = 'payroll';

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof PayRun ? $record->label() : 'Pay run';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('Sheet')
                    ->state(fn (PayRun $record): string => $record->label())
                    ->weight('bold'),
                TextColumn::make('pay_type')->label('Type')->badge(),
                TextColumn::make('pay_date')->label('Pay date')->date('d/m/Y')->sortable(),
                TextColumn::make('items_count')->counts('items')->label('Org'),
                TextColumn::make('total_all')
                    ->label('Total all (Rp)')
                    ->state(fn (PayRun $record): ?string => app(PayrollService::class)->totals($record)['total_all'])
                    ->formatStateUsing(fn ($state) => Fields::rupiah($state))
                    ->placeholder(__('No kurs yet'))
                    ->alignEnd(),
                TextColumn::make('status')->badge(),
                TextColumn::make('paidBy.username')->label('Paid by')->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('pay_type')->label('Type')->options(PayType::class),
                SelectFilter::make('status')->options(PayRunStatus::class),
            ])
            ->recordUrl(fn (PayRun $record): string => static::getUrl('sheet', ['record' => $record]))
            ->recordActions([DeleteAction::make()->label('Delete draft')])
            ->defaultSort('pay_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayRuns::route('/'),
            'sheet' => PayRunSheet::route('/{record}'),
        ];
    }
}

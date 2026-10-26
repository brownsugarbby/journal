<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\ActivityLogs\Tables\ActivityLogsTable;
use BackedEnum;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 90;

    protected static ?string $modelLabel = 'activity';

    protected static ?string $navigationLabel = 'Audit Log';

    protected static ?string $slug = 'audit-log';

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('created_at')
                    ->label('When')
                    ->dateTime('d M Y H:i:s'),
                TextEntry::make('causer.name')
                    ->label('Who')
                    ->placeholder('System / guest'),
                TextEntry::make('log_name')
                    ->label('Log')
                    ->badge(),
                TextEntry::make('event')
                    ->badge(),
                TextEntry::make('description')
                    ->columnSpanFull(),
                TextEntry::make('subject_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (?string $state, Activity $record): string => $state
                        ? class_basename($state).' #'.$record->subject_id
                        : '-'),
                Section::make('Changes')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        CodeEntry::make('attribute_changes')
                            ->hiddenLabel()
                            ->state(fn (Activity $record): ?array => $record->attribute_changes?->toArray())
                            ->placeholder('-'),
                    ]),
                Section::make('Properties')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        CodeEntry::make('properties')
                            ->hiddenLabel()
                            ->state(fn (Activity $record): ?array => $record->properties?->toArray())
                            ->placeholder('-'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return ActivityLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLogs::route('/'),
        ];
    }
}

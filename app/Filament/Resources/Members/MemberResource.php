<?php

namespace App\Filament\Resources\Members;

use App\Filament\Resources\Members\Pages\ManageMembers;
use App\Filament\Support\Fields;
use App\Models\Member;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Members are created automatically from journal entries (Phase 2); this
 * screen lists them and lets a superadmin correct their bank details.
 */
class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 11;

    protected static ?string $recordTitleAttribute = 'member_id';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('member_id')
                    ->label('Member ID')
                    ->disabled(),
                TextInput::make('name')
                    ->maxLength(255),
                Fields::activeRelation('bank_id', 'bank', 'name')
                    ->label('Bank'),
                TextInput::make('account_number')
                    ->maxLength(50),
                TextInput::make('account_name')
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('brand.code')->label('Brand')->badge()->sortable(),
                TextColumn::make('member_id')->label('Member ID')->searchable()->sortable()->weight('bold'),
                TextColumn::make('name')->searchable()->placeholder('-'),
                TextColumn::make('bank.name')->label('Bank')->placeholder('-'),
                TextColumn::make('account_number')->label('No. rek')->searchable()->placeholder('-'),
                TextColumn::make('account_name')->label('Nama rek')->searchable()->placeholder('-'),
                TextColumn::make('first_seen_at')->label('First seen')->dateTime('d M Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('brand_id')->label('Brand')->relationship('brand', 'code'),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('first_seen_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMembers::route('/'),
        ];
    }
}

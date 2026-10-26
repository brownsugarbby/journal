<?php

namespace App\Filament\Pages;

use App\Support\AppSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class AppSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 99;

    protected static ?string $navigationLabel = 'App Settings';

    protected static ?string $title = 'App Settings';

    protected static ?string $slug = 'settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasFullAccess() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(app(AppSettings::class)->all());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Company')
                    ->columns(2)
                    ->schema([
                        TextInput::make('company_name')
                            ->required()
                            ->maxLength(100),
                        FileUpload::make('company_logo')
                            ->image()
                            ->disk('public')
                            ->directory('branding')
                            ->maxSize(1024),
                        Textarea::make('pdf_header')
                            ->label('PDF report header')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
                Section::make('Journal rules')
                    ->columns(3)
                    ->schema([
                        TextInput::make('staff_edit_window_minutes')
                            ->label('Staff edit window')
                            ->helperText('Minutes an admin can still edit their own entry. 0 = no edits.')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(1440)
                            ->suffix('min')
                            ->required(),
                        TextInput::make('default_max_balance_operational')
                            ->label('Default max balance: operational')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->prefix('Rp')
                            ->required(),
                        TextInput::make('default_max_balance_pusat')
                            ->label('Default max balance: pusat')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->prefix('Rp')
                            ->required(),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save settings')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $settings = app(AppSettings::class);
        $old = $settings->all();
        $new = $this->form->getState();

        $settings->save($new);

        $changed = array_keys(array_diff_assoc(
            array_map(fn ($v) => json_encode($v), $new),
            array_map(fn ($v) => json_encode($v), array_intersect_key($old, $new)),
        ));

        if ($changed !== []) {
            activity('settings')
                ->event('updated')
                ->withProperties([
                    'old' => array_intersect_key($old, array_flip($changed)),
                    'new' => array_intersect_key($new, array_flip($changed)),
                ])
                ->log('App settings updated');
        }

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}

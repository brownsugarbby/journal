<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/**
 * Users can change their own password here. Name and username are shown
 * read-only: only a superadmin changes those, from the Users screen.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent()->disabled()->dehydrated(false),
                $this->getUsernameFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }

    protected function getUsernameFormComponent(): Component
    {
        return TextInput::make('username')
            ->disabled()
            ->dehydrated(false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        $record = parent::handleRecordUpdate($record, $data);

        if (filled($data['password'] ?? null)) {
            activity('user')
                ->performedOn($record)
                ->event('password_reset')
                ->log('Changed own password');
        }

        return $record;
    }
}

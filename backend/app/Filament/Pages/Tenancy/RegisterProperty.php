<?php

namespace App\Filament\Pages\Tenancy;

use App\Models\Property;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;

class RegisterProperty extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Register property';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            TextInput::make('slug')->required()->unique('properties', 'slug'),
        ]);
    }

    protected function handleRegistration(array $data): Property
    {
        return Property::create($data);
    }
}

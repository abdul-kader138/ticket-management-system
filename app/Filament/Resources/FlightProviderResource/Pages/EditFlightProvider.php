<?php

namespace App\Filament\Resources\FlightProviderResource\Pages;

use App\Filament\Resources\FlightProviderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFlightProvider extends EditRecord
{
    protected static string $resource = FlightProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

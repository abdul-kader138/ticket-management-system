<?php

namespace App\Filament\Resources\FlightProviderResource\Pages;

use App\Filament\Resources\FlightProviderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFlightProviders extends ListRecords
{
    protected static string $resource = FlightProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

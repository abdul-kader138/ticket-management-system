<?php

namespace App\Filament\Resources\SubscriptionTierRuleResource\Pages;

use App\Filament\Resources\SubscriptionTierRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptionTierRules extends ListRecords
{
    protected static string $resource = SubscriptionTierRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

<?php

namespace App\Filament\Resources\SubscriptionTierRuleResource\Pages;

use App\Filament\Resources\SubscriptionTierRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSubscriptionTierRule extends EditRecord
{
    protected static string $resource = SubscriptionTierRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}

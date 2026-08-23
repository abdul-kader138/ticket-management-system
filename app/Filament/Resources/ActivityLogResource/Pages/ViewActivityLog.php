<?php

namespace App\Filament\Resources\ActivityLogResource\Pages;

use App\Filament\Resources\ActivityLogResource;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Spatie\Activitylog\Models\Activity;

class ViewActivityLog extends ViewRecord
{
    protected static string $resource = ActivityLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Activity')
                ->columns(2)
                ->schema([
                    TextEntry::make('created_at')
                        ->label('When')
                        ->dateTime('d M Y H:i:s'),

                    TextEntry::make('log_name')
                        ->label('Area')
                        ->badge(),

                    TextEntry::make('description')
                        ->label('Activity')
                        ->columnSpanFull(),

                    TextEntry::make('causer.name')
                        ->label('Performed by')
                        ->default('System'),

                    TextEntry::make('subject_type')
                        ->label('On')
                        ->formatStateUsing(fn (Activity $record) => $record->subject_type
                            ? class_basename($record->subject_type).' #'.$record->subject_id
                            : '—'),

                    TextEntry::make('properties.ip')
                        ->label('IP address')
                        ->default('—'),
                ]),

            Section::make('Changed values')
                ->visible(fn (Activity $record) => filled($record->properties?->get('attributes')))
                ->schema([
                    KeyValueEntry::make('properties.old')
                        ->label('Before'),

                    KeyValueEntry::make('properties.attributes')
                        ->label('After'),
                ])
                ->columns(2),

            Section::make('Other properties')
                ->visible(fn (Activity $record) => filled(
                    $record->properties?->except(['attributes', 'old', 'ip'])->all()
                ))
                ->schema([
                    KeyValueEntry::make('properties')
                        ->label('')
                        ->state(fn (Activity $record) => $record->properties?->except(['attributes', 'old', 'ip'])->all() ?? []),
                ]),
        ]);
    }
}

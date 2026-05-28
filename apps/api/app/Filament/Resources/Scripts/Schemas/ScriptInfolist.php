<?php

namespace App\Filament\Resources\Scripts\Schemas;

use App\Models\Script;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ScriptInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request')
                    ->schema([
                        TextEntry::make('title'),
                        TextEntry::make('prompt')
                            ->columnSpanFull(),
                        TextEntry::make('language')
                            ->badge(),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('requestedBy.email')
                            ->label('Requested by')
                            ->placeholder('—'),
                        TextEntry::make('requested_at')
                            ->dateTime(),
                    ])
                    ->columns(2),

                Section::make('Result')
                    ->schema([
                        TextEntry::make('content')
                            ->placeholder('— not generated yet —')
                            ->formatStateUsing(fn (?string $state): string => $state ?? '— not generated yet —')
                            ->columnSpanFull()
                            // Render in a fixed-width font for code; Filament's
                            // default TextEntry uses prose styling.
                            ->extraAttributes(['style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, monospace; white-space: pre-wrap;']),
                        TextEntry::make('error')
                            ->visible(fn (Script $record): bool => filled($record->error))
                            ->color('danger')
                            ->columnSpanFull(),
                        TextEntry::make('model')
                            ->placeholder('—'),
                        TextEntry::make('generated_at')
                            ->dateTime()
                            ->placeholder('—'),
                    ])
                    ->columns(2),

                Section::make('Metadata')
                    ->schema([
                        TextEntry::make('metadata')
                            ->placeholder('—')
                            ->formatStateUsing(fn (?array $state): string => $state === null ? '—' : json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                            ->extraAttributes(['style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, monospace; white-space: pre-wrap;'])
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }
}

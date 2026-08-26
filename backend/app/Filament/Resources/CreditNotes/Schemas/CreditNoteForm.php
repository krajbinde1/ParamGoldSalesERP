<?php

namespace App\Filament\Resources\CreditNotes\Schemas;

use App\Models\CreditNote;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CreditNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Credit Note')->columns(2)->schema([
                    TextInput::make('credit_note_no')->disabled(),
                    Select::make('type')->options(CreditNote::typeLabels())->disabled(),
                    DatePicker::make('credit_note_date')->native(false)->disabled(),
                    TextInput::make('bill_reference')->disabled(),
                    TextInput::make('amount')->prefix('₹')->disabled(),
                    Select::make('status')->options(CreditNote::statusLabels())->disabled(),
                    Textarea::make('remarks')->columnSpanFull()->disabled(),
                ]),
            ]);
    }
}

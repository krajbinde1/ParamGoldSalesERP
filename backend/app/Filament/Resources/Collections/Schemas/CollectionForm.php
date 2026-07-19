<?php

namespace App\Filament\Resources\Collections\Schemas;

use App\Models\Collection;
use App\Filament\Support\DealerSelect;
use App\Filament\Support\EmployeeSelect;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class CollectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Collection details')->columns(2)->schema([
                    TextInput::make('receipt_no')->label('Receipt No.')->placeholder('Generated automatically when saved')->readOnly()->dehydrated(false),
                    DatePicker::make('collection_date')->default(fn () => Collection::businessToday())->native(false)->required(),
                    Select::make('dealer_id')
                        ->label('Dealer')
                        ->tap(fn (Select $select) => DealerSelect::applyRelationshipSelect($select))
                        ->searchable()->preload()->required(),
                    Select::make('sales_employee_id')->label('Sales Employee')->relationship('salesEmployee', 'full_name', fn (Builder $query) => $query->where('status', true))->searchable()->preload()->tap(fn (Select $select) => EmployeeSelect::applyRelationshipSelect($select)),
                    TextInput::make('amount')->prefix('₹')->numeric()->minValue(0.01)->required(),
                    Select::make('status')->options(Collection::statusLabels())->default(Collection::STATUS_PENDING)->disabled()->dehydrated(),
                    FileUpload::make('photo_path')
                        ->label('Photo')
                        ->image()
                        ->directory('collections')
                        ->disk('public')
                        ->columnSpanFull(),
                    Textarea::make('remarks')->label('Employee Remarks')->rows(2)->columnSpanFull(),
                    Textarea::make('admin_remark')->label('Admin Remark')->rows(2)->columnSpanFull(),
                ]),
            ]);
    }
}

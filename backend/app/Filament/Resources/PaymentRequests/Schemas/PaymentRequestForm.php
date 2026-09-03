<?php

namespace App\Filament\Resources\PaymentRequests\Schemas;

use App\Models\PaymentRequest;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class PaymentRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Payment Request')
                    ->columns(2)
                    ->schema([
                        TextInput::make('vendor_name')
                            ->label('Vendor Name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(1),
                        TextInput::make('vendor_mobile')
                            ->label('Vendor Mobile Number')
                            ->required()
                            ->tel()
                            ->maxLength(20)
                            ->rules(['regex:/^[0-9+\-\s]{8,20}$/'])
                            ->columnSpan(1),
                        TextInput::make('amount')
                            ->label('Amount ₹')
                            ->required()
                            ->numeric()
                            ->minValue(0.01)
                            ->prefix('₹')
                            ->columnSpan(1),
                        Textarea::make('remark')
                            ->label('Remark')
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ]),
                Section::make('Supporting Documents')
                    ->description('Optional. Upload PDF, JPG, JPEG, or PNG files (max 10 MB each).')
                    ->schema([
                        Placeholder::make('existing_supporting_documents')
                            ->label('Current supporting documents')
                            ->visible(fn (?Model $record): bool => $record instanceof PaymentRequest
                                && $record->exists
                                && $record->supportingDocuments()->exists())
                            ->content(function (?Model $record): HtmlString {
                                if (! $record instanceof PaymentRequest) {
                                    return new HtmlString('');
                                }

                                $items = $record->supportingDocuments
                                    ->map(fn ($document): string => '<li>'.e($document->original_file_name).'</li>')
                                    ->implode('');

                                return new HtmlString('<ul class="list-disc ps-4 text-sm text-gray-600 dark:text-gray-300">'.$items.'</ul>');
                            }),
                        FileUpload::make('supporting_documents')
                            ->label('Supporting Documents')
                            ->multiple()
                            ->reorderable()
                            ->appendFiles()
                            ->storeFiles(false)
                            ->acceptedFileTypes([
                                'application/pdf',
                                'image/jpeg',
                                'image/png',
                            ])
                            ->maxSize(10240)
                            ->helperText('Examples: Vendor Invoice, Quotation, Proforma Invoice, Bank Details, Approval Letter')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}

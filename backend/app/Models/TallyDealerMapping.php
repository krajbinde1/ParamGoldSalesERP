<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TallyDealerMapping extends Model
{
    protected $fillable = [
        'tally_ledger_name',
        'tally_ledger_name_normalized',
        'dealer_id',
        'created_by',
    ];

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function normalizeName(string $name): string
    {
        $text = str_replace(
            [
                "\xC2\xA0", "\u{00A0}", "\u{2007}", "\u{202F}", "\u{2009}",
                '（', '）', '［', '］', '【', '】',
            ],
            [
                ' ', ' ', ' ', ' ', ' ',
                '(', ')', '(', ')', '(', ')',
            ],
            $name,
        );

        $text = str_replace(['(', ')', '[', ']', '{', '}'], ' ', $text);

        return Str::of($text)
            ->lower()
            ->replaceMatches('/[^\p{L}\p{N}]+/u', ' ')
            ->squish()
            ->toString();
    }
}

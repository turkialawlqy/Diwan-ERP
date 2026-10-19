<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Official holiday (Art. 112: Eid al-Fitr, Eid al-Adha, National Day,
 * Founding Day) with its Gregorian dates for the year.
 */
class PublicHoliday extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'name', 'start_date', 'end_date'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    /** Holiday days falling in [from, to]. */
    public static function daysBetween(Carbon $from, Carbon $to): int
    {
        return (int) static::whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->get()
            ->sum(fn ($h) => (int) Carbon::parse(max($h->start_date->toDateString(), $from->toDateString()))
                ->diffInDays(Carbon::parse(min($h->end_date->toDateString(), $to->toDateString()))) + 1);
    }

    public static function covers(Carbon $date): bool
    {
        return static::whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->exists();
    }
}

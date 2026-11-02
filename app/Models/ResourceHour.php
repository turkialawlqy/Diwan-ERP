<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** One working interval of a resource on a weekday (ISO 1 = Monday … 7 = Sunday); several rows a day = breaks. */
class ResourceHour extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $fillable = ['company_id', 'booking_resource_id', 'weekday', 'start_time', 'end_time'];

    public const DAYS = [6 => 'السبت', 7 => 'الأحد', 1 => 'الاثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة'];
}

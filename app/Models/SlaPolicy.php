<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** First-response and resolution targets (minutes) for one ticket priority. */
class SlaPolicy extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'priority', 'first_response_minutes', 'resolution_minutes'];

    /** Defaults used until the company saves its own: priority => [first response, resolution] in minutes. */
    public const DEFAULTS = ['low' => [480, 4320], 'normal' => [240, 2880], 'high' => [60, 480], 'urgent' => [30, 240]];
}

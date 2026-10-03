<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class JobPosition extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'title', 'grade'];
}

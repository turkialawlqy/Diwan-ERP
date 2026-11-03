<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use Auditable, BelongsToCompany, SoftDeletes;

    protected $fillable = ['company_id', 'name', 'type', 'address', 'notes'];

    public const TYPES = ['building' => 'عمارة', 'villa' => 'فيلا', 'complex' => 'مجمع', 'shop' => 'محلات', 'land' => 'أرض'];

    public function units(): HasMany
    {
        return $this->hasMany(PropertyUnit::class)->orderBy('unit_number');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    protected $fillable = ['country', 'state', 'kind', 'rate_basis_points', 'inclusive', 'is_active'];

    protected function casts(): array
    {
        return ['inclusive' => 'boolean', 'is_active' => 'boolean', 'rate_basis_points' => 'integer'];
    }

    /** Human label, e.g. "18.00%". */
    public function label(): string
    {
        return number_format($this->rate_basis_points / 100, 2).'%';
    }
}

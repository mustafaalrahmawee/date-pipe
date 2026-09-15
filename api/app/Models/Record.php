<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'row_index',
    'name',
    'email',
    'amount',
])]
class Record extends Model
{
    // Records are derived, reproducible data: re-processing an import
    // replaces its rows, so no timestamp bookkeeping is kept.
    public $timestamps = false;

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
        ];
    }
}

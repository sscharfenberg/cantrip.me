<?php

namespace App\Models;

use App\Enums\Scryfall\ScryfallRulingSource;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ruling extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $table = 'rulings';

    protected $primaryKey = 'id';

    /**
     * Disable timestamps. Populated via bulk Scryfall import.
     *
     * @var bool
     */
    public $timestamps = false;

    protected $fillable = [
        'id',
        'oracle_card_id',
        'source',
        'published_at',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'date',
            'source' => ScryfallRulingSource::class,
        ];
    }

    /**
     * @return BelongsTo<OracleCard, Ruling>
     */
    public function oracleCard(): BelongsTo
    {
        return $this->belongsTo(OracleCard::class, 'oracle_card_id');
    }
}

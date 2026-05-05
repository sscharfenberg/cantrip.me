<?php

namespace App\Models;

use App\Enums\Scryfall\ScryfallSetType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Set extends Model
{
    use HasUuids;

    /**
     * The data type of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the model's ID is auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sets';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Disable use of timestamps. Since we do a full DB insert, we do not need timestamps
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'code',
        'name',
        'block_code',
        'block',
        'parent_set_code',
        'card_count',
        'printed_size',
        'set_type',
        'digital',
        'scryfall_uri',
        'path',
        'released_at',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'digital' => 'boolean',
        'released_at' => 'date',
        'set_type' => ScryfallSetType::class,
    ];

    /**
     * Get the default cards belonging to this set.
     *
     * @return HasMany<DefaultCard>
     */
    public function defaultCards(): HasMany
    {
        return $this->hasMany(DefaultCard::class, 'set_id', 'id');
    }
}

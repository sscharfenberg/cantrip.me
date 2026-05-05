<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Symbol extends Model
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
    protected $table = 'symbols';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
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
        'symbol',
        'svg_uri',
        'loose_variant',
        'english',
        'represents_mana',
        'appears_in_mana_costs',
        'transposable',
        'hybrid',
        'phyrexian',
        'funny',
        'cmc',
        'colors',
        'path',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'represents_mana' => 'boolean',
        'appears_in_mana_costs' => 'boolean',
        'transposable' => 'boolean',
        'hybrid' => 'boolean',
        'phyrexian' => 'boolean',
        'funny' => 'boolean',
        'cmc' => 'integer',
    ];
}

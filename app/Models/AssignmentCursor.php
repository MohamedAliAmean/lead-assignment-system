<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Round Robin rotation state (one row per pool).
 *
 * @property int $id
 * @property string $pool
 * @property int|null $last_user_id
 */
final class AssignmentCursor extends Model
{
    public const string GLOBAL_POOL = 'global';

    public const CREATED_AT = null;

    protected $fillable = ['pool', 'last_user_id'];

    protected function casts(): array
    {
        return ['last_user_id' => 'integer'];
    }
}

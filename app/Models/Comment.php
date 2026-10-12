<?php

namespace App\Models;

use App\Enums\CommentKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A user-written conversation message on a commentable record (ticket).
 * Plain text: sanitized on input, escaped on output. System events live in
 * the activity log, not here.
 *
 * @property int $id
 * @property string $commentable_type
 * @property int $commentable_id
 * @property int $user_id
 * @property CommentKind $kind
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'kind', 'body'])]
class Comment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CommentKind::class,
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

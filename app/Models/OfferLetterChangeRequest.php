<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agent's request to change an offer letter that has already been approved.
 *
 * The letter is the document a client is held to, so once it is issued its
 * terms are locked. Rather than either freezing them forever or letting any
 * agent edit them, the agent describes the change and a manager approves it.
 */
class OfferLetterChangeRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'tenant_id',
        'offer_letter_id',
        'requested_by',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    public function offerLetter(): BelongsTo
    {
        return $this->belongsTo(OfferLetter::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Only one live request per letter. Without this, two agents could each
     * raise one and a manager would approve the first, silently leaving the
     * second pointing at a letter that is no longer approved.
     */
    public function scopePendingFor($query, OfferLetter $letter)
    {
        return $query->where('offer_letter_id', $letter->id)->where('status', self::STATUS_PENDING);
    }
}

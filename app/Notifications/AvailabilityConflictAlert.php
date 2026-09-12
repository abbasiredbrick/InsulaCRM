<?php

namespace App\Notifications;

use App\Models\AvailabilityReview;
use Illuminate\Notifications\Notification;

/**
 * Tells the unit's agent (and admins) that a currently-listed unit is now
 * leased per the PM availability sheet, so flipping it off the portals would
 * waste an expensive madhmoun/marmoom listing permit. A human decides whether
 * to unlist it or keep it listed to keep receiving leads that can be diverted.
 */
class AvailabilityConflictAlert extends Notification
{
    public function __construct(
        protected AvailabilityReview $review,
        protected string $sourceName
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $property = $this->review->property;

        return [
            'type' => 'availability_conflict',
            'icon' => 'alert-triangle',
            'color' => 'orange',
            'title' => __('Listed unit needs an availability decision'),
            'body' => __(
                ':unit: PM shows it as leased (:source) — keep it listed to keep receiving (and diverting) leads, or unlist it.',
                [
                    'unit' => $property ? $property->display_name : '#'.$this->review->property_id,
                    'source' => $this->sourceName,
                ]
            ),
            'url' => route('availability-sources.reviews'),
            'property_id' => $this->review->property_id,
            'review_id' => $this->review->id,
            'reason' => $this->review->reason,
        ];
    }
}

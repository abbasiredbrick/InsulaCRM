<?php

namespace App\Services;

use App\Events\LeadStatusChanged;
use App\Facades\Hooks;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;

/**
 * Applies the automatic consequences of a deal stage change.
 *
 * Three rules, and they exist because leaving them to an agent makes the numbers
 * lie:
 *
 * 1. `commission_received` promotes to `deal_won` immediately. The deal is won
 *    when the broker's money lands — not when an offer is signed, and not when a
 *    manager remembers to tick a box. Commission confirmed but the deal not won
 *    means the board under-reports revenue; the reverse means it invents it.
 *
 * 2. `moved_in` promotes to `deal_locked`. Once the tenant is in, the record is
 *    administrative history and must not be edited.
 *
 * 3. The won/lost deal stage sets the lead status. `deal_won` and `closed_lost`
 *    are consequences of deal stages, never something an agent picks on the lead,
 *    so "how much did I win this month" has exactly one answer.
 */
class DealLifecycleService
{
    public function __construct(
        protected TransactionCloseService $close,
    ) {}

    /**
     * Stage a requested change is immediately promoted to, keyed by the stage the
     * agent set. `commission_received` and `moved_in` are the last hand-driven
     * steps in their ladders; everything after them is the system recording that
     * the money or the tenant arrived.
     *
     * @var array<string, string>
     */
    public const AUTO_PROMOTIONS = [
        'commission_received' => 'deal_won',
        'moved_in' => 'deal_locked',
    ];

    /**
     * Which stage an automatic stage is reached from — deal_won from
     * commission_received, deal_locked from moved_in. Null when $stage is not an
     * automatic outcome, so callers can say "set by X" or "set automatically".
     */
    public function promotionSourceFor(string $stage): ?string
    {
        $source = array_search($stage, self::AUTO_PROMOTIONS, true);

        return $source === false ? null : $source;
    }

    /**
     * Whether this deal may be won, or null when it may.
     *
     * Winning is gated on a signed offer letter in brokerage mode. Callers must
     * ask BEFORE writing the stage: a lease wins by passing through
     * `commission_received`, so gating only a literal 'closed_won' would let the
     * deal land on deal_won with no signature and no commission.
     */
    public function gateError(Deal $deal): ?string
    {
        $lead = $deal->lead;

        if (! $lead) {
            return null;
        }

        return $this->close->gateError($lead);
    }

    /**
     * Apply every consequence of a deal landing on $stage.
     *
     * Returns the stage the deal actually ended on, which differs from $stage
     * when an automatic promotion fired — callers must use the return value, or
     * they will log and broadcast a stage the deal was immediately moved off.
     *
     * @param  User|null  $actor  who triggered the change, for attribution
     */
    public function apply(Deal $deal, string $stage, ?User $actor = null): string
    {
        $deal->refresh();

        if (isset(self::AUTO_PROMOTIONS[$stage])) {
            $promoted = self::AUTO_PROMOTIONS[$stage];
            $deal->update(['stage' => $promoted, 'stage_changed_at' => now()]);
            $deal->refresh();
            $stage = $promoted;
        }

        $this->syncLeadStatus($deal, $actor);

        return $stage;
    }

    /**
     * Keep the lead's terminal status a mirror of its deal's.
     *
     * Only one deal per lead exists, so the lead's status is not ambiguous. A lost
     * deal loses the lead even if an agent had marked it active_client, because a
     * dead deal and a live client cannot both be true.
     */
    protected function syncLeadStatus(Deal $deal, ?User $actor = null): void
    {
        $lead = $deal->lead;
        if (! $lead) {
            return;
        }

        if ($deal->stage === 'closed_lost') {
            if ($lead->status !== 'closed_lost') {
                $this->setLeadStatus($lead, 'closed_lost');
            }

            return;
        }

        if (! $deal->isWon() || $lead->status === 'closed_won') {
            return;
        }

        // Defence in depth: the caller gates on this before writing the stage, so
        // reaching here without a signature means the gate was bypassed.
        if ($this->gateError($deal)) {
            return;
        }

        // closeAsWon() writes closed_won with a bare update(), so the won side never
        // fired the event/hooks that the lost side fires above. Left alone that
        // means a lost deal alerts the managers and a won deal is silent: any
        // hook listening for a terminal status change silently misses every deal
        // won through the lifecycle. The old status is captured before the close
        // because by the time it returns the lead already reads closed_won.
        $statusBeforeClose = $lead->status;

        app(LeadToClientService::class)->convertFromWonDeal($deal);
        $this->close->closeAsWon($lead, $deal, $actor);

        if ($statusBeforeClose !== 'closed_won') {
            $this->announceStatusChange($lead, $statusBeforeClose);
        }
    }

    /**
     * Move a lead to a terminal status, firing the same events, hooks and
     * cross-check alert the manual path did.
     *
     * A bare $lead->update() here would leave admins unalerted when a deal is
     * marked lost: the notification used to hang off the hand-driven status
     * change, and a lifecycle that quietly skips it loses the review step that
     * catches a dead deal nobody told anyone about.
     */
    protected function setLeadStatus(Lead $lead, string $status): void
    {
        $oldStatus = $lead->status;

        $lead->update(['status' => $status]);

        if ($oldStatus === $status) {
            return;
        }

        $this->announceStatusChange($lead, $oldStatus);
    }

    /**
     * Announce a terminal status change that something else has already written.
     *
     * Separate from setLeadStatus() because closeAsWon() persists closed_won itself
     * and there is nothing left to update by the time we notice — but the
     * listeners still have to run, exactly as they do on the manual path.
     */
    protected function announceStatusChange(Lead $lead, string $oldStatus): void
    {
        $status = (string) $lead->fresh()->status;

        event(new LeadStatusChanged($lead, $oldStatus));
        Hooks::doAction('lead.status_changed', $lead, $oldStatus);

        if (LostLeadNotifier::isLostStatus($status)) {
            LostLeadNotifier::notify($lead, $status, $oldStatus);
        }
    }
}

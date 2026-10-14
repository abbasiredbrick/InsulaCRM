<?php

namespace App\Services;

use App\Models\MarketContact;

/**
 * Reads the free-text comment / call-note columns of a cold-call contact and
 * derives a disposition and an "expected inventory" flag, so a once-called
 * contact who sounded open to renting/selling (or asked for a call-back) is
 * surfaced for a follow-up call before being dropped.
 *
 * The rules are deliberately keyword-first and permissive for the positive
 * cases (a comment that says anything about wanting to rent/sell/invest is
 * worth a re-call); negative and neutral text is only trusted when it is an
 * explicit refusal, otherwise the disposition stays "unclear" so a human makes
 * the call.
 */
class ColdCallSentimentService
{
    public const DISPOSITIONS = [
        'willing_rent' => 'Willing to rent',
        'willing_sell' => 'Willing to sell',
        'willing_invest' => 'Willing to invest',
        'call_back' => 'Call back later',
        'not_interested' => 'Not interested',
        'unclear' => 'Unclear',
    ];

    /** Dispositions that land a contact on the expected-inventory re-call queue. */
    public const RECALL_DISPOSITIONS = ['willing_rent', 'willing_sell', 'willing_invest', 'call_back'];

    public function classify(MarketContact $contact): array
    {
        $text = $this->normalize(implode(' | ', array_filter([
            $contact->requirements,
            $contact->notes,
            $contact->call_notes,
        ])));

        if ($text === '') {
            return $this->result('unclear', $contact);
        }

        // Refusals win over everything else.
        if ($this->matches($text, $this->negativePatterns())) {
            return $this->result('not_interested', $contact);
        }

        $invest = $contact->type === 'investor' || $this->matches($text, $this->investPatterns());

        if ($this->matches($text, $this->sellPatterns())) {
            return $this->result('willing_sell', $contact);
        }

        if ($this->matches($text, $this->rentPatterns())) {
            return $this->result('willing_rent', $contact);
        }

        if ($invest) {
            return $this->result('willing_invest', $contact);
        }

        if ($this->matches($text, $this->callbackPatterns())) {
            return $this->result('call_back', $contact);
        }

        return $this->result('unclear', $contact);
    }

    protected function result(string $disposition, MarketContact $contact): array
    {
        $expected = in_array($disposition, self::RECALL_DISPOSITIONS, true);

        $priority = match ($disposition) {
            'willing_rent', 'willing_sell', 'willing_invest' => 3,
            'call_back' => 2,
            'unclear' => 1,
            default => 0,
        };

        return [
            'disposition' => $disposition,
            'expected_inventory' => $expected,
            'priority' => $priority,
        ];
    }

    protected function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));

        return (string) preg_replace('/[\x{00A0}\s]+/u', ' ', $text);
    }

    protected function matches(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    protected function rentPatterns(): array
    {
        return [
            '/\bwill\s+rent\b/',
            '/\bwants?\s+to\s+rent\b/',
            '/\bwilling\s+to\s+rent\b/',
            '/\bready\s+to\s+rent\b/',
            '/\brent\s+(it\s+)?out\b/',
            '/\binterested\s+in\s+renting\b/',
            '/\bwants?\s+(it\s+)?rented\b/',
            '/\bfor\s+rent\b/',
            '/\bhas\s+property\b.{0,40}\brent\b/',
            '/\bowner\b.{0,30}\brent\b/',
            '/\blandlord\b.{0,20}\brent\b/',
        ];
    }

    protected function sellPatterns(): array
    {
        return [
            '/\bwill\s+sell\b/',
            '/\bwants?\s+to\s+sell\b/',
            '/\bwilling\s+to\s+sell\b/',
            '/\bready\s+to\s+sell\b/',
            '/\binterested\s+in\s+selling\b/',
            '/\bfor\s+sale\b/',
            '/\bwants?\s+(it\s+)?sold\b/',
            '/\bsell(?:ing)?\s+(?:the\s+)?(?:unit|property|apartment|villa|home|house)\b/',
        ];
    }

    protected function investPatterns(): array
    {
        return [
            '/\binvest(?:or|ment|ing)?\b/',
            '/\bbuy(?:er|ing)?\b/',
            '/\bpurchase?\b/',
            '/\bacqui(?:re|sition)\b/',
            '/\bupsize\b/',
        ];
    }

    protected function callbackPatterns(): array
    {
        return [
            '/\bcall\s*back\b/',
            '/\bcallback\b/',
            '/\bfollow\s*up\b/',
            '/\breach\s+out\s+later\b/',
            '/\btry\s+later\b/',
            '/\bnext\s+(week|month|year)\b/',
            '/\blater\b/',
            '/\bnot\s+now\b/',
            '/\bbusy\b/',
            '/\boccupied\b/',
        ];
    }

    protected function negativePatterns(): array
    {
        return [
            '/\bnot\s+interested\b/',
            '/\bno\s+(interest|thanks|thank you)\b/',
            '/\bdont\s+want\b/',
            "/\bdon't\s+want\b/",
            '/\bdo\s+not\s+want\b/',
            '/\bnot\s+(looking|interested|selling|renting)\b/',
            '/\bnot\s+at\s+this\s+time\b/',
        ];
    }
}

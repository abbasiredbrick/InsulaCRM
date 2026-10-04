<?php

namespace App\Helpers;

use Illuminate\Support\HtmlString;

/**
 * Renders user-supplied free text safely, with bare URLs turned into links.
 *
 * Portal leads arrive with a listing URL buried in the notes or in a custom
 * field, and nothing in the CRM made it clickable. This is the one place that
 * gets fixed, so every call site behaves identically.
 *
 * The order matters and is the whole security argument: the input is escaped
 * FIRST, and only the anchors this class generates are ever emitted as markup.
 * That means a user cannot smuggle a tag through a note, a URL, or a field
 * name - the only HTML in the output is an <a> whose href we built ourselves.
 */
class TextRenderHelper
{
    /**
     * Matches bare URLs. Deliberately conservative: it only ever links things
     * that already look like http(s):// or www., so ordinary text ("e.g. 5/6",
     * "call me on 0501234567", "see notes") is left completely alone.
     */
    private const URL_PATTERN = '~\b(?:https?://|www\.)[^\s<>"\'`]*[^\s<>"\'`.,;:!?)\]}]~i';

    /**
     * Escape the text and make any URLs in it clickable.
     *
     * Safe to output with {!! !!} - the input is escaped before the anchors are
     * added, and the anchors are the only markup produced.
     */
    public static function linkify(?string $text, bool $newlines = false): HtmlString
    {
        $text = (string) $text;

        if (trim($text) === '') {
            return new HtmlString('');
        }

        // Pass 1: pull the URLs out and leave an opaque token behind. Working on
        // the raw string (rather than the escaped one) keeps href handling
        // honest, and guarantees an escaped quote or angle bracket can never
        // end up inside an attribute we are about to build.
        $urls = [];

        $tokenised = preg_replace_callback(self::URL_PATTERN, static function (array $match) use (&$urls): string {
            $href = self::normaliseHref($match[0]);

            if ($href === null) {
                return $match[0];
            }

            $token = "\x1A".count($urls)."\x1A";

            $urls[] = '<a href="'.e($href).'" target="_blank" rel="noopener noreferrer">'
                .e($match[0])
                .'</a>';

            return $token;
        }, $text);

        // Pass 2: escape everything that is left - the token stream plus all
        // the surrounding prose. The \x1A sentinels survive escaping untouched
        // because they are not HTML metacharacters.
        $html = e((string) $tokenised);

        // Pass 3: put the anchors back, now known to be safe.
        $html = self::restore($html, $urls);

        return new HtmlString($newlines ? nl2br($html) : $html);
    }

    /**
     * Swap the sentinels back for the anchors built in pass 1.
     */
    private static function restore(string $html, array $urls): string
    {
        foreach ($urls as $index => $anchor) {
            $html = str_replace("\x1A{$index}\x1A", $anchor, $html);
        }

        return $html;
    }

    /**
     * Turn a matched URL into a safe absolute href, or null to skip it.
     *
     * The scheme allowlist is the important part: a bare "www." match gets
     * https:// prefixed, and anything that is not http(s) after that is
     * dropped rather than linked. This keeps javascript:, data: and vbscript:
     * out of the href entirely.
     */
    private static function normaliseHref(string $url): ?string
    {
        $candidate = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $candidate = trim($candidate);

        if (stripos($candidate, 'www.') === 0) {
            $candidate = 'https://'.$candidate;
        }

        $parts = parse_url($candidate);

        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $candidate;
    }
}

<?php

namespace App\Modules\Reference\Domain\Support;

/**
 * Deterministic Arabic lookup-normalization for marital-status source-value resolution only (S05
 * CORRECTIVE-01 §4). Normalizes harmless orthographic variance so distinct spellings of the same
 * word resolve to the same lookup key; it never changes business meaning, never infers anything
 * from the input, and is not a general-purpose Arabic text processor.
 *
 * Rules, applied in order:
 *  1. Trim surrounding whitespace.
 *  2. Collapse any run of internal whitespace to a single space.
 *  3. Unify the Alef family (أ / إ / آ) to bare Alef (ا) — a harmless orthographic variant
 *     commonly interchanged in Arabic HR source data, never a change in meaning.
 *
 * Deliberately does NOT: strip diacritics, normalize Teh Marbuta/Heh, remove tatweel, or perform
 * any fuzzy/typo-tolerant matching. Any of those would risk silently changing which word is being
 * matched (§4 — "It MUST NOT ... fuzzy-match arbitrary values").
 */
final class ArabicLookupNormalizer
{
    public static function normalize(string $value): string
    {
        $trimmed = trim($value);
        $collapsed = preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed;

        return str_replace(['أ', 'إ', 'آ'], 'ا', $collapsed);
    }
}

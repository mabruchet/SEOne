<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SEOne\Service\MetaTemplate;

/**
 * Turns "%title% %brand%, en stock chez %store_name%" into a meta value.
 *
 * A template is a plain string with %variable% markers, nothing else: no condition, no loop,
 * no function call, so a merchant can never run code from the back-office. An unknown variable
 * is removed, an empty one leaves no orphan separator behind, and the result is cut on a word
 * when it exceeds the maximum length.
 */
final readonly class MetaTemplateRenderer
{
    public const string VARIABLE_PATTERN = '/%([A-Za-z][A-Za-z0-9_]*)%/';

    private const string SEPARATOR = '[,;:|\/\-\x{2013}\x{2014}\x{00B7}]';

    /**
     * What may follow a separator for it to be one the template wrote, not a character of a
     * value: a space or the end of the text. "http://", "-20 %" or "T-shirt" keep their punctuation.
     */
    private const string SEPARATOR_END = '(?=\s|$)';

    /**
     * Variable names used by the template, without their markers, each once, in order of appearance.
     *
     * @return list<string>
     */
    public static function extractVariableNames(string $template): array
    {
        preg_match_all(self::VARIABLE_PATTERN, $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param array<string, string> $values    variable name => plain-text value; a name missing here is removed from the output
     * @param int|null              $maxLength cut on a word boundary when the result is longer; null keeps the whole result
     */
    public function render(string $template, array $values, ?int $maxLength = null): string
    {
        $rendered = preg_replace_callback(
            self::VARIABLE_PATTERN,
            static fn (array $match): string => $values[$match[1]] ?? '',
            $template,
        ) ?? '';

        $rendered = $this->tidy($rendered);

        if (null !== $maxLength && $maxLength > 0 && mb_strlen($rendered) > $maxLength) {
            $rendered = $this->truncateOnWord($rendered, $maxLength);
        }

        return $rendered;
    }

    private function tidy(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        // "()" or "[ ]" left by a variable that was the whole content of a bracket.
        $text = preg_replace('/[(\[]\s*[)\]]/u', '', $text) ?? $text;

        // A comma, a semicolon or a full stop never follows a space: "Horatio , en stock" when
        // the variable before the comma was empty.
        $text = preg_replace('/\s+([,;.])/u', '$1', $text) ?? $text;

        // A run of separators ("Horatio - - Thelia", "Horatio, , Thelia") keeps its first one. The
        // run has to end the way a separator of the template does, before a space: the "://" of
        // a URL or the ": -" before a negative number are no run of orphan separators.
        $text = preg_replace('/(\s*'.self::SEPARATOR.')(?:\s*'.self::SEPARATOR.')+'.self::SEPARATOR_END.'/u', '$1', $text) ?? $text;

        // A separator right before a full stop ("Horatio,." once the variables in between were
        // empty) has nothing left to separate: the sentence keeps its full stop only.
        $text = preg_replace('/(?:\s*'.self::SEPARATOR.')+\s*\./u', '.', $text) ?? $text;

        return $this->trimSeparators($text);
    }

    private function trimSeparators(string $text): string
    {
        // A leading separator is an orphan when a space follows it ("- Horatio", ", Horatio"),
        // never when it opens a word: "-20% sur Horatio" keeps its minus.
        $text = preg_replace('/^(?:\s*'.self::SEPARATOR.')+'.self::SEPARATOR_END.'\s*/u', '', $text) ?? $text;

        // A trailing separator is an orphan when a space precedes it ("Horatio -") or when it is a
        // punctuation mark glued to the last word ("Horatio,"); a dash or a slash that closes a
        // word belongs to it ("http://example.com/").
        $text = preg_replace('/(?:\s+'.self::SEPARATOR.'|(?<=\S)[,;:])(?:\s*'.self::SEPARATOR.')*\s*$/u', '', $text) ?? $text;

        return trim($text);
    }

    private function truncateOnWord(string $text, int $maxLength): string
    {
        $cut = mb_substr($text, 0, $maxLength);

        // A word that ends exactly at the limit is whole: only a cut inside a word steps back to the previous one.
        if (' ' !== mb_substr($text, $maxLength, 1)) {
            $lastSpace = mb_strrpos($cut, ' ');

            if (false !== $lastSpace && $lastSpace > 0) {
                $cut = mb_substr($cut, 0, $lastSpace);
            }
        }

        return $this->trimSeparators($cut);
    }
}

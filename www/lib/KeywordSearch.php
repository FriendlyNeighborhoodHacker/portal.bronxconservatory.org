<?php
declare(strict_types=1);

/**
 * Building blocks for the portal's name searches.
 *
 * Every one of them has to survive somebody typing a whole name. "Brian
 * Rosenthal" matches no single column — there is no column holding both
 * words — so a search that pattern-matches the raw string finds nothing and
 * looks broken. The fix is always the same shape: split the input on
 * whitespace, and require every word to match *some* column. "Brian
 * Rosenthal" then means first_name starts with Brian AND last_name starts
 * with Rosenthal, which is what the person meant.
 *
 * Whether a word matches a column by prefix or anywhere inside it is the
 * caller's call: a typeahead wants prefixes (you are typing a name from its
 * start), an admin list wants contains (you are hunting with a fragment, or
 * a piece of an email address).
 */
class KeywordSearch {

    /** The words of a search box, capped so a paragraph cannot become a query. */
    public static function tokens(string $keyword, int $max = 5): array {
        $tokens = preg_split('/\s+/', trim($keyword)) ?: [];
        return array_slice(array_values(array_filter($tokens, fn($t) => $t !== '')), 0, $max);
    }

    /** LIKE pattern for "this column starts with the word". */
    public static function startingWith(string $token): string {
        return self::escapeLike($token) . '%';
    }

    /** LIKE pattern for "this word appears anywhere in the column". */
    public static function containing(string $token): string {
        return '%' . self::escapeLike($token) . '%';
    }

    /**
     * `(col1 LIKE :p_0 OR col2 LIKE :p_1 ...)` for one search value, appending
     * the bindings to $params. Each column gets its own placeholder because we
     * run with PDO::ATTR_EMULATE_PREPARES off, where reusing a named
     * placeholder within a statement fails with "Invalid parameter number".
     */
    public static function likeAnyClause(array $columns, string $like, string $placeholderPrefix, array &$params): string {
        $parts = [];
        foreach ($columns as $j => $col) {
            $ph = ":{$placeholderPrefix}_{$j}";
            $parts[] = "{$col} LIKE {$ph}";
            $params[$ph] = $like;
        }
        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * The whole condition for a simple search: every word must match at least
     * one of $columns. Returns '' when nothing was typed, so the caller adds
     * no condition at all.
     *
     * Searches that need more than a column list per word — a student's own
     * name *or* their parent's, say — should use tokens() and
     * likeAnyClause() directly and compose the shape they need.
     */
    public static function allTokensClause(array $columns, string $keyword, string $placeholderPrefix, array &$params, bool $prefixOnly = true): string {
        $clauses = [];
        foreach (self::tokens($keyword) as $i => $token) {
            $like = $prefixOnly ? self::startingWith($token) : self::containing($token);
            $clauses[] = self::likeAnyClause($columns, $like, "{$placeholderPrefix}{$i}", $params);
        }
        return $clauses ? implode(' AND ', $clauses) : '';
    }

    /**
     * A word typed into a search box is data, not pattern: somebody looking
     * for "100%" or an address with an underscore should not silently match
     * everything.
     */
    private static function escapeLike(string $token): string {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $token);
    }
}

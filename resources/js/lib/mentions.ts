import type { Brand } from '@/types/enums';

export type NamedWrestler = { name: string; brand: Brand | null };

export type MentionMatcher = {
    pattern: RegExp | null;
    brands: Map<string, Brand | null>;
};

export type MentionPart = string | { name: string; brand: Brand | null };

const escape = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/**
 * Builds one regex for every roster name. Longer names come first so
 * "Matt Hardy" wins over "Hardy", and word boundaries stop "Edge" from
 * matching inside "edged".
 */
export function buildMentionMatcher(
    wrestlers: NamedWrestler[],
): MentionMatcher {
    const brands = new Map(wrestlers.map((w) => [w.name, w.brand]));
    const names = [...brands.keys()]
        .filter((name) => name.length > 0)
        .sort((a, b) => b.length - a.length);

    return {
        pattern:
            names.length === 0
                ? null
                : new RegExp(
                      `(?<![\\w])(${names.map(escape).join('|')})(?![\\w])`,
                      'g',
                  ),
        brands,
    };
}

/** Splits text into plain strings and the wrestler names found in it. */
export function splitMentions(
    text: string,
    matcher: MentionMatcher,
): MentionPart[] {
    if (matcher.pattern === null) {
        return [text];
    }

    const parts: MentionPart[] = [];
    let last = 0;

    for (const match of text.matchAll(matcher.pattern)) {
        const index = match.index ?? 0;

        if (index > last) {
            parts.push(text.slice(last, index));
        }

        parts.push({
            name: match[0],
            brand: matcher.brands.get(match[0]) ?? null,
        });
        last = index + match[0].length;
    }

    if (last < text.length) {
        parts.push(text.slice(last));
    }

    return parts;
}

<?php

namespace App\Services\Matches\Stories;

/**
 * What a conditional story says once the match is over: the pop-up's title
 * and facts, and the story added to the match's news.
 */
final readonly class StoryText
{
    public function __construct(
        public string $title,
        public string $text,
        public string $whatChanged,
        /** "Next chance" or "Rivalry", for the pop-up's last fact. */
        public string $nextLabel,
        public string $next,
    ) {}
}

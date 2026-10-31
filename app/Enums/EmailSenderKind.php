<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Who an email comes from, for the inbox's sender header: the brand's
 * commentator (JR / Tazz), Vince McMahon, the Trainer, the rival GM or a
 * superstar writing in themselves.
 */
enum EmailSenderKind: string
{
    use HasValues;

    case Commentator = 'commentator';
    case Chairman = 'chairman';
    case Trainer = 'trainer';
    case RivalGm = 'rival_gm';
    case Superstar = 'superstar';

    public function label(): string
    {
        return match ($this) {
            self::Commentator => 'Commentator',
            self::Chairman => 'Chairman',
            self::Trainer => 'Trainer',
            self::RivalGm => 'Rival GM',
            self::Superstar => 'Superstar',
        };
    }

    /**
     * Emails store the sender's name only; anyone who isn't one of the
     * configured senders is a superstar.
     */
    public static function fromSender(string $sender): self
    {
        $senders = (array) config('gm.email_senders');

        return match (true) {
            in_array($sender, [$senders['raw'] ?? null, $senders['smackdown'] ?? null], true) => self::Commentator,
            $sender === ($senders['vince'] ?? null) => self::Chairman,
            $sender === ($senders['trainer'] ?? null) => self::Trainer,
            in_array($sender, (array) ($senders['rival_gm'] ?? []), true) => self::RivalGm,
            default => self::Superstar,
        };
    }
}

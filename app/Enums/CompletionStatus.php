<?php

namespace App\Enums;

enum CompletionStatus: string
{
    case None = 'none';          // persönliche Aufgabe, keine Freigabe nötig
    case Pending = 'pending';    // wartet auf Eltern
    case Approved = 'approved';
    case Auto = 'auto';          // automatisch bestätigt (Frist abgelaufen oder ohne Freigabe)
    case Rejected = 'rejected';
    case Undone = 'undone';      // vom Kind selbst zurückgenommen

    public function countsAsDone(): bool
    {
        return in_array($this, [self::None, self::Pending, self::Approved, self::Auto], true);
    }
}

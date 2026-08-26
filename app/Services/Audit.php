<?php

namespace App\Services;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    public static function record(string $event, ?Model $subject = null, array $metadata = []): AuditEvent
    {
        return AuditEvent::create([
            'user_id' => auth()->id(),
            'event' => $event,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => self::sanitize($metadata),
        ]);
    }

    private static function sanitize(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (preg_match('/key|token|secret|password|authorization|credential/i', (string) $key)) {
                $metadata[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $metadata[$key] = self::sanitize($value);
            }
        }

        return $metadata;
    }
}

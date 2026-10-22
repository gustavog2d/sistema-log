<?php

namespace App\Platform\Auth;

use App\Models\PlatformAuditLog;
use App\Models\PlatformUser;
use Illuminate\Http\Request;

class PlatformAudit
{
    public function __construct(private readonly Request $request) {}

    /** @param  array<string, scalar|null>  $metadata */
    public function record(string $event, ?PlatformUser $user = null, array $metadata = []): void
    {
        PlatformAuditLog::create([
            'platform_user_id' => $user?->id,
            'event' => $event,
            'ip' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 255),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}

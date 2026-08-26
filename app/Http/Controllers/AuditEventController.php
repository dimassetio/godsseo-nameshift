<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditEventController extends Controller
{
    public function index(Request $request): Response
    {
        $event = $request->string('event')->toString();

        return Inertia::render('settings/audit-events', [
            'events' => AuditEvent::with('user:id,name,email')
                ->when($event, fn ($query) => $query->where('event', $event))
                ->latest()->paginate(50)->withQueryString(),
            'eventFilter' => $event,
            'eventNames' => AuditEvent::query()->distinct()->orderBy('event')->pluck('event'),
        ]);
    }
}

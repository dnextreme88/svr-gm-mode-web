<?php

namespace App\Http\Controllers\Gm;

use App\Http\Controllers\Controller;
use App\Models\Email;
use App\Models\Save;
use App\Models\SaveWrestler;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The GM inbox (§3 Email): hints and system notices from JR / Tazz.
 */
class EmailController extends Controller
{
    public function index(Request $request, Save $save): Response
    {
        $emails = Email::where('save_id', $save->id)
            ->where('brand', $save->player_brand)
            ->orderByDesc('week_number')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $selectedId = $request->integer('email') ?: $emails->first()?->id;
        $selected = $emails->firstWhere('id', $selectedId);

        if ($selected !== null && $selected->read_at === null) {
            $selected->update(['read_at' => now()]);
        }

        return Inertia::render('gm/email', [
            'emails' => $emails->map(fn (Email $e): array => [
                'id' => $e->id,
                'template' => $e->template->value,
                'sender' => $e->sender,
                'subject' => $e->subject,
                'week_number' => $e->week_number,
                'read' => $e->read_at !== null,
            ])->values(),
            'selected' => $selected === null ? null : [
                'id' => $selected->id,
                'template' => $selected->template->value,
                'sender' => $selected->sender,
                'subject' => $selected->subject,
                'body' => $selected->body,
                'payload' => $selected->payload,
                'week_number' => $selected->week_number,
            ],
            // Every name in the save, so the inbox can colour mentions by brand.
            'wrestlers' => SaveWrestler::with('wrestler')
                ->where('save_id', $save->id)
                ->get()
                ->map(fn (SaveWrestler $w): array => [
                    'name' => $w->name(),
                    'brand' => $w->brand?->value,
                ])
                ->values(),
        ]);
    }
}

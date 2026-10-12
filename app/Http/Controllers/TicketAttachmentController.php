<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Ticket;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authorized download of a ticket attachment. The route already requires
 * `can:view,ticket`; the attachment must belong to that ticket, so changing
 * the attachment id in the URL cannot reach another ticket's file (IDOR).
 * Files are never exposed through a public URL.
 */
class TicketAttachmentController extends Controller
{
    public function __invoke(Ticket $ticket, Attachment $attachment): StreamedResponse
    {
        abort_unless(
            $attachment->attachable_type === $ticket->getMorphClass() && $attachment->attachable_id === $ticket->id,
            404,
        );

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

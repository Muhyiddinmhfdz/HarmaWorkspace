<?php

namespace App\Domain\Ticket\Actions;

use App\Domain\Ticket\Rules\AttachmentRules;
use App\Domain\Ticket\Support\TicketAudit;
use App\Domain\Ticket\Support\TicketGuard;
use App\Models\Attachment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Store a file privately for a ticket (ADR-061): validated again here, saved
 * under a generated name (never the original file name), and only reachable
 * through the authorized download route.
 */
class AddTicketAttachment
{
    public function __construct(
        private TicketGuard $guard,
        private TicketAudit $audit,
    ) {}

    public function store(Ticket $ticket, UploadedFile $file, User $actor, bool $audit = true): Attachment
    {
        $this->guard->ensureNotFinal($ticket);

        Validator::make(['file' => $file], ['file' => ['required', ...AttachmentRules::file()]], attributes: ['file' => 'Attachment'])->validate();

        $disk = (string) config('harma.attachments.disk', 'local');
        $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));
        $directory = 'attachments/ticket/'.Date::now()->format('Y/m');
        $path = $file->storeAs($directory, Str::uuid()->toString().'.'.$extension, ['disk' => $disk]);

        if ($path === false) {
            throw new \RuntimeException('Attachment could not be stored.');
        }

        try {
            return DB::transaction(function () use ($ticket, $file, $actor, $audit, $disk, $path): Attachment {
                $attachment = new Attachment;
                $attachment->forceFill([
                    'attachable_type' => $ticket->getMorphClass(),
                    'attachable_id' => $ticket->id,
                    'uploaded_by' => $actor->id,
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 250, ''),
                    'mime_type' => (string) $file->getMimeType(),
                    'size' => (int) $file->getSize(),
                ])->save();

                if ($audit) {
                    $this->audit->record($ticket, 'ticket.attachment_added', 'Menambahkan lampiran '.$attachment->original_name, new: ['attachment' => $attachment->original_name]);
                }

                return $attachment;
            });
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }
}

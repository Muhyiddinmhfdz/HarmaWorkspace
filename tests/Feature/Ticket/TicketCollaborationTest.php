<?php

namespace Tests\Feature\Ticket;

use App\Domain\Ticket\Actions\AddTicketAttachment;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesTickets;
use Tests\TestCase;

class TicketCollaborationTest extends TestCase
{
    use CreatesTickets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_requester_comments_and_input_is_sanitized(): void
    {
        $ticket = $this->createTicket();

        $this->actingAs($ticket->requester);
        Livewire::test('tickets.conversation', ['ticketId' => $ticket->id])
            ->set('body', "<script>alert(1)</script>Halo <b>tim</b>,\nnomor transaksi TRX-1")
            ->call('send')
            ->assertHasNoErrors()
            ->assertSee('Halo tim');

        $comment = Comment::query()->sole();
        $this->assertSame("alert(1)Halo tim,\nnomor transaksi TRX-1", $comment->body);
        $this->assertSame($ticket->requester_user_id, $comment->user_id);
    }

    public function test_unauthorized_user_cannot_read_or_comment(): void
    {
        $ticket = $this->createTicket();
        $ticket->comments()->create(['user_id' => $ticket->requester_user_id, 'body' => 'Rahasia internal']);

        $this->actingAs($this->staff());

        Livewire::test('tickets.conversation', ['ticketId' => $ticket->id])->assertForbidden();
        $this->assertSame(1, Comment::query()->count());
    }

    public function test_upload_and_authorized_download(): void
    {
        $programmer = Department::factory()->create();
        $member = $this->member($programmer);
        $ticket = $this->createTicket(['current_department_id' => $programmer->id]);

        $this->actingAs($ticket->requester);
        Livewire::test('tickets.attachments', ['ticketId' => $ticket->id])
            ->set('uploads', [UploadedFile::fake()->image('screenshot-error.png', 200, 200)])
            ->assertHasNoErrors()
            ->assertSee('screenshot-error.png');

        $attachment = Attachment::query()->sole();
        $this->assertStringStartsWith('attachments/ticket/', $attachment->path);
        $this->assertStringNotContainsString('screenshot-error', $attachment->path, 'Stored under a generated name');
        Storage::disk('local')->assertExists($attachment->path);

        $this->actingAs($member)
            ->get(route('tickets.attachments.download', [$ticket, $attachment]))
            ->assertOk()
            ->assertDownload('screenshot-error.png');

        $this->actingAs($this->staff())
            ->get(route('tickets.attachments.download', [$ticket, $attachment]))
            ->assertForbidden();
    }

    public function test_attachment_cannot_be_downloaded_through_another_ticket(): void
    {
        $secret = $this->createTicket();
        $mine = $this->createTicket();
        $attachment = app(AddTicketAttachment::class)->store($secret, UploadedFile::fake()->create('gaji.pdf', 20, 'application/pdf'), $secret->requester);

        $this->actingAs($mine->requester)
            ->get(route('tickets.attachments.download', [$mine, $attachment]))
            ->assertNotFound();
    }

    public function test_forbidden_type_and_oversized_files_are_rejected(): void
    {
        $ticket = $this->createTicket();
        $upload = app(AddTicketAttachment::class);

        foreach ([
            UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
            UploadedFile::fake()->create('invoice.pdf.php', 10, 'application/x-php'),
            UploadedFile::fake()->create('renamed.pdf', 10, 'application/x-msdownload'),
            UploadedFile::fake()->create('huge.pdf', 10241 + 100, 'application/pdf'),
        ] as $file) {
            try {
                $upload->store($ticket, $file, $ticket->requester);
                $this->fail($file->getClientOriginalName().' must be rejected.');
            } catch (ValidationException) {
            }
        }

        $this->assertSame(0, Attachment::query()->count());

        $this->actingAs($ticket->requester);
        Livewire::test('tickets.attachments', ['ticketId' => $ticket->id])
            ->set('uploads', [UploadedFile::fake()->create('script.sh', 5, 'text/x-shellscript')])
            ->assertHasErrors('uploads.0');
    }

    public function test_attachments_are_listed_without_internal_path(): void
    {
        $ticket = $this->createTicket();
        $attachment = app(AddTicketAttachment::class)->store($ticket, UploadedFile::fake()->create('notes.txt', 3, 'text/plain'), $ticket->requester);

        $this->actingAs($ticket->requester)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertSee('notes.txt')
            ->assertDontSee($attachment->path);

        $this->assertArrayNotHasKey('path', $attachment->toArray());
    }
}

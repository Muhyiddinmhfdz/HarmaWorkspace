# HarmaWorkspace — Phase 4 Ticketing & Department Inbox

Phase 4 menambahkan alur request antar divisi: requester membuat Ticket ke department tujuan → Ticket masuk **Department Inbox** → department **Accept** → **Assign PIC** → dikerjakan → **Review** → **Resolve** → requester **Close**. Cabang: Request Information, Transfer Department, Revision, Reject, Reopen, Cancel.

Workflow Builder/versioning, Approval Engine, SLA, notifikasi (termasuk WhatsApp/Email), PWA, dan API mobile **tidak** termasuk (Phase 5+).

---

## 1. Database

| Tabel | Fungsi | Kolom kunci | Constraint / index | Delete |
|---|---|---|---|---|
| `statuses` (+kolom) | Status scope `ticket` | `awaits_review` (baru); memakai juga `is_initial`, `counts_as_started`, `is_blocked`, `counts_as_completed`, `is_terminal` | unique `(scope, code)` | deactivate |
| `priorities` | Priority scope `ticket` | `level` | unique `(scope, code)` | deactivate |
| `ticket_types` | Bug, Request, Incident, ... | master + `color` | `code` unique | deactivate |
| `ticket_categories` | Application, Account, ... | master | `code` unique | deactivate |
| `ticket_subcategories` | Login, Transaction, ... | master + `ticket_category_id` | FK RESTRICT | deactivate |
| `ticket_sources` | WhatsApp, Phone, ... (hanya dicatat) | master | `code` unique | deactivate |
| `ticket_resolutions` | Fixed, Answered, Duplicate, ... | master + `color`, `requires_related_ticket` | `code` unique | deactivate |
| `ticket_transfer_reasons` | Wrong Department, Escalation, ... | master | `code` unique | deactivate |
| `ticket_rejection_reasons` | Invalid Request, Duplicate, ... | master | `code` unique | deactivate |
| `ticket_relation_types` | Related To, Duplicate Of, ... | master + `is_duplicate` (eksklusif) | `code` unique | deactivate |
| `tickets` | Ticket | `number`, `title`, `description`, `requester_user_id`, `created_by`, `updated_by`, `client_id`?, `project_id`?, `task_id`?, `ticket_type_id`, `ticket_category_id`, `ticket_subcategory_id`?, `ticket_source_id`?, `status_id`, `priority_id`, `current_department_id`, `accepted_by/at`, `planned_due_at`, `current_due_at`, `first_assigned_at`, `started_at`, `resume_status_id`, `resolution_id`, `resolution_notes`, `resolved_by/at`, `closed_by/at`, `rejection_reason_id`, `rejection_notes`, `reopen_count`, `last_reopened_at` | `number` unique; `(current_department_id, status_id)`; `(current_department_id, accepted_at)`; `(status_id, current_due_at)`; `title`; index FK (requester, project, task, ...) | semua FK **RESTRICT**; ticket tidak pernah di-hard delete |
| `ticket_assignments` | Main PIC + kolaborator (riwayat) | `ticket_id`, `user_id`, `role`, `assigned_at/by`, `ended_at/by` | generated column: unique **1 PIC aktif per ticket**, unique 1 assignment aktif per user; `(user_id, ended_at, role)` | append-only (`ended_at`) |
| `ticket_transfers` | Riwayat perpindahan department | `from/to_department_id`, `transfer_reason_id`, `notes`, `transferred_by/at` | `(to_department_id, transferred_at)` | append-only |
| `ticket_relations` | Relasi ticket ↔ ticket | `ticket_id`, `related_ticket_id`, `ticket_relation_type_id`, `created_by` | unique `(ticket_id, related_ticket_id)`; **CHECK** bukan diri sendiri; pasangan terbalik dicegah di Action | dapat dilepas (teraudit) |
| `comments` | Percakapan (polymorphic, generik) | `commentable_type/id`, `user_id`, `kind`, `body` | morph index | — |
| `attachments` | File privat (polymorphic, generik) | `attachable_type/id`, `uploaded_by`, `disk`, `path` (generated), `original_name`, `mime_type`, `size` | morph index | — |
| `schedule_changes` | Riwayat deadline (sekarang juga ticket) | — | — | append-only |

Morph map baru: `ticket`, `ticket_type`, `ticket_category`, `ticket_subcategory`, `ticket_source`, `ticket_resolution`, `ticket_transfer_reason`, `ticket_rejection_reason`, `ticket_relation_type`, `ticket_assignment`, `ticket_transfer`, `ticket_relation`, `comment`, `attachment`.

Catatan: kolom `lock_version` dan soft delete di blueprint belum diperlukan — konkurensi dijaga row lock + validasi state di setiap Action (§13). `ticket_status_histories` belum dibuat; riwayat status ada di activity (old → new) dan akan dibutuhkan Workflow Engine Phase 5.

### Upgrade dari Phase 3 (tanpa `migrate:fresh`)

```bash
php artisan migrate --force
php artisan db:seed --class="Database\Seeders\System\SystemSeeder" --force   # master Ticket wajib, idempotent
```

Perbaikan final (default `GENERAL`) hanya mengubah seeder — tidak ada migration baru. Diverifikasi pada salinan database Phase 3 berisi data (3 project — POS `task_weighted` 57%, 6 task, 6 assignment, 12 milestone): 6 migration baru berjalan, **seluruh data project, task, progress, assignment, milestone, dan master lama identik** sebelum/sesudah (dibandingkan dengan diff), status & priority scope ticket terisi, permission `ticket.*` diberikan ke role default (Super Admin 11, Management 11, Staff 2) tanpa menimpa kustomisasi, SystemSeeder kedua tidak menambah apa pun, dan `migrate` kedua menghasilkan *Nothing to migrate*.

## 2. Master Data (grup "Ticket" di `/master-data`)

**SystemSeeder menjamin data master Ticket minimum yang production-safe untuk membuat Ticket inti** (ADR-065). Setelah `php artisan migrate` + `SystemSeeder` saja — tanpa DevelopmentSeeder — user dengan `ticket.create` langsung dapat membuat ticket yang valid (diuji pada database baru).

| Master | Kelompok | Seed | Default |
|---|---|---|---|
| Ticket Status | **System-required** | SystemSeeder | Lihat §4 |
| Ticket Priority | **System-required** | SystemSeeder | Low 10, Normal 20, High 30, Critical 40 |
| Ticket Type | **System-required** (wajib di form) | SystemSeeder | `GENERAL` General (netral) |
| Ticket Category | **System-required** (wajib di form) | SystemSeeder | `GENERAL` General (netral) |
| Ticket Resolution | **System-required** (resolve) | SystemSeeder | Fixed, Answered, Workaround Provided, Duplicate (*requires primary ticket*), Not Reproducible, Not Required, Completed |
| Transfer Reason | **System-required** (transfer) | SystemSeeder | Wrong Department, Requires Different Expertise, Escalation, Work Ownership Changed, Other |
| Rejection Reason | **System-required** (reject) | SystemSeeder | Invalid Request, Duplicate, Insufficient Information, Out of Scope, Wrong Process, Other |
| Ticket Relation Type | **System-required** (duplikat) | SystemSeeder | Related To, Duplicate Of (*duplicate*), Blocks, Parent Of, Child Of |
| Ticket Subcategory | Optional / organization-configurable | — (contoh di DevelopmentSeeder) | Tetap opsional di form & backend |
| Ticket Source | Optional / organization-configurable | — (contoh di DevelopmentSeeder) | Tetap opsional di form & backend |
| Type & Category tambahan (Bug, Request, Application, ...) | Organization-configurable | — (contoh di DevelopmentSeeder) | Diatur admin |

Aturan SystemSeeder (sama untuk semua master):

- `firstOrCreate` berdasarkan `code` (dan `scope` untuk status/priority): record hanya dibuat bila code belum ada; tidak ada truncate, delete, atau force update.
- Nama, warna, urutan, deskripsi, dan status aktif yang diubah admin **tidak pernah ditimpa** (mis. `GENERAL` yang diganti menjadi "General Support Request" tetap begitu). Menjalankan seeder berulang tidak membuat duplikat (diuji, dan diverifikasi pada database Phase 4 berisi data: satu-satunya perubahan adalah penambahan dua record `GENERAL`).
- Default yang **dinonaktifkan admin tidak diaktifkan kembali** secara diam-diam. Persyaratan "minimal satu Ticket Type dan Category aktif" didokumentasikan di sini dan ditegakkan lewat UX: bila tidak ada yang aktif, form New Ticket menampilkan peringatan "Master Ticket Type belum tersedia." / "Master Ticket Category belum tersedia. Hubungi administrator untuk mengatur Master Data Ticket." dan tombol Create dinonaktifkan — tanpa error teknis.
- Backend (`CreateTicket`/`UpdateTicket` via `TicketGuard`) tetap menolak type/category/subcategory nonaktif dan subcategory dari category lain, terlepas dari isi select di UI.

Master Data Center mendapat tipe field baru `relation` (Subcategory → parent Category; tabel menampilkan kolom Category).

## 3. Ticket, Requester, Relasi

- **Standalone, Project Ticket, atau Task-related** (ADR-051): `project_id` dan `task_id` nullable. Memilih task mengisi project dari task tersebut; task dari project lain ditolak.
- Project/Task yang dikaitkan harus **dapat dilihat** oleh pembuat/pengubah; project arsip atau berstatus terminal **tidak menerima ticket baru** (pesan menyarankan ticket standalone untuk support pasca-project).
- `client_id` opsional (diisi otomatis dari client project, tetap dapat diubah). Client bukan user.
- `requester_user_id` (default = pembuat) dibedakan dari `created_by`; `CreateTicket` sudah menerima requester lain (fondasi *on behalf of*), belum ada UI-nya.
- Nomor `TCK-YYYY-NNNN` dari `NumberGenerator` (row-locked, tidak pernah dipakai ulang).
- Field umum (title, description, type, category, subcategory, source, client) diubah lewat `UpdateTicket`; ganti category memvalidasi ulang subcategory. Field sensitif hanya lewat Action khusus dan tidak mass-assignable (`resolved_at`, `closed_at`, `accepted_by/at`, `created_by`, ... — diuji).

## 4. Status & Lifecycle (ADR-056, ADR-057, ADR-064)

| Code | Flag | Arti |
|---|---|---|
| `WAITING_ASSIGNMENT` | initial | Antrian Department Inbox (ticket baru / setelah transfer) |
| `ASSIGNED` | — | PIC ditetapkan, belum mulai |
| `IN_PROGRESS` | started | Dikerjakan |
| `NEED_INFO` | waiting (`is_blocked`) | Menunggu informasi requester |
| `IN_REVIEW` | started + `awaits_review` | Menunggu keputusan resolve/revisi |
| `REVISION` | started | Perlu perbaikan |
| `RESOLVED` | completed (tidak terminal) | Tim menyatakan selesai, menunggu requester |
| `CLOSED` | completed + terminal | Diterima & final |
| `REJECTED` / `CANCELLED` | terminal | Tidak dikerjakan |
| `REOPENED` | — | Dibuka kembali |

Semantik di kode: *aktif* = tidak terminal dan tidak completed; *resolved* = completed dan tidak terminal; *closed* = completed dan terminal. Nama status bebas diubah admin.

`TicketLifecycle` (berbasis code, satu tempat):

```
Waiting Assignment → Assigned, Need Information, Rejected, Cancelled
Assigned           → In Progress, Need Information, Cancelled
In Progress        → Need Information, In Review, Cancelled
Need Information   → (status sebelum diminta), Cancelled
In Review          → Resolved, Revision, Need Information
Revision           → In Progress, In Review
Resolved           → Closed, Reopened
Closed             → Reopened
Reopened           → Assigned, In Progress
```

Setiap langkah adalah operasi bernama di **satu Action**, `ChangeTicketStatus` (`start`, `requestInformation`, `provideInformation`, `sendToReview`, `requestRevision`, `resolve`, `close`, `reopen`, `reject`, `cancel`), yang mengunci baris dan memvalidasi dari status **saat ini** — double click, layar basi, atau manipulasi request tidak dapat melompati langkah. Assign memakai operasi `assign` dari lifecycle yang sama. Transfer adalah Action tersendiri yang mengembalikan ticket ke status initial. UI tidak pernah menampilkan dropdown semua status: hanya aksi yang valid **dan** diizinkan, dengan **satu primary action** (Accept → Assign PIC → Provide Information → Start Work → Send to Review → Resolve → Close Ticket) dan sisanya di menu More. Phase 5 dapat mengganti `TicketLifecycle`/`ChangeTicketStatus` dengan workflow versioned tanpa menyentuh komponen Livewire.

Efek samping: `started_at` hanya diisi sekali (tidak ditimpa saat revisi/reopen); `resolved_at/by` + resolution saat resolve; `closed_at/by` saat close; reject menyimpan reason + notes.

## 5. Department Inbox (ADR-052, ADR-053)

- Ticket hanya punya **satu `current_department_id`**; saat dibuat = department tujuan (aktif, dipilih lewat *department picker* yang dapat dicari).
- `/department-inbox`: selector **All my departments** / department tertentu (keanggotaan aktif; `ticket.view-all`/`ticket.manage-inbox` dapat memilih semua department). Tabs dengan jumlah nyata: **New, Unassigned, Assigned, In Progress, Waiting, Review, Overdue, All**.
- Definisi (semantic, satu sumber di `TicketQueue`): *New* = aktif, `accepted_at` null, tidak menunggu; *Unassigned* = diterima tanpa PIC aktif; *Assigned* = punya PIC, belum mulai; *In Progress* = started, tidak menunggu/review; *Waiting* = flag waiting; *Review* = `awaits_review`; *Overdue* = aktif dan `current_due_at < now`.
- Semua counter sebuah halaman dihitung dengan **satu query agregat** (`SUM(CASE WHEN …)`), kondisi yang sama dipakai filter list sehingga angka dan isi tab tidak pernah berbeda.
- Tabel desktop: Ticket, Requester, Type, Priority, Age (sejak ticket diterima department saat ini: `created_at`, atau waktu transfer terakhir; tidak pernah `updated_at`), PIC, Status, Deadline + aksi cepat **Accept** / **Assign**. Mobile: kartu dengan tombol Accept/Assign PIC. Ticket yang sudah diterima > 1 hari tanpa PIC diberi indikator ringan "Belum ada PIC" (tanpa notifikasi).
- **Accept ≠ Assign**: `AcceptTicket` mengisi `accepted_by/at` dan memindahkan ticket ke *Unassigned*; accept ganda ditolak "Ticket sudah diterima oleh X. Muat ulang data terbaru." Assign pada ticket yang belum diterima sekaligus mencatat penerimaan.

## 6. Assignment (ADR-054)

- Satu Main PIC aktif (generated column unique + row lock) dan kolaborator, keduanya harus **anggota aktif current department**. Pekerjaan lintas department memakai Transfer atau relasi Task/Project.
- `ManageTicketAssignments::assign()` (assign pertama; gagal bila sudah ada PIC: "Ticket sudah ditugaskan ke X oleh pengguna lain. Muat ulang data terbaru."), `changePic()` (riwayat PIC lama di-*end*), `addCollaborator()`, `removeCollaborator()`.
- Assign pertama mengisi `first_assigned_at` dan memindahkan status antrian ke Assigned lewat lifecycle.

## 7. Transfer (ADR-055)

`TransferTicket`: target harus aktif dan berbeda dari department saat ini, reason wajib, ticket harus aktif. Menutup PIC & kolaborator aktif, menulis `ticket_transfers`, mengganti department, mereset `accepted_by/at` dan `resume_status_id`, status kembali ke initial, activity `ticket.department_transferred`. Department lama **tidak lagi melihat** ticket (kecuali sebagai requester/creator); riwayat transfer tampil di detail ("Department history").

## 8. Need Information, Review, Revision

- **Request Information**: pesan wajib; status sebelumnya disimpan di `resume_status_id`; pesan disimpan sebagai comment bertipe `information_request`; callout "Waiting for Information" menampilkan pesan + tombol Provide Information.
- **Provide Information** (requester/creator, leader, `ticket.update`): comment `information_provided`, status kembali ke `resume_status_id` (bukan selalu In Progress — dari antrian kembali ke antrian; diuji).
- **Send to Review** oleh PIC (catatan opsional). **Resolve/Request Revision** oleh reviewer (§10). Revisi wajib alasan (comment `revision_request`, callout "Revision Requested" + Continue Work).
- **Resolve** wajib resolution + catatan. Resolution bertanda *requires primary ticket* (Duplicate) wajib memilih ticket utama dan otomatis membuat relasi bertipe *duplicate*.
- **Close** (requester, leader, `ticket.close`) dari Resolved. **Reopen** dari Resolved/Closed wajib alasan; `reopen_count` + `last_reopened_at`; nilai resolusi/penutupan lama tersimpan sebagai *old values* di activity (tidak hilang diam-diam).
- **Reject** (intake, sebelum dikerjakan) wajib reason + notes; **Cancel** wajib alasan. Keduanya terminal — ticket final read-only (edit, komentar, lampiran, assignment ditolak).

## 9. Deadline

`planned_due_at = current_due_at` saat dibuat (tanggal, disimpan sebagai akhir hari). `ChangeTicketDeadline`: baseline pertama tanpa alasan; perubahan berikutnya wajib alasan, planned tidak berubah, tercatat di `schedule_changes`. Overdue memakai scope terpusat `Ticket::overdue()` / queue `overdue`. **Tidak ada SLA otomatis**.

## 10. Permissions & Hak Kontekstual (ADR-062)

| Permission | Fungsi | Management | Staff |
|---|---|:-:|:-:|
| `ticket.view` | Melihat ticket yang relevan | ✓ | ✓ |
| `ticket.view-all` | Melihat seluruh ticket & inbox | ✓ | |
| `ticket.create` | Membuat ticket | ✓ | ✓ |
| `ticket.update` | Mengubah informasi ticket | ✓ | |
| `ticket.manage-inbox` | Accept, reject, priority di semua inbox | ✓ | |
| `ticket.assign` | PIC & kolaborator | ✓ | |
| `ticket.transfer` | Transfer department | ✓ | |
| `ticket.manage-deadline` | Deadline | ✓ | |
| `ticket.review` | Resolve / minta revisi | ✓ | |
| `ticket.close` | Close & reopen | ✓ | |
| `ticket.cancel` | Cancel | ✓ | |

| Kemampuan | Requester / creator | Main PIC | Leader current dept | Permission global |
|---|:-:|:-:|:-:|---|
| View, comment, lampiran (ticket aktif) | ✓ | ✓ | ✓ | `ticket.view(-all)` |
| Edit info umum | ✓ | | ✓ | `ticket.update` |
| Accept / reject / priority | | | ✓ | `ticket.manage-inbox` |
| Assign / ganti PIC / kolaborator | | | ✓ | `ticket.assign` |
| Transfer | | | ✓ | `ticket.transfer` |
| Deadline | | | ✓ | `ticket.manage-deadline` |
| Start / continue, send to review | | ✓ | ✓ | `ticket.manage-inbox` |
| Request information | | ✓ | ✓ | + reviewer |
| Provide information | ✓ | | ✓ | `ticket.update` |
| Resolve / request revision | ✓ *(bukan bila dia PIC)* | ✗ | ✓ | `ticket.review` |
| Close / reopen | ✓ | | ✓ | `ticket.close` |
| Cancel | ✓ | | ✓ | `ticket.cancel` |

Tidak ada role global "Reviewer", "Technical Support", atau "Programmer"; konteks datang dari membership department, assignment, dan relasi ticket. PIC tidak dapat me-resolve pekerjaannya sendiri kecuali memegang `ticket.review` atau memimpin department. Staff department dapat melihat inbox tetapi tidak otomatis accept/assign/transfer. Super Admin mem-bypass Policy, tetapi invariant (state, department, visibility relasi) tetap dijaga di Action.

## 11. Visibility (ADR-058)

Satu sumber: `Ticket::scopeVisibleTo()` + `isVisibleTo()`:

- user nonaktif → tidak ada; `ticket.view-all` → semua; tanpa `ticket.view` → tidak ada;
- requester, creator, PIC & kolaborator aktif, **anggota aktif current department** (termasuk leader), dan Project Manager project terkait.

Department lama setelah transfer dan membership yang sudah berakhir **tidak** memberi akses. **Melihat project ≠ melihat ticket project**; sebaliknya anggota department yang melihat ticket tidak otomatis melihat project/task terkait — UI menampilkan "Project terbatas" / "Task terbatas" tanpa nama. Event ticket di timeline project/task hanya ditampilkan untuk ticket yang terlihat. Scope dipakai oleh list, inbox, My Tickets, counter, dashboard, global search, conversation, attachment, ticket picker, dan Policy; URL langsung yang tidak berhak → 403.

## 12. Conversation & Attachments (ADR-060, ADR-061)

- `comments` generik polymorphic, terpisah dari activity: percakapan = pesan user, Activity = event sistem (event `ticket.comment_added` tetap di audit tetapi disembunyikan dari timeline ticket agar tidak dobel). Plain text: disanitasi (tag & karakter kontrol dibuang, newline dipertahankan), di-escape saat tampil. Pesan langkah lifecycle diberi badge ("Meminta informasi", "Meminta revisi"). Conversation dan Activity memakai load more.
- `attachments` generik polymorphic, disk privat (`config('harma.attachments.disk')`, default `local` = `storage/app/private`), path **generated** (`attachments/ticket/YYYY/MM/{uuid}.{ext}` dari ekstensi hasil deteksi konten), nama asli hanya untuk tampilan, `disk`/`path` disembunyikan dari serialisasi.
- Validasi (sama di Livewire dan di Action — `AttachmentRules`): MIME dari konten (`mimetypes`) + ekstensi hasil deteksi (`mimes`) + ekstensi nama file (`extensions`) harus masuk whitelist **JPG, JPEG, PNG, PDF, DOC, DOCX, XLS, XLSX, TXT**; maksimal **10 MB per file** (`HARMA_ATTACHMENT_MAX_KB`), maksimal 5 file per unggahan. Executable, double extension (`.pdf.php`), dan file yang di-rename ditolak.
- Download hanya lewat `GET /tickets/{ticket}/attachments/{attachment}` (`can:view,ticket`; attachment wajib milik ticket tersebut → 404 bila ID ditukar; `X-Content-Type-Options: nosniff`). Tidak ada URL storage publik.

## 13. Konkurensi & Idempotensi

Nomor (row lock `number_sequences`), accept, assign, transfer, dan setiap langkah status mengunci baris ticket lalu memvalidasi state terkini; satu PIC aktif dijamin unique index. Tombol aksi memakai `wire:loading.attr="disabled"`. Pelanggaran tampil sebagai pesan ramah (mis. "Muat ulang data terbaru"), bukan error SQL.

## 14. Project & Task (ADR-059)

- Project detail: tab **Tickets** (Open, In Progress, Waiting, Resolved, All + New Ticket yang mengisi project) dan kartu ringkas *Tickets* di Overview (hanya ticket yang terlihat). Tab: Overview, Tasks, Tickets, Milestones, Team, Activity.
- Task detail: section **Related Tickets** + New Ticket (task & project terisi otomatis).
- Activity: `ticket.created`, `ticket.resolved`, `ticket.reopened` tampil di timeline project; `ticket.created`, `ticket.resolved` di activity task (lewat `properties->project_id/task_id`). Ticket kritis ditandai di deskripsi event.
- **Project completion diblokir** oleh ticket aktif (tidak terminal dan belum resolved), digabung dengan pesan task: "…masih terdapat 2 Task aktif: …; serta 1 Ticket aktif: 1 Ticket masih In Progress." Resolved/closed/rejected/cancelled tidak memblokir.
- Lifecycle independen: Ticket resolved ≠ Task done, Task done ≠ Ticket closed, Project selesai ≠ ticket ditutup otomatis (diuji).
- *Create Related Task* dari ticket **ditunda**: link ke task yang sudah ada tersedia; membuat task tetap lewat halaman task (reuse `CreateTask`) agar scope tidak melebar.

## 15. UI & Routes

| Route | Halaman |
|---|---|
| `/my-tickets` | Waiting for Me (default bila ada isinya), Assigned to Me, Requested by Me, Collaborating, Resolved, Closed; urutan actionable: menunggu saya → overdue → due today → in progress → lainnya → resolved/closed |
| `/department-inbox` | Work queue department (§5) |
| `/tickets` | Daftar global: Open, My Tickets, Unassigned, In Progress, Waiting, Review, Overdue, Resolved, Closed, All; search (nomor, judul, client, requester); More filters (department, status, priority, type, client, project, PIC, requester, deadline); kolom Ticket, Requester, Department, PIC, Status, Priority, Updated, Deadline |
| `/tickets/create` | Form cepat (Title, Type, Category, Priority, Target Department, Description) + More options (Project, Task, Subcategory, Source, Client, Due date, Attachments). `?project=` / `?task=` mengisi relasi |
| `/tickets/{ticket}` | Header (nomor, judul, status, priority, primary action + More), callout (Waiting for Information / Revision Requested / Resolution Ready / final), kolom utama Description → Conversation → Attachments → Related → Department history → Activity, panel properti (Department, Priority, PIC, Collaborators, Requested by, Deadline, Client, Type/Category, Source, Created/Accepted). Mobile: satu kolom, properti ringkas sebelum percakapan |
| `/tickets/{ticket}/edit` | General + Related work (status/assignment/deadline tetap dari detail) |

Dashboard: kartu **My Open Tickets, Waiting for My Response, Tickets Due Today, Overdue Tickets** + daftar My Tickets (semua user); panel **Department Inbox** (New, Unassigned, In Progress, Waiting Information, In Review, Overdue) untuk leader; **Ticket Overview** (Open, Critical, Unassigned, Overdue, In Review) untuk `ticket.view-all`. Semua angka dari scope visibility. Global Search: grup **Tickets** (nomor, judul, nama client; subtitle "TCK… · Department • Status"). Global Create: **New Ticket**. Navigasi: My Work → My Tickets; Work → Tickets, Department Inbox. Tidak ada bell counter palsu.

## 16. Activity & Kesiapan Fase Berikutnya

Event (`TicketAudit`, subject = ticket, properti `ticket_number`, `project_id`, `task_id`): `ticket.created`, `ticket.updated`, `ticket.accepted`, `ticket.assigned`, `ticket.pic_changed`, `ticket.collaborator_added/removed`, `ticket.department_transferred`, `ticket.started`, `ticket.status_changed`, `ticket.information_requested/provided`, `ticket.sent_to_review`, `ticket.revision_requested`, `ticket.resolved`, `ticket.closed`, `ticket.reopened`, `ticket.rejected`, `ticket.cancelled`, `ticket.priority_changed`, `ticket.deadline_changed`, `ticket.comment_added`, `ticket.attachment_added`, `ticket.project_linked`, `ticket.task_linked`, `ticket.relation_added/removed`.

Setiap event juga memicu `TicketActivityRecorded` (setelah commit, belum ada listener) sebagai titik masuk Notification Phase. Langkah review memakai Policy dasar sehingga Approval Engine Phase 5 dapat menggantikannya.

## 17. Tests

`php artisan test` → **365 test lulus, 1196 assertion** (MySQL). PHPStan level 7: 0 error. Pint: bersih. Suite Phase 4 (`tests/Feature/Ticket`, 74 test):

| File | Cakupan |
|---|---|
| `TicketCreationTest` | create dari form, nomor TCK unik & berurutan, validasi wajib, standalone/project/task/client, task project lain ditolak, subcategory harus sesuai category, department nonaktif, project/task tak terlihat ditolak, project berakhir menolak ticket, mass assignment, prefill dari task |
| `TicketInboxTest` | ticket muncul hanya di inbox department tujuan, inbox department lain 403, leader accept → Unassigned, staff tidak bisa accept, double accept, **counter New/Unassigned/Assigned/In Progress/Waiting/Review/Overdue akurat**, deadline baseline/alasan/riwayat/overdue |
| `TicketAssignmentTest` | leader assign dari UI, PIC harus anggota current department, 1 PIC aktif dijaga DB, assign basi ditolak, ganti PIC + riwayat + activity, kolaborator duplikat/PIC/department lain ditolak, staff tidak bisa assign |
| `TicketTransferTest` | transfer oleh leader (assignment ditutup, accept direset, status initial, riwayat, department baru menerima sebagai New, department lama kehilangan akses), alasan wajib, department sama/nonaktif ditolak, tanpa izin ditolak, ticket resolved tidak bisa ditransfer |
| `TicketLifecycleTest` | alur penuh sampai Closed, revisi tanpa menimpa `started_at`, Need Information kembali ke status sebelumnya (termasuk antrian), PIC send to review tapi tidak bisa self-resolve, reviewer resolve wajib resolution, reopen wajib alasan + riwayat, reopen dari Resolved, transisi invalid, manipulasi request, reject/cancel wajib alasan & final, ticket closed tidak bisa diedit/dikomentari, primary action sesuai state |
| `TicketVisibilityTest` | requester/PIC/kolaborator/anggota department/Management terlihat; outsider & nonaktif tidak; URL langsung; project visibility ≠ ticket visibility + relasi disamarkan; PM project; global search; counter dashboard; panel inbox leader; membership berakhir |
| `TicketCollaborationTest` | komentar tersanitasi, outsider tidak bisa membaca/komentar, upload + download berizin, IDOR attachment antar ticket, MIME/ekstensi terlarang & file terlalu besar ditolak, path internal tidak terekspos |
| `TicketProductionReadinessTest` | master production minimum dari SystemSeeder (tanpa DevelopmentSeeder), instalasi baru langsung bisa membuat ticket (subcategory & source opsional), form tidak crash bila semua Type/Category nonaktif, backend menolak type/category nonaktif, SystemSeeder berulang tidak menimpa kustomisasi/tidak mengaktifkan kembali/tidak duplikat, SystemSeeder pada data Phase 4 tidak mengubah apa pun, PM project hanya melihat (tanpa accept/assign/transfer/review/close/cancel) |
| `TicketWorkRelationTest` | link project/task tidak konsisten ditolak, link tercatat & butuh akses, lifecycle ticket/task independen, **project completion diblokir ticket aktif** lalu diizinkan, resolution Duplicate menautkan ticket utama, relasi diri sendiri/duplikat ditolak, Related Tickets di task, tab Tickets project |

Juga diperbarui: `Ui/PageSmokeTest` (+11 halaman ticket), `System/SeederTest` (8 ticket demo per queue, idempotensi master ticket & kustomisasi admin tidak ditimpa, tidak ada ticket di SystemSeeder), `Access/RoleManagementTest` (permission Staff baru).

## 18. Architecture Decisions

| ADR | Keputusan | Alasan / konsekuensi |
|---|---|---|
| ADR-051 | **Ticket dapat berdiri tanpa Project/Task** (keduanya nullable, task harus satu project) | Request operasional tidak dipaksa masuk project; arah relasi satu jalur ticket → task → project. |
| ADR-052 | **Ticket punya satu Current Department** (`current_department_id`), terpisah dari riwayat transfer | Kepemilikan selalu jelas; inbox cukup memfilter satu kolom terindeks. |
| ADR-053 | **Accept dan Assign adalah konsep berbeda** | Department dapat mengakui request sebelum menentukan orang; tab Unassigned bermakna. Assign tanpa accept mencatat penerimaan sekaligus. |
| ADR-054 | **Satu Main PIC + kolaborator dari current department**, riwayat append-only | Konsisten dengan Task (ADR-040); kerja lintas divisi lewat transfer, bukan assignment ambigu. |
| ADR-055 | **Transfer memindahkan kepemilikan sepenuhnya tetapi menyimpan riwayat**; department lama kehilangan akses | Tidak ada akses permanen dari keterlibatan historis; riwayat tetap terbaca oleh yang berhak. |
| ADR-056 | **Resolved ≠ Closed** (completed non-terminal vs completed terminal) | Tim menyatakan selesai, requester yang menutup; reopen dari keduanya. |
| ADR-057 | **Lifecycle ticket terpusat** (`TicketLifecycle` + satu `ChangeTicketStatus` dengan operasi bernama) sampai Workflow Engine | Tidak ada peta transisi di komponen; Phase 5 cukup mengganti satu lapisan. |
| ADR-058 | **Visibility ticket independen dari visibility project**; relasi yang tidak boleh dilihat disamarkan | Ticket tidak membocorkan project privat, project tidak membuka semua ticket. |
| ADR-059 | **Lifecycle Project/Task tidak mengikuti Ticket**; project tidak bisa diselesaikan selama ada ticket aktif | Sesuai blueprint §5; penyelesaian project bermakna tanpa penutupan otomatis. |
| ADR-060 | **Comment terpisah dari Activity**; pesan langkah lifecycle = comment bertipe (`kind`) | Satu tempat untuk teks user, satu tempat untuk event sistem. |
| ADR-061 | **Attachment disimpan privat**, nama generated, validasi isi + ekstensi, download berizin | Mencegah eksekusi/eksposur file dan IDOR. |
| ADR-062 | **Tanpa role reviewer global**: resolve oleh requester (bukan PIC), leader current department, atau `ticket.review` | Pemisahan pelaksana/pemeriksa; siap diganti Approval Engine. |
| ADR-063 | **Need Information menyimpan `resume_status_id`** | Kembali ke status sebelumnya, tidak hardcode In Progress. |
| ADR-064 | **Flag status `awaits_review`; "waiting" memakai `is_blocked`** | Queue Review/Waiting dihitung dari flag, bukan nama; flag review dipakai lagi oleh approval nanti. |
| ADR-065 | **SystemSeeder menjamin master minimum production-safe** untuk fitur inti (termasuk Type & Category netral `GENERAL`), tanpa menimpa atau mengaktifkan kembali pilihan admin | Instalasi baru langsung usable tanpa data demo; kustomisasi organisasi tetap dihormati; kekosongan master ditangani dengan UX yang jelas, bukan overwrite diam-diam. |

## 19. Known Limitations

- Workflow Builder, Approval Engine, SLA, notifikasi (bell/email/WhatsApp), PWA, dan API mobile belum dibuat; `TicketActivityRecorded` belum punya listener.
- Belum ada UI *create on behalf of*, *Create Related Task* dari ticket, mention, edit/hapus komentar, hapus lampiran, preview gambar, atau lampiran di dalam komentar.
- Deadline berbasis tanggal (akhir hari), tanpa jam dan tanpa SLA otomatis.
- Status ticket baru yang ditambah admin tanpa code standar hanya mengikuti aturan fallback (tidak terminal → bukan initial) sampai Workflow Engine.
- Activity seeder development dicatat atas nama user demo (bukan "System") untuk ticket.

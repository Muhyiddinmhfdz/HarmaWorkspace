# HarmaWorkspace — Phase 6 Simplification & Operational UX

Phase 6 tidak menambah sistem baru. Phase ini **menyederhanakan** HarmaWorkspace untuk kebutuhan operasional utama HARMA DIGITAL:

```
PROJECT → TASK → REPORT / ISSUE → PROGRESS
```

Backend boleh kompleks; frontend harus sederhana. Workflow Engine, Approval Engine, audit, assignment/schedule history, visibility, domain actions, dan versioning **tetap** menjadi fondasi — hanya tidak ditampilkan kepada Staff kecuali membantu mereka menyelesaikan pekerjaan.

Deadline Center, Calendar, Timeline, Gantt, Dependency, Workload, SLA, Automation, Notifications, PWA, dan analytics **tidak** dibangun (lihat §12).

---

## 1. Prinsip

| Prinsip | Penerapan |
|---|---|
| Simplicity first | Staff cukup memahami Project, Task, Report, PIC, Status, Progress, Deadline. Istilah Workflow Version, Transition, Approval Template, Weight Resolver, Scope tidak muncul di halaman operasional. |
| Progressive disclosure | Informasi penting tampil dulu; detail lanjutan di **More Details** (`<details>`), menu **More (…)**, atau tab **More**. Tidak ada data/fungsi yang dihapus. |
| Primary action + More | Satu aksi utama yang kontekstual; aksi lain di menu "…". Tidak ada deretan 8 tombol. |
| Role-aware, satu halaman | Dashboard memakai permission & role kontekstual yang sudah ada; satu komponen, bagian ditampilkan sesuai peran. |
| Security tidak berubah | Menyembunyikan UI bukan otorisasi. Semua policy, scope visibility, WorkflowGate, Approval Engine, dan validasi assignment tetap dijalankan di backend. |
| Tanpa engine baru | Query sederhana di atas scope & flag status yang ada (`NeedsAttention`), tanpa tabel persisted. |

## 2. Information Architecture

```
Dashboard
Projects
   └─ Project Workspace: Overview · Tasks · Reports · Activity · More (Milestones, Team, Project Settings)
My Work
   ├─ My Tasks
   ├─ My Reports
   └─ My Approvals
Reports
   ├─ Reports
   └─ Department Inbox          (hanya anggota department / pengelola inbox)
Administration                  (hanya bila ada menu yang boleh diakses)
   Members · Departments · Clients · Master Data · Workflows · Approval Templates · Role & Permission · System Settings · Audit Log
```

## 3. Navigation

**Sebelum** (Staff default): Overview, My Work (My Tasks, My Tickets, My Approvals), Projects, Work (Tasks, Tickets, Department Inbox), Team (Departments), Master Data (Clients) — 7 grup, termasuk halaman direktori yang hanya read-only bagi Staff.

**Sesudah** (Staff default): Dashboard · Projects · My Work (My Tasks, My Reports, My Approvals) · Reports (Reports, Department Inbox) — 4 grup, tanpa section Administration.

| Item | Ditampilkan bila |
|---|---|
| All Tasks (daftar task global) | `task.view-all` (Staff bekerja dari My Tasks & tab Tasks project) |
| Department Inbox | `ticket.view` **dan** (anggota aktif department, atau `ticket.view-all` / `ticket.manage-inbox`) — kondisi `when: department-inbox` di `config/navigation.php`, di-resolve `Navigation::condition()` |
| Departments | `user.view`, `department.create`, atau `department.update` |
| Clients | `client.create` atau `client.update` |
| Grup tanpa item | tidak dirender |

Halaman Departments/Clients tetap dapat dibuka oleh pemegang `department.view` / `client.view` (route & policy tidak berubah); hanya tidak lagi memenuhi sidebar Staff.

- **Global Create**: hanya New Project, New Task, New Report (sesuai permission). New Member/Department/Client dibuat dari halamannya masing-masing.
- **Global Search**: grup utama Projects, Tasks, **Reports**; Members/Departments/Clients tetap tersedia di bawahnya sesuai permission. Workflow/approval config tidak dicari.
- **Mobile**: sidebar tetap menjadi drawer; ditambah bottom bar (`lg:hidden`) Dashboard · Projects · My Tasks · Reports · More (membuka drawer), touch target ≥ 56px.
- Placeholder bell Notifications dihapus (notifikasi ditunda, bukan fitur palsu).

## 4. Terminology

| Backend (tidak berubah) | UI operasional |
|---|---|
| `Ticket`, tabel `tickets`, permission `ticket.*`, event `ticket.*`, route `/tickets`, `/my-tickets`, `/department-inbox` | **Report**, **Reports**, **My Reports**, **New Report**, **Open Reports**, **Related Reports** |
| Toast / pesan bisnis | Bahasa Indonesia "Laporan …" (mis. *Laporan TCK-2026-0012 berhasil dibuat.*) |
| Master Data "Ticket Type/Category" (admin) | tetap; Report Type di form = master `ticket_types` |

Route lama dipertahankan (tanpa alias `/reports`) demi stabilitas link & test. Breadcrumb mengikuti konteks: *Projects / POS Development / Reports / TCK-2026-0012* bila project terlihat oleh user, selain itu *Reports / TCK-…*.

Label aksi workflow (Close/Reject/Cancel) adalah data workflow. Blueprint default kini memakai "Close Report", "Reject Report", "Cancel Report" untuk instalasi baru; versi workflow yang sudah published **tidak** diubah (immutable, ADR-067). Admin dapat mengganti label lewat draft versi baru.

Audit/activity description lama ("Membuat ticket …") tidak ditulis ulang: itu riwayat yang sudah tersimpan.

## 5. Project Workspace

**Header** — nomor, nama, client · lead department, status (+ health bila bukan default), tombol **+ Task**, **+ Report**, menu **…** (Edit project, aksi status dari workflow, Complete, Update progress/health, Progress calculation, Change schedule, visibility, Archive/Restore). Key facts: **Progress** (bar + "Calculated from Tasks"/"Manual"), **Project Manager**, **Deadline**.

**Tabs** — maksimal 4 utama: Overview · Tasks · Reports (dengan jumlah report terbuka) · Activity. Milestones, Team, dan Project Settings ada di **More**. Link lama `?tab=tickets` dialihkan ke `reports`.

**Overview** (menjawab: bagaimana kondisinya, apa yang dikerjakan, apa yang bermasalah):

| Bagian | Sumber |
|---|---|
| Tasks: Total, Done, In Progress, Blocked, Overdue | satu query agregat dari flag status (`counts_as_completed`, `counts_as_started`, `is_blocked`, `is_terminal`) |
| Open Reports per Type | satu query agregat `TicketQueue::base()` (visibility ticket) + group by `ticket_type_id` → nama/warna dari master |
| Current Work (maks 5) | task terbuka: blocked dulu, lalu priority level, deadline, update terakhir |
| Open Issues (maks 5) | report terbuka yang terlihat: priority level, menunggu (blocked), update terakhir |
| Department Progress | `ProjectProgressCalculator::byDepartment()` (tidak berubah) |
| About this Project | deskripsi, owner, start, health; **More Details**: category, priority, visibility, jadwal planned/current/actual, perubahan jadwal terakhir, milestone terdekat, department |

Rumus bobot tidak ditampilkan; Staff hanya melihat "Calculated from Tasks" atau "Manual".

## 6. Task UX

- **Create**: field default Title, Main PIC, Department, Priority, Deadline (Project otomatis bila dari project). Status awal, Description, Type, Category, Milestone, Collaborators, Start date, Work estimate, Weight override ada di **More options**. Validasi backend tidak berubah.
- **List** (project/all/mine): kolom Task · PIC · (Department bila project multi-department) · Status · Progress · Deadline. Nomor, project, subtasks, dan badge priority (hanya di atas level default) menjadi info sekunder di sel Task. Tombol cepat **Update progress** per baris.
- **Quick views**: Project → All, My Tasks, In Progress, Blocked, Done (+ More: Open, Overdue). My Tasks → **Needs Attention** (blocked, overdue, jatuh tempo hari ini), In Progress, Blocked, Upcoming, Completed (+ More: All open as PIC, Collaborating, Due today, Overdue); default membuka Needs Attention bila ada isinya. Filter tambahan (Department, PIC, Priority, Milestone/Project, Status, Deadline) di **More Filters**.
- **Detail**: header status + priority + overdue; baris progress dengan tombol **Update progress**; satu aksi status utama (resume bila blocked → selesai → mulai → status berikutnya menurut urutan master), sisanya di **…** (termasuk New Report, Change schedule, Edit). Kendala **Blocked** tampil sebagai callout berisi alasan. Sidebar: PIC, Department, Priority, Project, Deadline, Collaborators; **More Details**: Milestone, jadwal & baseline/aktual, estimasi jam & bobot progress, Type/Category, Created. Main: Description, Approval (hanya bila relevan), Subtasks, Checklist, **Related Reports**, Activity.

## 7. Report UX

- **New Report**: "What are you reporting?" — kartu pilihan dari master Ticket Type (tanpa logika berdasarkan nama), Title, Project + Related Task (terkunci bila dibuat dari project/task), Target Department, Category (otomatis terpilih bila hanya satu), Description. **More options**: Priority, Due date, Client, Subcategory, Source, Process (hanya bila ada lebih dari satu workflow), Attachments. Task di luar project yang dipilih ditolak `TicketGuard::work()`; project yang terkunci tidak dapat diganti (`#[Locked]` + 403).
- **List**: Reports → Report · Type · PIC / Department · Status · Priority · Updated; My Reports → Report · Status · Who Is Handling · Last Update dengan tab Waiting for Me, Assigned to Me, Reported by Me, Recently Resolved (+ More: Collaborating, Closed). Nama project hanya ditampilkan bila project tersebut terlihat oleh user (satu query per halaman).
- **Detail**: header Type badge, status, priority, overdue; satu aksi utama kontekstual (Accept → Assign PIC → Provide Information → Start Work → Send to Review → Resolve → Close) dari workflow, sisanya di **…**. Sidebar: Reported by, Department, Priority, PIC, Project, Related Task (dimasking bila tidak berhak), Deadline, Collaborators; **More Details**: Category, Client, Source, deadline awal, Created/Accepted. Resolution tampil menonjol hanya setelah resolved/closed. Related Reports hanya muncul bila ada atau dapat ditautkan. Riwayat transfer di **History** (collapsible). Nama workflow/versi tidak ditampilkan.

## 8. Department Inbox

Tetap work queue. Tab utama: New, Unassigned, In Progress, Waiting, Review (+ Overdue bila ada); Assigned & All di **More**. Kolom: Report · Type · Priority · Requester · PIC · Status · Age, dengan aksi Accept/Assign per baris. Counter tetap dari satu query agregat `TicketQueue`.

## 9. Dashboard (role-aware)

| Peran | Ringkasan (4–5 kartu) | Panel |
|---|---|---|
| Staff | My Open Tasks, Blocked, My Open Reports, Reports Waiting, (Pending My Approval bila ada) | My Tasks (5, blocked dulu, progress & deadline), Needs Attention, My Projects |
| Project Manager / Owner | sama dengan Staff | **Managed Projects**: progress, deadline, jumlah task blocked & report terbuka per project |
| Department Leader | sama dengan Staff | **Department Work**: New Reports, Unassigned, In Review, Blocked Tasks department |
| Management (`project.view-all`) | **Company Overview**: Active Projects, Projects At Risk (health di atas level default), Open Reports, Blocked Work | **Project Progress**: project aktif, yang paling bermasalah di atas |

**Needs Attention** (`App\Domain\System\Dashboard\NeedsAttention`): task blocked milik user (beserta alasan), task overdue, report yang menunggu user (alasan dari flag status), approval tertunda, dan untuk leader report tanpa PIC / report baru. Semua sumber memakai scope visibility masing-masing; tidak ada tabel baru.

Dihapus dari dashboard: kartu Active Members/Clients/Departments, Ticket Overview, Upcoming Deadlines, Recent Activity (tetap di Audit Log), My Organization, placeholder "Coming soon".

## 10. Workflow & Approval

- Tombol aksi tetap berasal dari `availableTransitions()` dan dieksekusi lewat `ExecuteWorkflowTransition`/domain action; tidak ada peta transisi di UI.
- Bahasa UI: Action, Status, Review, Approval — bukan Transition, Workflow State, Workflow Version.
- Transisi ber-approval ditandai "(approval)", membuka modal "Request Approval", dan **tidak** mengubah status (test: `TaskOperationalUxTest::test_approval_gated_primary_action_still_requires_approval`).
- Panel approval (`approvals.progress`) hanya muncul bila record pernah membutuhkan approval; menampilkan tahap secara sederhana (✓ selesai, ● menunggu). Ringkasan terbatas untuk approver Phase 5 tidak berubah.

## 11. Compatibility, Database & Performance

- **Tanpa migration.** Perubahan hanya UI, query, relasi model read-only (`Project::tasks()`, `Project::tickets()`), scope `Task::inProgress()` / `Task::notStarted()`, pesan, dan seeder demo. Database Phase 5 cukup di-deploy tanpa `migrate:fresh`.
- Route, permission, event, dan nama kelas tetap. URL lama (`?tab=tickets`, `?view=assigned`, dll.) tetap valid.
- `DevelopmentSeeder` menambah tipe Maintenance, Issue, Note (`firstOrCreate`, non-destruktif) serta dua report demo (Maintenance, Note) di POS Development. `SystemSeeder` tidak berubah.
- Query per halaman (data uji 10 task + 10 report, sebelum → sesudah): Project Overview 47 → 54, Project Tasks 52 → 55, Project Reports 47 → 51, Department Inbox 12 → 15, My Tasks 7 → 10, Dashboard 21. Jumlah query konstan terhadap jumlah baris (tanpa N+1; diuji dengan 2 vs 14 baris).

## 12. Deferred (bukan Cancelled)

Phase 6 lama (Deadline Center, Timeline, Calendar, approval perubahan jadwal, notifikasi deadline) dan fase berikutnya (Gantt, Dependency, Workload, Reports & Analytics, SLA/Automation, Notifications, PWA, Mobile) **ditunda** sampai ada kebutuhan nyata dari pilot. Roadmap baru:

```
Phase 6 Simplification & Operational UX
   ↓
Pilot internal HARMA DIGITAL
   ↓
Kumpulkan pain point nyata
   ↓
Pilih fitur berikutnya (hanya yang benar-benar dibutuhkan)
```

## 13. Tests

`php -d memory_limit=1G vendor/bin/phpunit` → **451 test lulus, 1567 assertion** (MySQL). PHPStan level 7: 0 error. Pint: bersih. `npm run build`: sukses.

Review browser (headless Chrome, 1440px & 390px) sebagai Staff, Project Manager + Department Leader, Management, dan Staff dengan task: Dashboard, Projects, Project Overview/Tasks/Reports/Team, My Tasks, Task Detail, Reports, My Reports, Report Detail, New Report, New Task, Department Inbox, My Approvals, All Tasks — 0 horizontal overflow, 0 JS error. Journey interaktif lulus: update progress task (mobile), New Report dari task terisi otomatis, Accept dari Department Inbox, modal Assign PIC, quick-create task dari project (cukup judul), More → Team, Dashboard → Project.

Suite Phase 6 (28 test baru) selain penyesuaian test lama yang memeriksa label/kartu yang sengaja diganti:

| File | Cakupan |
|---|---|
| `System/NavigationTest` | menu Staff operasional saja, tanpa Administration; Super Admin melihat Administration; Department Inbox hanya untuk anggota/leader/pengelola; menu tersembunyi tetap 403 di backend; Global Create hanya aksi harian |
| `System/DashboardAttentionTest` | task blocked (dengan alasan) & overdue milik user, task orang lain tidak bocor; report menunggu informasi; leader melihat report tanpa PIC, member biasa tidak; jumlah query dashboard terbatas |
| `Project/ProjectWorkspaceTest` | ringkasan task dari flag status; Current Work maks 5, blocked dulu; Open Reports per type menghormati visibility ticket; 4 tab utama + alias tab lama + tab More; tombol + Task/+ Report dengan konteks project; jumlah query halaman project/inbox/my tasks konstan |
| `Task/TaskOperationalUxTest` | quick create dari project (context, status awal), validasi backend & project terkunci; quick progress lewat `UpdateTaskProgress` + audit; seksi My Tasks; aksi utama task menjalankan workflow; aksi ber-approval tetap butuh approval; detail teknis di More Details |
| `Ticket/ReportUxTest` | terminologi Report di halaman operasional; report dari project (project terisi) dan dari task (project + task terisi); task dari project lain tidak dapat disisipkan & project terkunci; task tak terlihat → 403; aksi utama report menjalankan workflow |

Test lama yang disesuaikan (perilaku domain sama, label/struktur UI berubah): `DashboardTest`, `Access/AuthorizationTest` (sidebar), `Task/TaskVisibilityTest`, `Ticket/TicketVisibilityTest`, `Project/ProjectVisibilityTest` (kartu dashboard), `Ticket/TicketCreationTest` (category tunggal kini terpilih otomatis; mengosongkannya tetap gagal validasi), `Ticket/TicketInboxTest` (urutan key counter), `System/SeederTest` (2 report demo baru).

Catatan: satu proses PHPUnit penuh membutuhkan memori > 128 MB (juga terjadi pada kode Phase 5 sebelum perubahan ini). Jalankan `php -d memory_limit=1G vendor/bin/phpunit` atau naikkan `memory_limit` CLI.

## 14. Architecture Decisions

| ADR | Keputusan | Alasan / konsekuensi |
|---|---|---|
| ADR-076 | **UX operasional berpusat pada Project → Task → Report → Progress** | Pertanyaan harian (apa yang berjalan, siapa PIC, apa kendalanya, berapa persen) terjawab di Dashboard, Project Overview, dan My Work tanpa membuka halaman konfigurasi. |
| ADR-077 | **Terminologi backend Ticket tidak diubah** (model, tabel, permission, event, route, kelas) | Tanpa migration/refactor besar; seluruh test Phase 4–5 tetap berlaku. |
| ADR-078 | **Ticket ditampilkan sebagai Report di UI operasional**; pesan Bahasa Indonesia memakai "Laporan" | Istilah natural untuk bug/maintenance/request/issue/note. Master data admin tetap "Ticket Type/Category". |
| ADR-079 | **Detail workflow disembunyikan dari Staff** — tombol aksi tetap dari workflow, tanpa nama versi/transition | Engine tetap sumber kebenaran; UI hanya menampilkan aksi yang boleh dijalankan. |
| ADR-080 | **Project Detail maksimal 4 tab utama**; Milestones, Team, Settings di More | Overview menjadi ringkasan eksekutif; tidak ada fitur yang hilang. |
| ADR-081 | **Metadata lanjutan memakai progressive disclosure** (More Details, More options, menu …) | Halaman bisa dipahami dalam beberapa detik tanpa tooltip; data teknis tetap tersedia untuk yang membutuhkan. |
| ADR-082 | **Phase 6 tidak menambah business engine**; "Needs Attention" adalah query biasa tanpa tabel | Kompleksitas backend tidak bertambah; fitur besar menunggu kebutuhan nyata dari pilot. |
| ADR-083 | **Navigasi menampilkan halaman direktori hanya untuk pengelolanya**; akses read-only tetap lewat route/policy | Sidebar Staff ringkas tanpa mengurangi hak akses yang ada. |

## 15. Known Limitations

- Prioritas "Open Issues" tidak mengutamakan tipe Bug karena tipe adalah master data tanpa flag semantik; urutan memakai level priority, status menunggu, dan update terakhir.
- Label aksi pada workflow published lama masih "Close Ticket"/"Reject Ticket"/"Cancel Ticket" sampai admin menerbitkan versi baru.
- Aksi utama Task memakai flag status dan urutan master (`sort_order`); workflow kustom dengan urutan status tidak lazim dapat menampilkan aksi utama yang kurang ideal (aksi lain tetap di menu …).
- Route tetap `/tickets`; alias `/reports` belum dibuat.
- Bottom bar mobile adalah navigasi web biasa (bukan PWA).

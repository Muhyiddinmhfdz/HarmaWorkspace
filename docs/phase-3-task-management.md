# HarmaWorkspace — Phase 3 Task, Subtask, Checklist & Project Progress

Phase 3 menambahkan unit kerja harian: Task (di dalam project atau standalone) dengan nomor otomatis, Main PIC + kolaborator beserta riwayatnya, jadwal planned/current/actual, estimasi & bobot, progress manual, status blocked dengan alasan, lifecycle terpusat, Subtask, Checklist, serta progress project otomatis berbobot task (`task_weighted`) dan progress per department.

Ticketing, Workflow Engine, Approval, notifikasi, PWA, dan API mobile **tidak** termasuk (Phase 4+).

---

## 1. Database

| Tabel | Fungsi | Kolom kunci | Constraint / index | Delete |
|---|---|---|---|---|
| `statuses` (+kolom) | Status scope `task` | `counts_as_started`, `is_blocked` (baru), plus `is_initial`, `is_terminal`, `counts_as_completed` | unique `(scope, code)` | deactivate |
| `priorities` | Priority scope `task` | `level` | unique `(scope, code)` | deactivate |
| `task_types` | Tipe task (Feature, Bug, ...) | master | `code` unique | deactivate |
| `task_categories` | Kategori task | master | `code` unique | deactivate |
| `tasks` | Task | `number`, `title`, `description`, `project_id`?, `milestone_id`?, `task_type_id`?, `task_category_id`?, `status_id`, `priority_id`, `department_id`?, planned/current/actual start & end, `estimated_hours` decimal(7,2), `weight` decimal(8,2)?, `progress_percentage`, `blocked_reason/at/by`, `completed_at`, `created_by`, `updated_by` | `number` unique; `(project_id, status_id)`; `(department_id, status_id)`; `(status_id, current_end)`; `title`; **CHECK** `progress 0–100`, `weight > 0`, `estimated_hours ≥ 0` | semua FK **RESTRICT**; task tidak pernah di-hard delete (dibatalkan = status Cancelled) |
| `task_assignments` | Main PIC & kolaborator (riwayat) | `task_id`, `user_id`, `role` (`pic`/`collaborator`), `assigned_at/by`, `ended_at/by` | generated column: unique **1 PIC aktif per task**, unique 1 assignment aktif per user per task; `(user_id, ended_at, role)` | append-only; dilepas = `ended_at` |
| `task_subtasks` | Subtask | `task_id`, `title`, `description`, `pic_user_id`?, `due_date`, `is_completed`, `completed_at/by`, `sort_order` | FK task | dihapus beserta checklist-nya (bukan entitas transaksi) |
| `checklist_items` | Checklist polymorphic (task / subtask) | `checklistable_type/id`, `text`, `is_completed`, `completed_at/by`, `sort_order` | morph index | dihapus per item |
| `schedule_changes` | Riwayat jadwal (sekarang juga untuk task) | — | — | append-only |

Morph map baru: `task`, `task_type`, `task_category`, `task_assignment`, `task_subtask`, `checklist_item`.

### Upgrade dari Phase 2 (tanpa `migrate:fresh`)

```bash
php artisan migrate --force
php artisan db:seed --class="Database\Seeders\System\SystemSeeder" --force   # master Task default, idempotent
```

Diverifikasi pada salinan database Phase 2 berisi data (3 project, 19 migration): 6 migration baru berjalan, project tetap utuh dengan `progress_mode = manual` dan nilai progress lama (45/70/10%), status & priority scope task terisi, permission `task.*` diberikan ke role default lewat data migration (Super Admin 9, Management 9, Staff 2) tanpa menimpa kustomisasi, dan `migrate` kedua menghasilkan *Nothing to migrate*.

## 2. Master Data (grup "Task" di `/master-data`)

| Master | Default (SystemSeeder, `firstOrCreate` — tidak menimpa perubahan admin) |
|---|---|
| Task Status | Backlog (initial), To Do, In Progress (started), Blocked (started + blocked), In Review (started), Revision (started), Done (terminal + completed), Cancelled (terminal) |
| Task Priority | Low 10, Normal 20, High 30, Critical 40 |
| Task Type / Task Category | dikelola admin (contoh hanya di DevelopmentSeeder) |

Logika tidak pernah memakai nama: *started* → `counts_as_started`, *blocked* → `is_blocked`, *selesai* → `is_terminal && counts_as_completed`, *batal* → `is_terminal && !counts_as_completed`, *awal* → `is_initial` (eksklusif).

## 3. Task di dalam Project atau Standalone

- `project_id` nullable (ADR-039). Task wajib punya **department atau Main PIC**.
- Task project: department harus aktif **dan terlibat** di project; milestone harus milik project yang sama; PIC/kolaborator harus anggota aktif project atau PM. Tidak ada penambahan anggota implisit — pesan: *"X belum menjadi anggota Project ini. Tambahkan sebagai Project Member terlebih dahulu."* (ADR-042).
- Project arsip atau berstatus terminal tidak menerima task baru, dan task-nya **read-only** (Action menolak dengan `BusinessRuleException`; UI menyembunyikan aksi — termasuk untuk Super Admin yang mem-bypass Policy).
- Nomor `TSK-YYYY-NNNN` dari `NumberGenerator` (row-locked).

## 4. Assignment

- Satu **Main PIC aktif** dijaga database (generated column + unique index) dan Action. Mengganti PIC menutup baris lama (`ended_at/by`) lalu menyisipkan baris baru (ADR-040). Jika PIC baru sebelumnya kolaborator, baris kolaborasinya ditutup.
- Banyak kolaborator; duplikat ditolak; melepas kolaborator = `ended_at`, riwayat tetap.
- Event: `task.pic_assigned`, `task.pic_changed` (old → new nama), `task.collaborator_added`, `task.collaborator_removed`.

## 5. Jadwal

- Saat dibuat: `planned_* = current_*` (baseline). `actual_start` terisi saat pertama kali masuk status `counts_as_started`; `actual_end` saat selesai.
- `ChangeTaskSchedule` mengubah `current_*` saja dan menulis `schedule_changes`. **Alasan wajib** setelah baseline ada; menetapkan deadline pertama kali menjadi baseline tanpa alasan.
- Overdue = `current_end < today` dan status tidak terminal.

## 6. Estimasi, Bobot & Progress Task

- `estimated_hours` (decimal) dan `weight` (override opsional).
- **Effective weight** (ADR-045) — satu definisi di `TaskWeight` (PHP `of()` + konstanta SQL): override → estimated hours (> 0) → 1.
- Progress task diisi manual 0–100 oleh PIC/manager (ADR-043). Subtask & checklist **tidak** menggerakkan progress di Phase 3. Done → 100%; reopen mempertahankan nilai terakhir.

## 7. Lifecycle (ADR-041)

`TaskLifecycle` memvalidasi transisi berdasar **code** status (sampai Workflow Engine Phase 5):

| Dari | Ke |
|---|---|
| Backlog | To Do, Cancelled |
| To Do | In Progress, Cancelled |
| In Progress | Blocked, In Review, Cancelled |
| Blocked | In Progress, Cancelled |
| In Review | Done, Revision |
| Revision | In Progress, In Review, Cancelled |
| Done | Revision (reopen) |
| Cancelled | To Do (reopen) |

- UI hanya menawarkan aksi valid (menu More); transisi lain ditolak dengan pesan *"Status Task tidak dapat diubah dari X ke Y."*
- Ability per transisi: ke Done / reopen dari terminal → `complete`; ke Cancelled → `cancel`; lainnya → `work`. PIC tidak dapat meng-approve (Done) pekerjaannya sendiri kecuali manager atau `task.complete`.
- **Blocked** (ADR-049): alasan wajib (modal), menyimpan `blocked_reason/at/by`; keluar dari Blocked membersihkannya. Ditampilkan sebagai callout merah di detail dan dihitung di ringkasan project & dashboard.
- Event: `task.status_changed`, `task.blocked`, `task.unblocked`, `task.completed`, `task.cancelled`, `task.reopened`.

## 8. Progress Project

`projects.progress_mode`: **Manual** (default, semua project lama) atau **Based on Tasks** (`task_weighted`) (ADR-044, ADR-046).

```
progress = Σ(effective_weight × progress_task) / Σ(effective_weight)
```

- Dihitung oleh database (`ProjectProgressCalculator`), dibulatkan & di-clamp 0–100.
- Task **Cancelled dikeluarkan** dari pembilang dan penyebut; tanpa task aktif → 0%.
- Contoh: A (w40, 50%), B (w30, 60%), C (w10, 100%), D (w20, 0%) → (2000+1800+1000+0)/100 = **48%** (diuji).
- `RecalculateProjectProgress` (row lock project) dipanggil setiap create task, perubahan progress/status/estimasi/bobot/department; hanya menyimpan bila mode `task_weighted`.
- Mode `task_weighted`: progress project **read-only** — `UpdateProjectProgress` menolak di backend, UI menampilkan "Calculated from N active tasks".
- Ganti mode lewat More → *Progress calculation* (konfirmasi SweetAlert). Ke `task_weighted` langsung menghitung ulang; kembali ke Manual mempertahankan nilai terakhir. Event `project.progress_mode_changed`.
- **Department progress**: formula yang sama per `tasks.department_id`, untuk setiap department yang terlibat (0% bila belum ada task).

### Penyelesaian Project (ADR-047)

`CompleteProject` ditolak bila masih ada task non-terminal, dengan ringkasan: *"Project belum dapat diselesaikan karena masih terdapat 2 Task aktif: 1 Task masih In Progress, 1 Task masih Blocked."* Diizinkan setelah semua task Done atau Cancelled.

## 9. Subtask & Checklist

- Subtask milik satu task (judul, deskripsi, PIC opsional, due date, selesai/buka). Checklist dapat dimiliki task atau subtask.
- Komponen selalu meng-scope query ke task pemilik: ID subtask/item dari task lain → 404.
- Mengelola butuh `participate` (assignee, manager, atau `task.update`); viewer hanya melihat. Toggle mencatat `completed_at/by`. Input disanitasi.
- Event: `task.subtask_created/completed/reopened/removed`, `task.checklist_updated`.

## 10. Permissions

| Permission | Fungsi | Management | Staff |
|---|---|:-:|:-:|
| `task.view` | Melihat task yang relevan | ✓ | ✓ |
| `task.view-all` | Melihat semua task | ✓ | |
| `task.create` | Membuat task | ✓ | ✓ |
| `task.update` | Mengubah info task, subtask/checklist | ✓ | |
| `task.assign` | Mengatur PIC & kolaborator | ✓ | |
| `task.manage-schedule` | Mengubah jadwal | ✓ | |
| `task.manage-progress` | Mengubah progress & status kerja | ✓ | |
| `task.complete` | Menyelesaikan / reopen task selesai | ✓ | |
| `task.cancel` | Membatalkan task | ✓ | |

`TaskPolicy` = visibility **dan** (permission global **atau** peran kontekstual) (ADR-050). **Manager kontekstual** task = leader department task, **atau** PM project (task project), **atau** pembuat (task standalone). Owner project tetap sponsor (view saja, ADR-038).

| Kemampuan | Main PIC | Kolaborator | Manager kontekstual | Permission global |
|---|:-:|:-:|:-:|---|
| View | ✓ | ✓ | ✓ | `task.view(-all)` |
| Update info / department / estimasi | | | ✓ | `task.update` |
| PIC & kolaborator | | | ✓ | `task.assign` |
| Jadwal | | | ✓ | `task.manage-schedule` |
| Progress & status kerja (start, block, review) | ✓ | | ✓ | `task.manage-progress` |
| Subtask & checklist | ✓ | ✓ | ✓ | `task.update` |
| Done / reopen | | | ✓ | `task.complete` |
| Cancel | | | ✓ | `task.cancel` |

Membuat task project: PM, leader department yang terlibat, atau pemegang `task.create` yang merupakan anggota project / punya `project.view-all`.

## 11. Visibility (ADR-048)

Satu sumber aturan: `Task::scopeVisibleTo($user)` + `isVisibleTo()` (shortcut tanpa query).

- User nonaktif → tidak ada; `task.view-all` → semua; tanpa `task.view` → tidak ada.
- **Task project** terlihat bila project-nya terlihat (`Project::scopeVisibleTo`) — visibility project tetap satu-satunya penentu.
- **Task standalone** terlihat oleh pembuat, assignee aktif (PIC/kolaborator), dan anggota aktif department task.
- Dipakai oleh semua list & quick view counts, dashboard, global search, dan Policy. Akses ID langsung yang tidak berhak → 403.

## 12. UI & Routes

| Route | Halaman |
|---|---|
| `/my-tasks` | Task saya: quick views Assigned, Collaborating, Due Today, Overdue, Blocked, Completed (dengan jumlah) |
| `/tasks` | Semua task yang visible: Open, Mine, Blocked, Overdue, Completed, All; search; filter (project, status, priority, department, PIC); tabel di desktop, kartu di mobile; quick update progress |
| `/tasks/create` | Form cepat (judul, project, department, PIC, status, priority, deadline) + *More options* (deskripsi, tipe, kategori, milestone, kolaborator, start date, estimasi, bobot). `?project=` mengunci project; opsi dependen difilter per project |
| `/tasks/{task}` | Header (nomor, judul, status/priority, progress), callout Blocked, kolom utama Description / Subtasks / Checklist / Activity, panel properti (PIC, kolaborator, department, project, milestone, jadwal, estimasi & bobot efektif, tipe/kategori); menu More: transisi lifecycle valid, update progress, change schedule |
| `/tasks/{task}/edit` | General; Department & Estimate (PIC, jadwal, progress, status diubah dari detail agar riwayat tercatat) |
| `/projects/{project}?tab=tasks` | Tab **Tasks** pada project (tabel yang sama, mode project) |

- Project Overview: kartu ringkasan Total / Done / In Progress / Blocked / Overdue (link ke tab Tasks dengan quick view), Upcoming Tasks, Department Progress, label sumber progress di header.
- Dashboard: My Open Tasks, Due Today, Overdue, Blocked, daftar My Tasks; untuk `task.view-all`: Overdue Tasks (all), Blocked Tasks (all).
- Global Search: grup *Tasks* (nomor & judul), hanya task visible. Global Create: *New Task*.
- Konfirmasi SweetAlert (`hwConfirm`) untuk Cancel, Done, ganti mode progress, hapus kolaborator/subtask.

## 13. Activity & Audit

Event task ditulis lewat `TaskAudit` (AuditLogger, subject = task, properti `task_number` + `project_id`): `task.created`, `task.updated`, `task.priority_changed`, `task.department_changed`, `task.weight_changed`, `task.schedule_changed`, `task.progress_changed`, status events (§7), assignment events (§4), subtask/checklist events (§9).

Timeline project ikut menampilkan event penting task (`task.created`, `task.completed`, `task.cancelled`, `task.blocked`, `task.schedule_changed`) melalui `properties->project_id`, tanpa tabel activity terpisah.

## 14. Tests

`php artisan test` → **279 test lulus, 846 assertion** (MySQL). PHPStan level 7: 0 error. Pint: bersih. Suite Phase 3 (`tests/Feature/Task`, 53 test):

| File | Cakupan |
|---|---|
| `TaskCreationTest` | standalone & project task, nomor berurutan, department/PIC wajib, department harus terlibat, milestone project lain ditolak, PIC bukan anggota ditolak (tanpa auto-member), status awal/terminal, priority scope lain, master nonaktif, baseline jadwal, project arsip menolak task (Policy, halaman 403, Action), mass assignment |
| `TaskVisibilityTest` | task project mengikuti visibility project, standalone (pembuat/assignee/department), user nonaktif, view-all, list & global search, IDOR |
| `TaskAssignmentTest` | 1 PIC aktif dijaga DB, penggantian PIC dengan riwayat & activity, banyak kolaborator tanpa duplikat, promosi kolaborator → PIC, assignee harus anggota project, user tanpa hak ditolak |
| `TaskLifecycleTest` | alur valid + actual_start/actual_end/100%, reopen, transisi invalid dengan pesan, manipulasi request & PIC tidak bisa Done, blocked wajib alasan (Action & UI) + resume, schedule baseline & riwayat & alasan wajib, deadline pertama, overdue berbasis flag |
| `ProjectProgressTest` | contoh 48%, Cancelled dikeluarkan, tanpa task = 0%, resolver bobot, perubahan estimasi/bobot menghitung ulang, task_weighted read-only (Action & UI), kembali ke Manual, project lama tetap manual, progress department, complete diblokir task aktif lalu diizinkan, task project arsip read-only |
| `SubtaskChecklistTest` | subtask oleh PIC tanpa mengubah progress, subtask task lain → 404, outsider & viewer ditolak, checklist sanitasi + metadata toggle, checklist subtask |

Juga diperbarui: `Ui/PageSmokeTest` (halaman task), `System/SeederTest` (6 task demo, POS `task_weighted`).

## 15. Architecture Decisions

| ADR | Keputusan | Alasan / konsekuensi |
|---|---|---|
| ADR-039 | **Task boleh tanpa Project** (`project_id` nullable) tetapi wajib department atau PIC | Pekerjaan rutin/operasional tidak dipaksa membuat project palsu; selalu ada penanggung jawab. |
| ADR-040 | **Assignment sebagai riwayat** (`task_assignments` + `ended_at`), satu PIC aktif via generated column unique | Siapa mengerjakan kapan tidak hilang; invarian dijaga database, bukan hanya kode. |
| ADR-041 | **Lifecycle task terpusat** (`TaskLifecycle`) berbasis code status sampai Workflow Engine | Konsisten dengan ADR-036; dipindah ke workflow versioned di Phase 5. |
| ADR-042 | **Tidak ada keanggotaan project implisit** dari assignment task | Visibility & tim project tetap keputusan PM yang sadar; mencegah kebocoran akses. |
| ADR-043 | **Progress task manual**; subtask/checklist tidak menggerakkan progress di Phase 3 | Sederhana & dapat diprediksi; otomasi dapat ditambahkan tanpa migrasi. |
| ADR-044 | **Progress project `task_weighted` = Σ(w·p)/Σw dihitung SQL**, Cancelled dikeluarkan, tanpa task = 0% | Akurat untuk banyak task, satu query; batal tidak menurunkan progress. |
| ADR-045 | **Effective weight: override → estimated hours → 1** dengan satu definisi (`TaskWeight`) | Estimasi jam menjadi bobot alami; override untuk kasus khusus; tidak ada formula ganda PHP/SQL. |
| ADR-046 | **Mode progress per project**, default Manual; project lama tidak berubah | Upgrade aman; PM memilih kapan beralih; kembali ke Manual mempertahankan nilai terakhir. |
| ADR-047 | **Project tidak dapat diselesaikan selama ada task aktif** | Status Completed bermakna; pesan ringkasan membantu PM menindaklanjuti. |
| ADR-048 | **Visibility task diturunkan dari project** (task project) atau assignee/department (standalone), satu scope terpusat | Tidak ada pengaturan visibility kedua yang dapat bertentangan dengan project. |
| ADR-049 | **Blocked = flag status `is_blocked` + alasan wajib** | Master tetap dinamis; alasan blokir terlihat dan teraudit. |
| ADR-050 | **Manager kontekstual task** = leader department task / PM project / pembuat (standalone); PIC hanya mengerjakan, bukan meng-approve | Konsisten dengan ADR-022/037/038; pemisahan pelaksana dan pemeriksa. |

## 16. Known Limitations

- Ticketing, Workflow, Approval, notifikasi, Gantt, PWA, dan API mobile belum dibuat.
- Tidak ada dependency antar task, time tracking (jam aktual), recurring task, maupun komentar/lampiran task.
- Progress task tidak dihitung otomatis dari subtask/checklist.
- Status task dapat ditambah admin, tetapi transisi lifecycle masih berbasis code default (§7) sampai Workflow Engine; status baru tanpa transisi tidak dapat dicapai lewat UI.
- Health project masih manual.
- Activity seeder development tercatat sebagai "System" (tidak ada user login saat seeding).

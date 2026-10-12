# HarmaWorkspace — Phase 2 Project Multi-Division Management

Phase 2 membangun fondasi Project Management: project multi-divisi dengan nomor otomatis, owner dan project manager kontekstual, lead/supporting department, anggota dengan project role, jadwal planned/current/actual beserta riwayatnya, milestone, progress, health, visibility, dan activity.

Task, Ticket, Workflow, Approval, Gantt, notifikasi, dan PWA **tidak** termasuk.

---

## 1. Database

| Tabel | Fungsi | Kolom kunci | Constraint / index | Delete |
|---|---|---|---|---|
| `statuses` | Status bersama (scope project/task/ticket) | `scope`, `code`, `name`, `color`, `is_initial`, `is_terminal`, `counts_as_completed`, `is_active` | unique `(scope, code)`; `(scope, is_active, sort_order)` | tidak dihapus (deactivate) |
| `priorities` | Priority bersama | `scope`, `code`, `level`, `color` | unique `(scope, code)` | deactivate |
| `project_categories` | Kategori project | master | `code` unique | deactivate |
| `project_roles` | Peran anggota di project (kontekstual) | master | `code` unique | deactivate |
| `project_health_statuses` | Indikator health | `level`, `color`, `is_default`, `represents_completion` | `code` unique | deactivate |
| `number_sequences` | Counter nomor dokumen | `scope`, `period`, `last_number` | unique `(scope, period)` | — |
| `projects` | Project | `number`, `name`, `client_id`?, `project_category_id`, `status_id`, `priority_id`, `health_status_id`, `owner_id`?, `project_manager_id`, `visibility` (`restricted` default / `department` / `company`), planned/current/actual start & end, `progress_mode`, `progress_percentage`, `completed_at`, `archived_at`, `created_by`, `updated_by` | `number` unique; `name`; `(archived_at, current_end)`; `(project_manager_id, archived_at)`; `(owner_id, archived_at)`; FK index untuk client/status/priority/health | semua FK **RESTRICT**; project tidak pernah di-hard delete |
| `project_departments` | Department terlibat | `project_id`, `department_id`, `is_lead`, `function`, `lead_user_id`, `removed_at` | generated column: unique 1 department aktif per project, unique 1 **lead** aktif per project | RESTRICT; dihapus = `removed_at` |
| `project_members` | Anggota eksplisit | `project_id`, `user_id`, `project_role_id`, `department_id`?, `joined_at`, `ended_at` | generated column: unique 1 keanggotaan aktif per user per project; `(user_id, ended_at)` | RESTRICT; keluar = `ended_at` |
| `project_milestones` | Milestone | `name`, `owner_id`, `is_key_milestone`, `planned_date`, `current_due_date`, `actual_date`, `sort_order` | `(project_id, actual_date, current_due_date)` | RESTRICT |
| `schedule_changes` | Riwayat jadwal (polymorphic: project, milestone, nanti task) | `schedulable_type/id`, `old_start`, `old_end`, `new_start`, `new_end`, `reason`, `changed_by` | `(schedulable_type, schedulable_id)` | append-only |

Catatan desain:

- **Lead department hanya satu sumber kebenaran**: `project_departments.is_lead`. Tidak ada kolom `projects.lead_department_id` (ADR-023).
- `is_active` pada membership tidak dibuat terpisah: keanggotaan aktif = `ended_at IS NULL` (tidak ada dua sumber kebenaran).
- Data migration `grant_phase_2_permissions_to_default_roles` memberi permission Project ke role Management/Staff yang sudah ada, tanpa menimpa kustomisasi admin.
- Migration `normalize_project_visibility_values` mengubah nilai sementara (`members`→`restricted`, `internal`→`company`, nilai lain→`restricted`) dan menetapkan default `restricted`.

### Upgrade dari Phase 1 (tanpa `migrate:fresh`)

```bash
php artisan migrate --force
php artisan db:seed --class="Database\Seeders\System\SystemSeeder" --force   # master Project default, idempotent
```

Diverifikasi pada database hasil commit Phase 1 yang berisi data (6 user, 12 client, 9 department): semua data tetap utuh, permission Project ditambahkan ke role default tanpa mengembalikan permission yang sebelumnya dicabut admin, dan project baru langsung dapat dibuat dengan visibility `restricted`. `migrate:fresh` hanya untuk development lokal dan test.

## 2. Master Data

Dikelola lewat halaman generik `/master-data/{type}` (satu pola tabel seperti Phase 1):

| Type | Master | Field khusus |
|---|---|---|
| `project-categories` | Project Categories | — |
| `project-statuses` | Project Statuses (`statuses`, scope project) | color, initial*, terminal, counts as completed |
| `project-priorities` | Project Priorities (`priorities`, scope project) | level, color |
| `project-roles` | Project Roles | — |
| `project-health` | Project Health | level, color, default*, used when completed* |

\* Flag eksklusif: hanya satu record yang boleh memegangnya; menyetel record baru otomatis melepas yang lama.

Seeder sistem (`ProjectMasterSeeder`, hanya membuat code yang belum ada):

- **Status**: DRAFT (initial), PLANNING, ACTIVE, ON_HOLD, COMPLETED (terminal + completed), CANCELLED (terminal).
- **Priority**: LOW 10, NORMAL 20, HIGH 30, CRITICAL 40.
- **Health**: ON_TRACK (default, level 10), AT_RISK (20), CRITICAL (30), COMPLETED (dipakai saat selesai).
- **Project role**: DEPT_LEAD, DEVELOPER, REVIEWER, MEMBER, OBSERVER.

Category contoh (Development, Implementation, Maintenance, Migration, Internal System, Infrastructure, Research) hanya ada di **Development seeder**. Di production, admin membuat category sendiri.

Validasi scope terpusat di `ScopedMasterGuard`: project hanya dapat memakai status/priority `scope = project` yang aktif. Nilai lama yang sudah dinonaktifkan tetap boleh dipertahankan saat edit.

## 3. Project Lifecycle

Status tetap berasal dari master (nama, warna, flag), tetapi **transisi** divalidasi terpusat oleh `App\Domain\Project\Support\ProjectLifecycle` sampai Workflow Engine tersedia (ADR-036):

| Dari | Boleh ke |
|---|---|
| Draft | Planning, Cancelled |
| Planning | Active, On Hold, Cancelled |
| Active | On Hold, Completed (via Complete), Cancelled |
| On Hold | Active, Cancelled |
| Completed | — (hanya Archive) |
| Cancelled | — (hanya Archive) |

- Aturan memakai **code** status (stabil dan terkunci setelah dipakai), bukan nama tampilan. Status buatan admin tanpa aturan eksplisit memakai fallback konservatif berbasis flag: status terminal tidak bisa berpindah, status lain hanya ke status non-initial.
- Transisi tidak valid ditolak dengan pesan ramah, misalnya "Status Project tidak dapat diubah dari Completed ke Planning." (toast). Aturan berlaku juga bila request dimanipulasi (validasi di Action).
- UI tidak lagi memakai dropdown semua status. Menu **More** menampilkan *available actions* sesuai status saat ini: Move to planning, Start project, Put on hold, Resume project, Cancel project (dengan konfirmasi), Complete project.

| Aksi | Action | Aturan |
|---|---|---|
| Buat | `CreateProject` | nomor otomatis, status = initial, health = default, visibility = restricted (default), planned = current, actual kosong |
| Ubah status | `ChangeProjectStatus` | scope project & aktif, harus diizinkan `ProjectLifecycle`; status *counts_as_completed* ditolak (pakai Complete); pindah pertama ke status non-initial mengisi `actual_start`; status terminal butuh izin *complete* |
| Selesaikan | `CompleteProject` | hanya dari status yang mengizinkan completion (Active); status completed, `actual_end`, `completed_at`, progress manual 100%, health completion; titik ekstensi validasi Task di Phase 3 |
| Arsip / pulihkan | `ArchiveProject` | hanya status terminal; project arsip **read-only**, hilang dari list default, tetap bisa dicari |

Tidak ada hard delete melalui UI (ADR-028).

## 4. Numbering

`NumberGenerator::next('project', SettingKey::ProjectNumberPrefix)` membuat `PRJ-2026-0001`:

- prefix, jumlah digit, dan reset tahunan diambil dari System Settings;
- counter dikunci dengan `SELECT … FOR UPDATE` di tabel `number_sequences` dan dipanggil di dalam transaksi pembuatan project, sehingga aman untuk create bersamaan;
- `last_number` tidak pernah turun, sehingga nomor tidak dipakai ulang (rollback hanya menyisakan celah);
- `projects.number` unique sebagai pengaman terakhir.

Generator yang sama siap dipakai Task (`TSK`) dan Ticket (`TCK`).

## 5. Multi-Division

- Setiap project punya **tepat satu Lead Department** (unique index pada generated column) dan nol atau lebih **Supporting Department**.
- `ManageProjectDepartments`:
  - `add`: menambah supporting department beserta *project function* dan department lead (PIC);
  - `update`: mengubah function dan lead user;
  - `changeLead`: lead lama otomatis menjadi supporting, jadi project tidak pernah tanpa lead (row lock);
  - `remove`: menolak lead department; selain itu mengisi `removed_at`.
- Department nonaktif tidak dapat ditambahkan.

## 6. Membership & Project Roles

- Anggota ditambahkan **eksplisit**; menjadi anggota department tidak otomatis menjadi anggota project.
- Setiap anggota punya **Project Role** (master), dan opsional konteks department.
- `ManageProjectMembers`: `add` (user aktif, tidak dobel), `changeRole`, `remove` (`ended_at`). Rejoin membuat baris baru, sehingga riwayat lengkap.
- **Owner** dan **Project Manager** adalah kolom pada `projects` (kontekstual, bukan role Spatie). Perubahannya lewat `ChangeProjectOwner` / `ChangeProjectManager` dan tercatat old → new.
- **Project Manager** mendapat hak kelola harian secara kontekstual. **Owner** adalah sponsor bisnis: selalu dapat melihat project dan activity-nya, tetapi tidak otomatis mengelola tanpa permission global (ADR-038).

Contoh yang didukung: Andi bersystem role **Staff**, menjadi **Project Manager** di POS, dan **Member** di HRIS.

## 7. Schedule

| Lapis | Kolom | Diubah oleh |
|---|---|---|
| Planned (baseline) | `planned_start`, `planned_end` | hanya saat dibuat |
| Current | `current_start`, `current_end` | hanya `ChangeProjectSchedule` (alasan wajib) |
| Actual | `actual_start`, `actual_end` | otomatis oleh `ChangeProjectStatus` / `CompleteProject` |

Setiap perubahan current schedule menulis `schedule_changes` (old/new start & end, alasan, pengubah, waktu) dan activity. Overview menampilkan planned vs current beserta selisih harinya ("Mundur 5 hari dari rencana"). Kolom jadwal tidak mass-assignable.

Baseline versioning (`project_baselines`) belum dibuat. Baseline Phase 2 = planned dates + riwayat perubahan (ADR-024).

## 8. Milestones

- `ManageProjectMilestones`: `create`, `update`, `complete` (tanggal tercapai, tidak boleh di masa depan), `reopen`.
- `planned_date` dikunci saat dibuat. Perubahan `current_due_date` wajib disertai alasan dan tercatat di `schedule_changes`.
- State diturunkan dari tanggal: **completed** (ada `actual_date`), **overdue** (lewat target), **upcoming**. Tidak ada master status milestone (ADR-027).
- Label M1/M2 tidak dipakai sebagai identitas; urutan memakai `sort_order` dan tanggal target.
- UI berbentuk timeline: ✓ tercapai, ● milestone berikutnya, ○ mendatang, serta penanda merah untuk terlambat. Filter All / Upcoming / Completed.

## 9. Progress

- `progress_mode` = `manual` (Phase 2) | `task_weighted` (disiapkan untuk Phase 3).
- `UpdateProjectProgress` (0–100, row lock, menolak mode non-manual) mencatat activity "45% → 60%".
- Aturan Phase 3: jika `progress_mode = task_weighted`, input progress manual menjadi read-only (menu Update progress disembunyikan dan action menolak). Progress hanya punya satu sumber kebenaran.
- Ditampilkan sebagai progress bar **beserta angka persen**.

## 10. Health

- Master `project_health_statuses`; `level` mengurutkan keparahan.
- Phase 2: health diatur manual oleh PM lewat `UpdateProjectHealth` (catatan opsional, tercatat di activity).
- Project baru memakai health `is_default`; project selesai memakai health `represents_completion`.
- Badge selalu menampilkan teks, tidak hanya warna. Quick tab health di daftar project dibangkitkan dari master (bukan nama hardcode) dan disembunyikan jika kosong.
- Siap untuk perhitungan otomatis di phase berikutnya (deadline variance, milestone delay, task overdue) tanpa perubahan skema.

## 11. Permissions

| Permission | Fungsi | Management | Staff |
|---|---|:-:|:-:|
| `project.view` | Melihat project yang relevan | ✓ | ✓ |
| `project.view-all` | Melihat semua project | ✓ | |
| `project.create` | Membuat project | ✓ | |
| `project.update` | Mengubah info, owner, PM, status | ✓ | |
| `project.manage-departments` | Lead & supporting department | ✓ | |
| `project.manage-members` | Anggota & project role | ✓ | |
| `project.manage-schedule` | Jadwal | ✓ | |
| `project.manage-milestones` | Milestone | ✓ | |
| `project.manage-progress` | Progress & health | ✓ | |
| `project.complete` | Menyelesaikan/membatalkan | ✓ | |
| `project.archive` | Arsip/pulihkan | ✓ | |
| `project.manage-visibility` | Mengatur visibility **Company** | ✓ | |
| `master-data.manage` | Mengelola master Project | (Super Admin) | |

`ProjectPolicy` = visibility **dan** (permission global **atau** peran kontekstual Project Manager).

| Kemampuan | Project Manager (kontekstual) | Owner (kontekstual) | Butuh permission global |
|---|:-:|:-:|---|
| View, activity | ✓ | ✓ | — |
| Update info, members, departments, milestones, schedule, progress, health | ✓ | — | `project.update` / `project.manage-*` |
| Visibility Restricted ↔ Department | ✓ | — | `project.update` |
| Visibility **Company** | — | — | `project.manage-visibility` |
| Complete / cancel | ✓ | — | `project.complete` |
| **Archive / restore** | — | — | `project.archive` |
| Master data | — | — | `master-data.manage` |

Hak kontekstual tidak pernah menjadi jalan pintas untuk aksi administratif (ADR-037).

## 12. Visibility

**Visibility terpisah dari client.** Project tanpa client adalah *Internal Project* (tipe), bukan project yang terbuka untuk semua orang (ADR-034). Visibility disimpan sebagai enum teknis `projects.visibility`, bukan master dinamis, karena merupakan aturan keamanan inti.

| Mode | Dapat dilihat oleh |
|---|---|
| **Restricted** (default, ADR-035) | Owner, Project Manager, anggota aktif, department lead (PIC) project, leader department yang terlibat |
| **Department** | Restricted + seluruh anggota aktif department yang terlibat (lead & supporting, keterlibatan belum dihapus) |
| **Company** | Seluruh user aktif dengan `project.view` |

Selalu berlaku: user nonaktif tidak melihat apa pun, `project.view-all` (Management/Super Admin) melihat semua, dan tanpa `project.view` tidak ada project yang terlihat. Visibility **hanya mengatur VIEW**, tidak memberi hak update, manage, atau archive.

- Satu sumber aturan: `Project::scopeVisibleTo($user)`. Dipakai oleh list, quick tab counts, filter options, dashboard, global search, dan `isVisibleTo()` di Policy. `isVisibleTo()` punya shortcut tanpa query (view-all, PM, owner, company) untuk mencegah N+1.
- Mengubah visibility hanya lewat `ChangeProjectVisibility` (menu More → Change visibility) dan tercatat old → new. Memilih **Company** butuh `project.manage-visibility`, baik saat create maupun saat edit; dicek di Policy dan di Action (`VisibilityGuard`).
- Wizard Create menampilkan pilihan visibility di Step 1 dengan default Restricted. Opsi Company dinonaktifkan untuk user tanpa izin.
- Global search tidak pernah menampilkan project yang tidak boleh dilihat. Akses lewat URL ID langsung (IDOR) menghasilkan 403.

## 13. UI & Routes

| Route | Halaman |
|---|---|
| `/projects` | Daftar project: quick tabs (Active, My Projects, health dinamis, Completed, Archived, All) dengan jumlah; search (nomor, nama, client, PM); More filters (status, priority, deadline, client, PM, department); sort (name, progress, deadline); tabel di desktop, kartu di mobile; baris dapat diklik; aksi di menu `…` |
| `/projects/create` | Wizard 4 langkah: Basic Information → Ownership & Team → Schedule → Review |
| `/projects/{project}` | Header (nomor, nama, status/priority/health, progress), ringkasan (Client, PM, Lead Dept, Start, Deadline + indikator), metadata visibility, tabs **Overview / Milestones / Team / Activity**; menu More: aksi lifecycle yang valid, change schedule (modal + alasan), update progress, update health, change visibility, complete, archive |
| `/projects/{project}/edit` | Form bersection: General, Ownership; department & jadwal diarahkan ke tab Team / aksi schedule |
| `/master-data/{type}` | Master Project generik |

**Konfirmasi**: aksi sensitif memakai dialog SweetAlert2 (`window.hwConfirm`, satu helper bersama toast Phase 1). Tidak ada lagi `confirm()` bawaan browser: Archive/Restore project (detail & list), Cancel project, Make lead department, Remove department ("Hapus Department dari Project?"), Remove member ("Keluarkan anggota dari Project?"). Konfirmasi hanya UX; setiap action tetap diotorisasi di backend.

Komponen baru: `x-ui.master-badge`, `x-ui.progress-bar`, `x-ui.deadline` ("12 hari lagi", "Jatuh tempo hari ini", "Terlambat 3 hari"), dan `livewire:user-picker` (pencarian user aktif, tidak memakai dropdown panjang).

**Dashboard**: kartu Active Projects + health bermasalah (dari master), My Projects, Upcoming Project Deadlines (30 hari, termasuk terlambat), Upcoming Milestones untuk project yang dikelola, Recent Activity (audit.view), My Organization.

**Global Search**: grup *Projects* (nomor & nama), hanya project yang visible.

**Global Create**: *New Project* untuk pemegang `project.create`.

## 14. Activity & Audit

Semua event project ditulis lewat `AuditLogger` dengan **subject = project**, sehingga tab Activity menampilkan timeline per tanggal (load more 20) dan Audit Log admin tetap menampilkan detail teknis old/new:

`project.created`, `project.updated`, `project.visibility_changed`, `project.owner_changed`, `project.manager_changed`, `project.status_changed`, `project.completed`, `project.archived`, `project.restored`, `project.schedule_changed`, `project.progress_changed`, `project.health_changed`, `project.department_added`, `project.department_updated`, `project.department_removed`, `project.lead_department_changed`, `project.member_added`, `project.member_role_changed`, `project.member_removed`, `project.milestone_created`, `project.milestone_updated`, `project.milestone_completed`, `project.milestone_reopened`, serta `{master}.created/updated/activated/deactivated` untuk master Project.

## 15. Tests

`php artisan test` → **218 test lulus, 660 assertion** (MySQL). Suite Phase 2:

| File | Cakupan |
|---|---|
| `Project/ProjectCreationTest` | wizard end-to-end, validasi tiap step, internal project, nomor unik & berurutan, generator mengikuti setting, PM aktif wajib, lead ≠ supporting, priority scope lain ditolak, master nonaktif ditolak, department nonaktif ditolak, mass assignment diblokir |
| `Project/ProjectVisibilityTest` | default restricted (juga tanpa client); restricted: PM/owner/member/leader terlihat, staff department & outsider tidak; department: anggota department terlibat (lead & supporting) terlihat, department lain & keanggotaan berakhir tidak; company: user eligible terlihat, tanpa baseline permission tidak; user nonaktif; Management/Super Admin; mantan anggota; IDOR; list; global search (restricted/department/company); counter dashboard; perubahan visibility tercatat; PM tidak bisa membuka ke Company (Policy & Action); member tidak bisa mengubah visibility; Company saat create butuh permission; hak kontekstual tanpa aksi administratif; owner tidak otomatis mengelola |
| `Project/ProjectLifecycleTransitionTest` | Draft→Planning→Active→On Hold→Active→Completed→Archived; Draft→Cancelled→Archived; Completed→Planning ditolak dengan pesan ramah; Draft→Active dan complete dari Draft ditolak; manipulasi request dari UI ditolak; status scope lain ditolak; UI hanya menawarkan aksi valid; project arsip read-only; PM tidak bisa archive |
| `Project/ProjectTeamTest` | supporting add/remove dengan riwayat, department dobel ditolak, satu lead dijaga database, ganti lead tanpa kehilangan lead, multi-member dengan project role, role berbeda per project, keanggotaan unik + riwayat, PM mengelola tim dari UI, user nonaktif ditolak |
| `Project/ProjectLifecycleTest` | schedule change + riwayat + alasan wajib, PM change activity, progress, health, status & actual start, scope ticket ditolak, complete, archive read-only, arsip tersembunyi tapi searchable, tanpa izin ditolak |
| `Project/MilestoneTest` | create, validasi, tanpa akses ditolak, perubahan target wajib alasan & tercatat, complete/reopen, milestone project lain tidak bisa disasar |
| `Project/ProjectMasterDataTest` | CRUD master generik, code unik per scope, hanya data scope project, flag eksklusif, code terkunci + deactivate, view-only ditolak |
| `System/SeederTest` | seeder development berjalan lewat domain action & idempotent |
| `Ui/PageSmokeTest` | semua halaman Phase 1 + 12 halaman/tab Phase 2 |

## 16. Architecture Decisions

| ADR | Keputusan | Alasan / konsekuensi |
|---|---|---|
| ADR-022 | **Project Manager & Owner kontekstual** (`projects.project_manager_id`, `owner_id`), bukan role Spatie | User yang sama dapat menjadi PM di satu project dan Member di project lain; hak kelola diberikan Policy. |
| ADR-023 | **`project_departments` adalah sumber kebenaran lead department** (`is_lead` + unique index), tanpa `lead_department_id` | Menghindari dua sumber data; pergantian lead atomik dengan row lock. |
| ADR-024 | **Jadwal planned/current/actual**; baseline = planned + `schedule_changes` | Rencana awal tidak pernah tertimpa; versioning baseline ditunda sampai Gantt (Phase 7). |
| ADR-025 | **Progress manual** di Phase 2 dengan `progress_mode` | Phase 3 cukup menambah perhitungan `task_weighted` tanpa migrasi data. |
| ADR-026 | **Health manual oleh PM**, master `project_health_statuses` dengan flag default & completion | Tidak ada nama hardcode; algoritma otomatis dapat ditambahkan kemudian. |
| ADR-027 | **State milestone diturunkan dari tanggal**, tanpa master status | Cukup untuk target penting; menghindari master yang belum dibutuhkan. |
| ADR-028 | **Archive = `archived_at`** (bukan status) dan tidak ada hard delete | Status tetap menyatakan hasil (Completed/Cancelled); arsip read-only & tetap searchable. |
| ADR-029 | **`schedule_changes` polymorphic** untuk project & milestone (dan Task nanti) | Satu riwayat jadwal untuk semua entitas berjadwal (blueprint §7.6). |
| ADR-030 | **Master Project generik** lewat `MasterRegistry` + satu halaman | Konsistensi UI dan validasi; menambah master baru cukup satu definisi. |
| ADR-031 | **Activity project memakai audit log** dengan subject = project | Satu penyimpanan, dua tampilan (timeline ramah & audit teknis); tidak ada duplikasi tabel activity. |
| ADR-032 | **Permission baru diberikan ke role default lewat data migration** | Role yang sudah dikustomisasi admin tidak ditimpa oleh seeder. |
| ADR-033 | *(Digantikan ADR-034/035)* Visibility `members` / `internal` | Nilai sementara selama pengembangan Phase 2; dimigrasikan otomatis ke model baru. |
| ADR-034 | **Visibility project independen dari client/tipe Internal Project**: enum teknis `restricted` / `department` / `company` | Project tanpa client tidak otomatis publik; visibility adalah aturan keamanan inti sehingga tidak dijadikan master dinamis. Visibility hanya mengatur VIEW. |
| ADR-035 | **Restricted adalah default visibility** | Akses lebih luas harus dipilih secara sadar; migrasi data lama memakai default aman. |
| ADR-036 | **Transisi lifecycle project divalidasi terpusat** (`ProjectLifecycle`) sampai Workflow Engine tersedia | Status tetap master-driven untuk tampilan; transisi berbasis code/flag, bukan nama. Completion & archive tetap action khusus. Saat Phase 5 Workflow hadir, aturan ini dipindahkan ke workflow versioned. |
| ADR-037 | **Hak kontekstual ≠ hak administratif**: archive, visibility Company, dan master data selalu butuh permission global (`project.archive`, `project.manage-visibility` baru, `master-data.manage`) | Mencegah PM membuka project ke seluruh perusahaan atau mengarsipkan tanpa kontrol. |
| ADR-038 | **Owner adalah sponsor**: selalu melihat project, tetapi hak kelola kontekstual hanya untuk Project Manager | Sesuai blueprint (owner menyetujui perubahan besar, bukan operasional harian); owner yang perlu mengelola diberi permission global atau dijadikan PM. |

## 17. Known Limitations

- Task, Ticket, Workflow, Approval, Gantt, notifikasi, PWA, dan API mobile belum dibuat.
- Progress `task_weighted` dan health otomatis belum diimplementasikan (struktur sudah siap).
- Jadwal per department (planned/current per divisi) dan progress per department belum dibuat.
- Baseline versioning dan project tag belum ada.
- Label tanggal memakai locale aplikasi (Indonesia).

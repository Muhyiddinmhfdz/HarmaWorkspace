# HarmaWorkspace — Phase 1 Foundation

Dokumen ini menjelaskan fondasi yang sudah dibangun pada Phase 1: environment, instalasi, struktur kode, database, akses, dan keputusan arsitektur (ADR). Rancangan jangka panjang ada di [phase-0-blueprint.md](phase-0-blueprint.md).

---

## 1. Environment

| Komponen | Versi / catatan |
|---|---|
| PHP | 8.4 (`composer.json`: `"php": "^8.4"`) — jangan memakai PHP XAMPP pada Apple Silicon |
| Laravel | 13.x |
| Livewire | 4.x (single-file components) |
| Flux UI | 2.x edisi free |
| Fortify | 1.x (session authentication) |
| spatie/laravel-permission | 8.x |
| spatie/laravel-activitylog | 5.x |
| MySQL | 8.x / 9.x, InnoDB, `utf8mb4_unicode_ci` |
| Node | ≥ 22 (Vite 8 + Tailwind CSS 4) |
| Dev tooling | Laravel Boost 2.x, Pint, Larastan (level 7) |

## 2. Instalasi Lokal

```bash
composer install
cp .env.example .env
php artisan key:generate

# Buat database (contoh dengan user khusus, bukan root)
#   CREATE DATABASE harmaworkspace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
#   CREATE DATABASE harmaworkspace_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
# Isi DB_USERNAME / DB_PASSWORD di .env

# Super Admin awal: default username "superadmin", password "123123123"
#   (override lewat HARMA_SUPER_ADMIN_USERNAME / _PASSWORD; WAJIB diganti di production)

php artisan migrate --seed
npm install && npm run build
composer run dev          # atau: php artisan serve
php artisan boost:install # guideline AI (CLAUDE.md) + MCP, dibangkitkan dari .ai/guidelines
```

Super Admin tambahan dapat dibuat secara interaktif:

```bash
php artisan harma:create-super-admin
```

## 3. Seeder

| Seeder | Isi | Kapan berjalan |
|---|---|---|
| `System\SystemSeeder` | Permission (dari `PermissionRegistry`), role sistem, Super Admin (`superadmin` / `123123123` atau nilai env) | Selalu; idempotent, aman di production: `php artisan db:seed --class="Database\Seeders\System\SystemSeeder" --force` |
| `Development\DevelopmentSeeder` | Department, position, user demo `rina`, `andi`, `budi`, `sari`, `dewi` (password `password`), client dummy | Hanya `APP_ENV=local` (dipanggil `DatabaseSeeder`) |

`SyncPermissionRegistry` menambah permission baru dan menghapus yang sudah tidak ada di registry. Role Super Admin selalu memegang semua permission. Role lain hanya menerima permission default saat pertama kali dibuat; perubahan admin tidak ditimpa.

## 4. Struktur Proyek

```text
app/
├── Domain/                         # business logic, dipakai web sekarang dan API nanti
│   ├── Access/                     # PermissionRegistry, CreateRole/UpdateRole/DeleteRole, RoleGuard, SyncPermissionRegistry, Rules
│   ├── Audit/AuditLogger.php       # satu pintu audit log (Spatie Activitylog, log "audit")
│   ├── Client/                     # CreateClient/UpdateClient, ClientRules
│   ├── MasterData/                 # ChangeMasterStatus (activate/deactivate), MasterRules (code, sort order)
│   ├── Organization/               # CreateUser, UpdateUser, AssignUserRole, SyncUserMemberships, ChangeUserStatus,
│   │                               # SendPasswordResetLink, UpdateOwnProfile, ChangeOwnPassword, Department/Position actions, Rules
│   ├── Shared/Exceptions/BusinessRuleException.php
│   └── System/                     # Settings (SettingKey enum + cache), UpdateSettings, Navigation, GlobalSearch
├── Http/Middleware/EnsureUserIsActive.php
├── Listeners/RecordLastLogin.php
├── Livewire/Concerns/              # WithDataTable, HandlesBusinessRules
├── Models/                         # User, Department, DepartmentMembership, Position, Client, Setting, Activity
│   └── Concerns/                   # HasActiveStatus, HasMasterCode, HasVisibilityScope
├── Policies/                       # User, Department, Position, Client, Role
└── Console/Commands/CreateSuperAdmin.php

config/harma.php                    # nama role Super Admin, kredensial awal dari env
config/navigation.php               # sidebar berbasis permission
resources/views/
├── pages/                          # halaman Livewire (SFC): dashboard, admin/*, clients/*, master-data, settings, profile/*, auth/*
├── components/ui/                  # page-header, empty-state, active-badge, form-section, detail-item, table-card
├── components/global-search.blade.php
└── errors/                         # 403, 404, 419, 429, 500, 503
lang/id/, lang/id.json              # pesan & validasi Bahasa Indonesia
.ai/guidelines/harmaworkspace.md    # aturan proyek untuk AI agent (Laravel Boost)
```

### Alur permintaan

```text
Livewire page (resources/views/pages/**)
  ├─ authorize()  → Policy / permission
  ├─ validate()   → Domain/*/Rules (satu sumber aturan)
  └─ Action::handle()  → DB::transaction, aturan bisnis, AuditLogger
```

Pelanggaran aturan bisnis melempar `BusinessRuleException` (pesan Indonesia). Request JSON menerima status 422.

### Tabel, CRUD, sanitizer, dan alert

- **Tabel server-side**: setiap list memakai `WithDataTable`. Search (debounce 300 ms), filter, sort (whitelist kolom), dan pagination dijalankan sebagai query MySQL; browser hanya menerima satu halaman data. State tersimpan di query string.
- **CRUD AJAX**: semua create/edit/activate/deactivate berjalan lewat request Livewire tanpa reload halaman, dengan loading state pada tombol.
- **Sanitizer**: `WithFeedback::validate()` membersihkan setiap input sebelum validasi (`InputSanitizer`: trim, buang tag HTML dan karakter kontrol). Password tidak diubah.
- **Try-catch terpusat**: hook `exception()` di `WithFeedback` menangkap semua error di setiap aksi:
  - validasi → toast "Periksa kembali isian" + pesan tetap tampil di bawah field;
  - `BusinessRuleException` → toast berisi pesan aturan;
  - error tak terduga → dicatat ke log (`report()`) dan toast pesan ramah;
  - 403/404/419/429/500 dan gangguan jaringan → ditangkap interceptor Livewire di `resources/js/app.js` dan ditampilkan sebagai toast (tanpa modal error teknis).
- **Alert**: SweetAlert2 toast (pojok kanan atas, progress bar, berhenti saat di-hover). Sukses memakai `notify('success', ...)`; setelah redirect memakai flash (`notifyAfterRedirect`). Halaman login/reset password juga menampilkan error dan status sebagai toast.

## 5. Database

| Migration | Tabel | Catatan |
|---|---|---|
| `0001_01_01_000000_create_users_table` | `users`, `password_reset_tokens`, `sessions` | + `username` (unique, login), `email` nullable unique, `phone`, `is_active`, `last_login_at`, `deactivated_at` |
| `0001_01_01_000001/2` | `cache`, `jobs` | bawaan Laravel |
| `2025_08_14_170933_add_two_factor_columns_to_users_table` | `users` | 2FA Fortify |
| `2026_10_12_103430_create_activity_log_table` | `activity_log` | + `ip_address`, `user_agent`, index `event` dan `created_at` |
| `2026_10_12_103430_create_permission_tables` | tabel Spatie | |
| `2026_10_12_110000_create_positions_table` | `positions` | `code` unique, `level`, `sort_order`, `is_active` |
| `2026_10_12_110100_create_departments_table` | `departments` | `code` unique, `parent_id` self-FK |
| `2026_10_12_110200_create_department_memberships_table` | `department_memberships` | lihat di bawah |
| `2026_10_12_110300_create_clients_table` | `clients` | `code` unique |
| `2026_10_12_110400_create_settings_table` | `settings` | override nilai `SettingKey` |

**`department_memberships`**: user ↔ department (many-to-many dengan riwayat). Kolom: `position_id`, `is_primary`, `is_leader`, `joined_at`, `ended_at`. Dua generated column + unique index menjamin di level database:

- satu membership aktif per (user, department);
- satu primary department aktif per user.

Satu department boleh memiliki lebih dari satu leader. Mengeluarkan user dari department mengisi `ended_at`; barisnya tidak dihapus.

Semua FK memakai `RESTRICT` (master dan user tidak di-hard delete). Morph map dipaksa (`user`, `department`, `position`, `client`, `role`, ...), sehingga kolom polymorphic tidak menyimpan nama class.

## 6. Authentication

| Fitur | Status |
|---|---|
| Login (**username** + password), remember me | Aktif, throttle 5/menit per username+IP; username tidak case-sensitive |
| Logout | Aktif |
| Forgot / reset password | Aktif, via email (hanya untuk user yang memiliki email) |
| Password confirmation | Aktif (dipakai halaman Security) |
| Change password (`/profile/security`) | Aktif, tercatat di audit |
| Two-factor authentication | Tersedia, opsional per user |
| Public registration | **Nonaktif** (route `/register` tidak ada) |
| Email verification | Tidak dipasang (lihat ADR-006) |
| Passkeys | Tidak dipasang |
| Hapus akun sendiri | Dihapus dari starter kit |

User nonaktif tidak bisa login; pesan "akun tidak aktif" hanya tampil jika password benar. Saat dinonaktifkan, sesi database dihapus dan remember token diganti. `EnsureUserIsActive` mengeluarkan sesi yang masih terbuka pada request berikutnya.

## 7. Role & Permission

System role: **Super Admin**, **Management**, **Staff**. Project Manager, Department Leader, Reviewer, dan Approver adalah peran kontekstual (data), bukan role Spatie.

| Permission | Super Admin | Management | Staff |
|---|:-:|:-:|:-:|
| dashboard.view | ✓ | ✓ | ✓ |
| user.view | ✓ | ✓ | |
| user.create / update / activate / deactivate | ✓ | | |
| department.view | ✓ | ✓ | ✓ |
| department.create / update / activate / deactivate | ✓ | | |
| position.view | ✓ | ✓ | |
| position.create / update / activate / deactivate | ✓ | | |
| client.view | ✓ | ✓ | ✓ |
| client.create / update / activate / deactivate | ✓ | ✓ | |
| master-data.view | ✓ | ✓ | |
| role.view | ✓ | ✓ | |
| role.manage | ✓ | | |
| permission.view | ✓ | ✓ | |
| audit.view | ✓ | ✓ | |
| settings.view | ✓ | ✓ | |
| settings.manage | ✓ | | |

Nilai di atas adalah default awal; administrator dapat mengubah role (kecuali Super Admin) di **Role & Permission**.

Aturan anti-eskalasi (di Domain Action, sehingga juga berlaku untuk Super Admin):

- Tidak ada yang bisa mengubah role akun sendiri atau menonaktifkan akun sendiri.
- Hanya Super Admin yang dapat memberi atau mencabut role Super Admin, maupun menonaktifkan Super Admin.
- Selalu harus ada minimal satu Super Admin aktif.
- Non-Super Admin hanya dapat memberikan role atau permission yang ia miliki sendiri.
- Role Super Admin tidak dapat diubah atau dihapus. Role lain hanya dapat dihapus jika tidak dipakai user.

**Visibility**: `HasVisibilityScope` menjadi satu sumber aturan. Model mendefinisikan `scopeVisibleTo($user)`; Policy `view()` memanggil `isVisibleTo()` yang memakai scope yang sama. Phase 1 menerapkannya pada `User` (pemegang `user.view` melihat semua, user lain hanya dirinya), dan pola yang sama dipakai Project/Task/Ticket nanti.

## 8. Organization & Master Data

| Master | Halaman | Aksi |
|---|---|---|
| Department | `/admin/departments`, detail anggota | add, edit, activate/deactivate, hierarki `parent_id` (tanpa siklus) |
| Position | `/admin/positions` (via Master Data) | add, edit, activate/deactivate, `level` |
| Client | `/clients`, detail | add, edit, activate/deactivate |
| Master Data Center | `/master-data` | kartu per kategori; Project/Task/Ticket ditandai "Coming soon" |

`code` dinormalisasi menjadi huruf besar dan terkunci setelah master dipakai (department memiliki anggota/sub-department, position dipakai membership). Master nonaktif tidak muncul di pilihan baru, tetapi tetap tampil pada data lama.

## 9. Halaman (UI)

| Area | Route | Permission |
|---|---|---|
| Login / forgot / reset | `/login`, `/forgot-password`, `/reset-password/{token}` | guest |
| Dashboard | `/dashboard` | `dashboard.view` |
| My Profile, Security | `/profile`, `/profile/security` | auth |
| Members (list, add, detail, edit) | `/admin/users`, `/admin/users/create`, `/admin/users/{user}`, `/admin/users/{user}/edit` | `user.*` |
| Departments | `/admin/departments`, `/admin/departments/{department}` | `department.*` |
| Positions | `/admin/positions` | `position.*` |
| Clients | `/clients`, `/clients/{client}` | `client.*` |
| Master Data | `/master-data` | `master-data.view` |
| Role & Permission | `/admin/roles`, `/admin/roles/create`, `/admin/roles/{role}/edit`, `/admin/permissions` | `role.*`, `permission.view` |
| Audit Log | `/admin/audit` | `audit.view` |
| System Settings | `/settings` (General, Branding, Numbering; Security read-only) | `settings.view` / `settings.manage` |

Header: toggle sidebar, Global Search (⌘K; mencari member, department, client sesuai permission), tombol **+ Create** (sesuai permission), panel notifikasi (empty state jujur), dan menu profil.

## 10. Audit

`AuditLogger` menulis ke `activity_log` (log name `audit`) dengan event semantik:

`user.created`, `user.updated`, `user.role_changed`, `user.memberships_changed`, `user.activated`, `user.deactivated`, `user.password_changed`, `user.password_changed_by_admin`, `user.password_reset`, `user.password_reset_link_sent`, `user.profile_updated`, `department.created/updated/activated/deactivated`, `position.created/updated/activated/deactivated`, `client.created/updated/activated/deactivated`, `role.created/updated/deleted`, `settings.updated`.

Setiap entri menyimpan aktor, subject, nilai lama/baru (`attribute_changes`), IP, dan user agent. `password`, `remember_token`, dan secret 2FA selalu dibuang sebelum ditulis.

## 11. Testing

```bash
php artisan test --compact      # memakai database MySQL harmaworkspace_testing (phpunit.xml)
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

Test memakai MySQL (bukan SQLite) karena constraint generated column/unique index adalah bagian dari aturan bisnis. CI (`.github/workflows/tests.yml`) menyediakan service MySQL.

| Suite | Cakupan |
|---|---|
| `Auth/*` | login, logout, reset password, 2FA challenge, password confirmation, registrasi tidak tersedia, user nonaktif, sesi dicabut, last login, guest redirect |
| `Access/AuthorizationTest` | halaman admin menolak Staff, aksi Livewire dipanggil langsung tetap ditolak, IDOR, sidebar sesuai permission, Super Admin bypass |
| `Access/RoleManagementTest` | create/update role, audit permission ditambah/dihapus, Super Admin terkunci, anti-eskalasi, hapus role |
| `Organization/*` | Department, Position, User (multi-department, satu primary di level DB, riwayat membership, eskalasi role, Super Admin terakhir, reset link, filter) |
| `Client/*` | create/update/validasi/deactivate/filter |
| `System/*` | audit log, settings, global search |
| `Profile/*` | profil, security, email tidak dapat diubah sendiri |
| `Ui/PageSmokeTest` | semua halaman Phase 1 dapat dirender |

## 12. Architecture Decision Log

| ADR | Keputusan | Alasan / konsekuensi |
|---|---|---|
| ADR-001 | Memakai **Official Laravel Livewire Starter Kit** (Fortify + Flux + Tailwind), bukan Breeze | Fondasi resmi Laravel 13/Livewire 4; fitur auth dipilih via `install:features` (registration & passkeys dimatikan). File Livewire tanpa prefix ⚡ agar ramah git/shell. |
| ADR-002 | **Registrasi publik dimatikan**; akun dibuat admin atau `harma:create-super-admin` | Sistem internal. Kredensial awal hanya dari env/prompt, tidak pernah di source code. |
| ADR-003 | **Department membership** memakai tabel `department_memberships` (bukan `users.department_id`) dengan `ended_at` dan generated-column unique index | Multi-department, satu primary aktif, riwayat tersimpan, invariant dijaga database. |
| ADR-004 | **Project Manager, Department Leader, Approver, Reviewer tetap kontekstual**, bukan role Spatie | Leader = `department_memberships.is_leader`; role sistem hanya Super Admin/Management/Staff. |
| ADR-005 | Satu bypass Super Admin via `Gate::before`, nama role dari `config('harma.super_admin_role')`; invariant bisnis ada di Domain Action | Tidak ada pengecekan nama role tersebar; bypass tidak bisa melewati aturan "jangan nonaktifkan diri sendiri", dsb. Super Admin nonaktif tidak mendapat bypass. |
| ADR-006 | Email verification tidak dipasang | Akun dibuat admin dengan email kantor; link reset password dari admin sekaligus membuktikan kepemilikan email. Dapat diaktifkan kembali via Fortify bila dibutuhkan. |
| ADR-007 | **Satu user = satu system role** di UI | Lebih jelas untuk admin; Spatie tetap mendukung multi-role bila kelak diperlukan. |
| ADR-008 | **Permission didefinisikan di kode** (`PermissionRegistry`), admin hanya mengatur role ↔ permission | Permission tanpa kode pemeriksa tidak bermakna. `master-data.manage` dan `permission.manage` dari brief tidak dibuat karena belum ada fitur yang memeriksanya; ditambahkan saat master Project/Task/Ticket atau direct permission dibangun. |
| ADR-009 | Audit memakai **Spatie Activitylog v5 secara eksplisit** melalui `AuditLogger` (bukan auto-log trait) | Event bermakna bisnis (`user.deactivated` vs "updated"), field sensitif dijamin tidak tercatat. Model `App\Models\Activity` menambah IP & user agent. |
| ADR-010 | Halaman = **Livewire 4 single-file components** di `resources/views/pages`, logic di `app/Domain` | Mengikuti konvensi starter kit; Livewire tipis sehingga API/mobile nanti memanggil Action yang sama. |
| ADR-011 | **Validasi satu sumber** di `app/Domain/*/Rules`, dipakai Livewire dan (nanti) Form Request API | Mencegah duplikasi aturan. |
| ADR-012 | Tabel `statuses`/`priorities` **ditunda ke Phase 2** | Belum ada konsumen di Phase 1; desain (scope, `is_initial`, `is_final`/`is_terminal`, `counts_as_completed`, unique `(scope, code)`, validasi scope terpusat) tetap seperti blueprint §9.2. |
| ADR-013 | Numbering: hanya **setting** (prefix, digit, reset tahunan) di Phase 1; generator + `number_sequences` dibuat bersama modul Project | Menghindari kode tanpa pemakai; format sudah dapat dikonfigurasi dan dipratinjau. |
| ADR-014 | **Test memakai MySQL** (`harmaworkspace_testing`), bukan SQLite | Perilaku generated column/unique index harus teruji pada engine produksi. |
| ADR-015 | **PHPUnit** (bawaan starter kit), bukan Pest | Mengikuti stack bawaan, sesuai brief Phase 1. |
| ADR-016 | Tim (**Team**) belum dibuat | Belum ada kebutuhan di Phase 1; struktur department/membership tidak menghalangi penambahan `teams` nanti. |
| ADR-017 | Profil: user hanya dapat mengubah **nama dan telepon** | Username/email dikelola admin. Role dan leadership tidak dapat diubah sendiri. |
| ADR-018 | **Login memakai username** (unique, huruf kecil); email opsional | Permintaan HARMA DIGITAL. Email tetap disimpan (nullable unique) untuk link reset password; user tanpa email di-reset passwordnya oleh admin melalui Edit. |
| ADR-019 | **Tema terang saja** (dark mode & halaman Appearance dihapus) | Tampilan putih & clean sesuai arahan; satu tema mengurangi variasi UI yang harus dirawat. |
| ADR-021 | **Tetap Livewire** untuk tabel server-side dan CRUD AJAX (bukan jQuery DataTables + yajra); feedback lewat **SweetAlert2 toast** | Livewire sudah memberi server-side table dan AJAX tanpa duplikasi controller JSON. Sanitizer + try-catch + toast dipusatkan di trait `WithFeedback` dan interceptor JS, sehingga tidak ada blok try-catch berulang di setiap halaman. |
| ADR-020 | **Kredensial default Super Admin** `superadmin` / `123123123` di config | Mempermudah setup awal sesuai permintaan. Risiko: password lemah & diketahui umum — wajib diganti setelah login pertama dan di-override via env di production (seeder memberi peringatan di production). |

## 13. Known Limitations (sengaja untuk phase berikutnya)

- Project, Task, Ticket, Department Inbox, Workflow, Approval, Deadline, Calendar, Gantt, Workload, SLA, automation, notifikasi, PWA, dan REST API belum dibuat.
- `statuses`, `priorities`, master Project/Task/Ticket, dan generator nomor dibuat mulai Phase 2.
- Notifikasi di header baru berupa empty state.
- Belum ada paksaan ganti password pada login pertama (`must_change_password`). Rekomendasi: gunakan "Send reset link" alih-alih password sementara.
- Setelah ganti password, sesi di perangkat lain belum otomatis diakhiri (butuh middleware `AuthenticateSession`).
- Audit log belum memiliki retensi/arsip otomatis (`activitylog:clean` tersedia, belum dijadwalkan).

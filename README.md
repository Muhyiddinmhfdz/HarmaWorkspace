# HarmaWorkspace

**Integrated Work Management System for HARMA DIGITAL**

Aplikasi internal untuk mengelola organisasi, master data, dan (pada phase berikutnya) project multi-divisi, task, ticket, workflow, approval, serta deadline.

Status: **Phase 1 — Foundation** (authentication, user & department management, position, client, role & permission, Master Data Center, audit log, system settings).

## Stack

PHP 8.4 · Laravel 13 · Livewire 4 · Flux UI · Tailwind CSS 4 · Laravel Fortify · MySQL (InnoDB, utf8mb4) · spatie/laravel-permission · spatie/laravel-activitylog

## Quick Start

```bash
composer install
cp .env.example .env && php artisan key:generate
# set DB_* (MySQL) dan, opsional, HARMA_SUPER_ADMIN_* di .env
php artisan migrate --seed
npm install && npm run build
composer run dev
```

Login default Super Admin: username **`superadmin`**, password **`123123123`** — segera ganti setelah login pertama (wajib di production, override via `HARMA_SUPER_ADMIN_*`). Tidak ada registrasi publik; akun lain dibuat admin atau via `php artisan harma:create-super-admin`.

Pada environment `local`, seeder juga membuat data demo (department, position, user demo dengan password `password`, client).

## Quality Checks

```bash
php artisan test --compact     # butuh database MySQL harmaworkspace_testing
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

## Documentation

- [docs/phase-0-blueprint.md](docs/phase-0-blueprint.md) — arsitektur, database konseptual, roadmap
- [docs/phase-1-foundation.md](docs/phase-1-foundation.md) — instalasi, struktur kode, role & permission, audit, testing, ADR
- `.ai/guidelines/harmaworkspace.md` — aturan proyek untuk AI agent; jalankan `php artisan boost:install` untuk membangkitkan `CLAUDE.md`

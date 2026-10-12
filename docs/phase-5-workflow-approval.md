# HarmaWorkspace — Phase 5 Dynamic Workflow & Approval Engine

Phase 5 memindahkan aturan transisi status Project, Task, dan Ticket dari peta hardcoded (`ProjectLifecycle`, `TaskLifecycle`, `TicketLifecycle`) ke **workflow ber-versi** yang dapat dikonfigurasi admin, dan menambahkan **Approval Engine** yang dapat dipasang pada transisi mana pun.

Notifikasi, SLA, PWA, API mobile, automation builder, BPMN/graph editor, dan expression language **tidak** termasuk.

---

## 1. Arsitektur

```
Workflow (entity: project | task | ticket)
  └─ Workflow Version (draft → published → archived)        ← record terikat ke satu versi
       ├─ States (masing-masing = satu Status dari scope entity)
       └─ Transitions (from → to, operation, label, confirmation, approval template)
            └─ Rules (permission, context_role, comment/reason/attachment/assignee/resolution required)
```

| Lapisan | Tanggung jawab |
|---|---|
| **Workflow version** | APA yang boleh terjadi: transisi dari status saat ini, siapa yang boleh, apa syaratnya, perlu approval atau tidak (ADR-066, ADR-068) |
| **Domain action** (`ChangeTaskStatus`, `ChangeTicketStatus`, `ChangeProjectStatus`, `CompleteProject`, `ManageTicketAssignments`, `TransferTicket`, Create*) | EFEK bisnis: progress 100%, actual dates, resolution, validasi task/ticket aktif, dsb. Tidak berubah dari Phase 2–4 |
| **`WorkflowGate`** | Dipakai setiap domain action pada baris yang terkunci: mencari transisi yang sah dari status **saat ini**, menolak transisi basi/versi lain/butuh approval, mencatat history |
| **`ExecuteWorkflowTransition`** | Satu pintu untuk transisi dari pengguna: visibility → transisi milik versi record → status saat ini → rule "who" → rule requirement → approval (dua tahap) atau handler domain |
| **Transition handlers** | Jembatan per entity: memetakan `operation` transisi ke domain action yang sudah ada |

Status record tetap disimpan di `status_id`; **state saat ini = state versi yang berstatus sama** (unique per versi). Tidak ada kolom state kedua.

## 2. Database

| Tabel | Kolom kunci | Constraint / index |
|---|---|---|
| `workflows` | `entity_type`, `code`, `name`, `description`, `is_default`, `is_active`, `created_by/updated_by` | unique `(entity_type, code)`; generated `default_marker` → **satu default per entity** |
| `workflow_versions` | `workflow_id`, `version_number`, `status` (draft/published/archived), `published_at/by`, `created_by` | unique `(workflow_id, version_number)`; generated marker: **satu published** & **satu draft** per workflow; `(workflow_id, status)` |
| `workflow_states` | `workflow_version_id`, `status_id`, `code`, `label_override`, `sort_order`, `is_initial`, `is_terminal`, `position_x/y` | unique `(version, code)`, `(version, status_id)`; generated `initial_marker` → **maksimal satu initial** (publish mewajibkan tepat satu) |
| `workflow_transitions` | `from_state_id`, `to_state_id`, `code`, `action_label`, `operation`, `confirmation_message`, `approval_template_id`, `sort_order`, `is_active`, `created_by` | unique `(version, code)`, `(version, from, to)`; `(version, from_state_id)` |
| `workflow_transition_rules` | `workflow_transition_id`, `type`, `config` (JSON tervalidasi) | unique `(transition, type)` |
| `workflow_transition_histories` | `workflow_version_id`, `workflow_transition_id`, `subject` (morph), `from_state_id`, `to_state_id`, `actor_id`, `comment`, `metadata`, `executed_at` | morph index; FK RESTRICT ke versi/transisi/state; **immutable** (model menolak update/delete) |
| `projects` / `tasks` / `tickets` (+kolom) | `workflow_version_id` | FK RESTRICT |
| `approval_templates` | `code`, `name`, `description`, `entity_type` (null = semua), `is_active` | `code` unique; `(entity_type, is_active)` |
| `approval_template_steps` | `step_order`, `name`, `approver_type`, `approver_config`, `minimum_approvals`, `allow_self_approval` (default false), `is_required` | unique `(template, step_order)` |
| `approval_requests` | `approvable` (morph), `approval_template_id`, `workflow_transition_id`, `title`, `status`, `current_step_order`, `input`, `outcome_note`, `requested_by/at`, `completed_at`, `cancelled_at/by` | generated marker: **satu pending per record + transisi**; `(status, requested_at)`, `(requested_by, status)` |
| `approval_steps` | snapshot step: `step_order`, `name`, `approver_type`, `approver_context`, `minimum_approvals`, `allow_self_approval`, `is_required`, `status`, `started_at`, `completed_at` | unique `(request, step_order)`; `status` |
| `approval_decisions` | `approval_step_id`, `user_id`, `decision`, `comment`, `decided_at` | unique `(step, user)`; **immutable** |

Definisi yang sudah dipakai (versi published/archived, transisi dalam history, request, decision) tidak pernah di-hard delete; hanya draft yang belum pernah dipakai yang boleh dihapus (ADR-073).

## 3. Migrasi dari Lifecycle (ADR-066)

1. Migration membuat skema, lalu `EnsureDefaultWorkflows` membangun **Default Project/Task/Ticket Workflow v1** dari `DefaultWorkflowBlueprint` — berisi peta transisi Phase 2–4 yang sama persis (berdasar code status; status buatan admin untuk project/task tetap memakai aturan fallback lama) dan rule "who" yang sama dengan Policy sebelumnya:
   - Task: kerja → Main PIC / leader department / PM / pembuat task standalone / `task.manage-progress`; selesai & reopen → manager / `task.complete`; batal → manager / `task.cancel`; Blocked wajib alasan. **PIC tetap tidak bisa menandai Done sendiri.**
   - Ticket: per operasi (start, review, resolve, close, reopen, reject, cancel, assign, need information/provide information) dengan role kontekstual & permission Phase 4, plus requirement (komentar, alasan, resolution). Need Information → transisi kembali ke status asal (resume) per status.
   - Project: perubahan status → PM / `project.update`; selesai & batal → PM / `project.complete`; selesai memakai operasi `complete` (validasi task/ticket aktif tetap di `CompleteProject`).
2. Semua record lama di-bind ke versi published default; status dan history tidak disentuh.
3. Domain action memakai `WorkflowGate` sebagai pengganti `*Lifecycle::allows()`; UI Project/Task/Ticket membaca aksi dari engine.
4. Kelas `ProjectLifecycle`, `TaskLifecycle`, `TicketLifecycle` **dihapus**. Satu-satunya sumber transisi runtime adalah versi workflow record; blueprint hanya dipakai saat bootstrap. Tidak ada dua sumber kebenaran.

Seluruh 365 test Phase 1–4 tetap lulus tanpa mengubah ekspektasi perilakunya. Penyesuaian hanya: daftar permission role Staff (`RoleManagementTest`) serta penambahan data demo & halaman baru (`SeederTest`, `PageSmokeTest`).

## 4. Versioning (ADR-067)

- Draft dapat diubah; **published immutable** (`ManageWorkflowDraft` menolak versi non-draft). Perubahan = **Create draft version** (salinan versi berlaku) → edit → validate → publish.
- Publish mengunci workflow, memvalidasi, mengarsipkan versi published sebelumnya, dan mempublikasikan draft. Record yang berjalan tetap di versinya (termasuk versi archived); record baru memakai versi published dari workflow **default**.
- Contoh (diuji): Task A dibuat di v1 → v2 dipublikasikan (Backlog tidak bisa dibatalkan) → Task B memakai v2 → Task A masih dapat dibatalkan sesuai v1, Task B tidak.
- Workflow **usable** = aktif **dan** punya versi published. Default tidak dapat dinonaktifkan; ganti default lewat *Make default* (hanya workflow usable). Record dapat memulai di workflow non-default yang usable (Ticket: pilihan **Process** di More options bila ada lebih dari satu; tidak ada pemetaan otomatis Ticket Type → Workflow).
- Mengganti versi record yang sedang berjalan tidak disediakan (ADR-067).

## 5. Rules (ADR-069)

| Rule | Config | Arti |
|---|---|---|
| `permission` | `{permissions: [...]}` | Aktor memegang salah satu permission |
| `context_role` | `{roles: [...]}` | Aktor memegang salah satu role kontekstual |
| `comment_required` / `reason_required` | — | Permintaan wajib membawa komentar/alasan |
| `attachment_required` | — | Record (ticket) wajib punya lampiran |
| `assignee_required` | — | Record wajib punya Main PIC |
| `resolution_required` | — | Resolution wajib (operasi resolve) |

"Who" = permission **OR** role kontekstual; transisi tanpa rule "who" tidak dapat dipublikasikan (kecuali operasi Assign PIC yang mengikuti hak assignment). Super Admin memakai bypass tunggal lewat Gate (`workflow.bypass-rules`). Visibility record selalu dicek terpisah: permission global tidak memberi akses ke record yang tidak terlihat. Tidak ada eval, ekspresi, SQL, atau JavaScript di konfigurasi.

Role kontekstual (`WorkflowActorResolver`): Project — Project Manager, Owner, leader department terlibat, member, creator; Task — Main PIC, kolaborator, leader department task, PM project, creator (standalone), creator; Ticket — requester, requester (bukan Main PIC), Main PIC, kolaborator, leader department saat ini, PM project terkait, creator.

**Operation** (efek domain) per entity: Project `move`/`complete`; Task `move` (efek mengikuti flag status); Ticket `move`, `assign`, `start`, `request_information`, `provide_information`, `send_to_review`, `request_revision`, `resolve`, `close`, `reopen`, `reject`, `cancel`. Builder dan validator memeriksa kecocokan operation dengan flag status tujuan (mis. resolve harus menuju status completed non-final).

## 6. Validasi sebelum Publish

**Error (memblokir):** tanpa state; initial ≠ 1; status beda scope; transisi merujuk state versi lain; operation tidak cocok; tanpa rule "who"; role/permission tidak dikenal; requirement tidak berlaku untuk entity (lampiran hanya Ticket, PIC bukan untuk Project, resolution hanya resolve); template approval nonaktif/beda entity/tanpa tahap wajib; approval pada Assign PIC; state awal tanpa aksi keluar; tidak ada jalur ke state akhir.

**Warning (disadari admin):** state tidak terjangkau; dead-end non-final; status nonaktif; ticket tanpa transisi Assign PIC; draft menghapus state/transisi dibanding versi berlaku ("Perubahan ini hanya berlaku untuk transaksi baru yang memakai versi terbaru").

Ringkasan sebelum publish: initial, jumlah state, transisi, transisi ber-approval, warning. Publish memakai konfirmasi SweetAlert.

Master status tetap dikelola di Master Data; builder hanya memilih status aktif sesuai scope. Status yang dipakai versi published **tidak dapat dinonaktifkan** dan code-nya terkunci.

## 7. Engine & Keamanan

`ExecuteWorkflowTransition::handle(record, transitionId, actor, input)`:

1. `can('view', record)` — 403 bila tidak;
2. transisi harus ada di **versi milik record** → id transisi workflow lain / entity lain ditolak ("Aksi ini tidak berlaku untuk proses record ini.") — ID transisi bukan otorisasi;
3. transisi harus keluar dari status **saat ini** → selain itu "Status sudah berubah. Muat ulang data terbaru.";
4. rule "who" → 403;
5. rule requirement → pesan ramah;
6. approval → precheck domain lalu approval request; tanpa approval → handler domain dalam transaksi: domain action mengunci baris, memvalidasi ulang via `WorkflowGate` (stale), menjalankan efek, mengubah status, mencatat history + audit `workflow.transition_executed` + event `WorkflowTransitionExecuted` (setelah commit).

Domain action yang dipanggil langsung (seeder, assignment, transfer) tetap divalidasi `WorkflowGate`; transisi yang butuh approval **tidak pernah** bisa dieksekusi langsung tanpa approval. Pembuatan record dan transfer ticket juga tercatat di history (transisi null, metadata `created` / `department_transferred`).

## 8. Approval Engine

- **Template** (Administration → Approval Templates): tahap berurutan; tipe approver `specific_user`, `department_leader` (department record atau department tertentu), `project_manager`, `project_owner`, `project_role`, `requester`, `creator`, `permission`; `minimum_approvals` (mis. 2 dari beberapa leader); `allow_self_approval` (default **off**, ADR-071); tahap opsional dilewati bila tidak ada approver.
- **Request** (ADR-070, ADR-074): dibuat saat aktor menjalankan transisi ber-approval setelah precheck domain (mis. Complete Project dengan task aktif → ditolak, tidak ada request mustahil). Tahap template di-**snapshot** ke `approval_steps`; edit template hanya berlaku untuk request baru. Input transisi (komentar, alasan, resolution) disimpan untuk dieksekusi nanti. Satu request pending per record + transisi (DB constraint). Record **tidak** berubah status; UI menampilkan "Approval Pending" (ADR-072).
- **Approver** di-resolve **live** dari rule snapshot (`ApproverResolver`): leader/PM terbaru dapat memutuskan setelah perubahan organisasi. User nonaktif tidak bisa approve; approver tunggal yang nonaktif membuat tahap "Approver unavailable" — tidak pernah dilewati diam-diam; requester atau `approval.view-all` dapat membatalkan request dengan alasan.
- **Keputusan** (`DecideApproval`, row lock): approve (komentar opsional) → tahap selesai bila mencapai minimum → tahap berikutnya → setelah tahap terakhir transisi dieksekusi atas nama requester dengan validasi ulang; reject & request revision **wajib komentar** → request berakhir, record tetap di status semula, alasan tampil menonjol. Keputusan immutable; salah keputusan = request baru.
- **Stale** (ADR-074): bila record berubah selama approval (status lain, task aktif baru, dsb.), transisi tidak dipaksa — request menjadi *cancelled* dengan catatan penyebab; keputusan tetap tercatat.

## 9. Visibility & Permission Approval

Approval terlihat oleh: requester, approver yang ditunjuk tahapnya, yang sudah memberi keputusan, dan pemegang `approval.view-all` yang dapat melihat record. Approver yang ditunjuk spesifik (user, leader department tertentu, project role, PM/owner, requester, creator) boleh memutuskan tanpa akses penuh ke record — halaman approval hanya menampilkan nomor & judul record, permintaan, dan catatan; link ke record hanya bila ia berhak melihatnya. Approver berbasis **permission** (himpunan terbuka) wajib dapat melihat record. Orang lain → 403 (tidak ada kebocoran record privat).

| Permission | Fungsi | Management | Staff |
|---|---|:-:|:-:|
| `workflow.view` | Melihat definisi workflow | ✓ | |
| `workflow.manage` | Membuat/mengubah draft | (Super Admin) | |
| `workflow.publish` | Publish versi | (Super Admin) | |
| `approval.view` | My Approvals | ✓ | ✓ |
| `approval.view-all` | Semua approval + pembatalan | ✓ | |
| `approval.decide` | Memberi keputusan saat menjadi approver | ✓ | ✓ |
| `approval.manage-templates` | Mengelola template | (Super Admin) | |

Approve/reject/revisi digabung menjadi `approval.decide` karena kelayakan sebenarnya ditentukan kontekstual per tahap. Konfigurasi workflow & template = tugas administratif; default hanya Super Admin (ADR-075), dapat diberikan lewat Role & Permission.

## 10. UI

- **Administration → Workflows**: daftar (Workflow, Entity, Published Version, Status, Last Updated; badge "Default for new …"); New Workflow (nama, entity, deskripsi, *Start from* salinan workflow lain atau kosong); detail dengan tab **Overview** (pengaturan, default, aktif, versi berlaku), **States & Transitions** (structured builder: daftar state + aksi dikelompokkan per state asal — From → To, Action, Who, What Is Required, Approval — modal aksi tanpa JSON/ID teknis; panel Validate & Publish), **Versions** (status, publish oleh/kapan, jumlah record, hapus draft), **Usage** (jumlah record per versi). More: Create draft version, Duplicate workflow, Make default, Activate/Deactivate.
- **Administration → Approval Templates**: daftar (Name, Entity, Steps, Active, Used By) dan editor tahap dengan picker sesuai tipe approver.
- **My Work → My Approvals**: Waiting for Me / Requested by Me / Completed; tabel desktop + kartu mobile. **Approval detail**: progress tahap (✓ / ● / ○), keputusan & komentar, tombol Approve / Request Revision / Reject (modal; alasan wajib untuk reject/revisi), Cancel request.
- **Record pages**: aksi status berasal dari workflow (Project/Task/Ticket), modal generik untuk alasan/komentar sesuai rule (domain modal tetap: blocker task, resolution & penolakan ticket, tanggal selesai project), konfirmasi dari `confirmation_message`, label "(approval)" pada aksi ber-approval, komponen **ApprovalProgress** (pending, atau callout Approval Rejected / Revision Requested / Cancelled dengan alasannya). Aksi yang sudah menunggu approval tidak ditawarkan ulang.
- **Dashboard**: kartu "Pending My Approval" hanya bila > 0.

Staff tetap melihat label sederhana (Start Work, Send to Review, Approve, Request Revision) — tidak ada ID transisi, versi, atau rule.

## 11. Activity & Audit

Event: `workflow.transition_executed` (teknis; disembunyikan dari timeline manusia, tetap di Audit Log), `workflow.created/updated/activated/deactivated/default_changed/draft_created/version_published/version_deleted`, `approval.requested`, `approval.approved`, `approval.rejected`, `approval.revision_requested`, `approval.cancelled`, `approval_template.*`. Event approval ditulis dengan subject = record, sehingga timeline Project/Task/Ticket menampilkan "Dewi meminta approval untuk 'Resolve'", "Andi menyetujui tahap …". Audit menyimpan workflow, versi, kode transisi, history id, request id, before/after.

## 12. Seeder

- **SystemSeeder** (`WorkflowSeeder`, idempotent): membuat default workflow per entity **hanya** bila entity belum punya default dan code `DEFAULT_*` belum ada; tidak pernah mengganti/menimpa workflow yang sudah ada (termasuk yang diubah admin); bind record yang belum terikat. Instalasi baru: `migrate` + SystemSeeder → Project/Task/Ticket dapat dibuat dan dijalankan (diuji).
- **DevelopmentSeeder** (demo): template *Ticket QA + TS Approval* (leader Programmer → leader Technical Support) dan *Task Simple Review*; workflow non-default *Bug Ticket Workflow (QA + TS)* dan *Task with Review Approval*; satu ticket bug menunggu tahap pertama (muncul di My Approvals Andi).

## 13. Upgrade dari Phase 4 (tanpa `migrate:fresh`)

```bash
php artisan migrate --force
php artisan db:seed --class="Database\Seeders\System\SystemSeeder" --force
```

Diverifikasi pada salinan database Phase 4 berisi data: 7 migration berjalan; **seluruh data bisnis identik** sebelum/sesudah (projects termasuk progress & health, tasks termasuk progress & actual dates, tickets termasuk acceptance/resume/resolution, assignments, milestones, transfers, comments, status & priority master, activity log); 3 default workflow v1 published (Project 6 state/10 transisi, Task 8/16, Ticket 11/25); 0 record tanpa workflow; permission Phase 5 diberikan ke role default; aksi tetap berperilaku sama setelah upgrade; migrate & SystemSeeder kedua tidak mengubah apa pun.

## 14. Tests

`php artisan test` → **423 test lulus, 1425 assertion** (MySQL). PHPStan level 7: 0 error. Pint: bersih. Suite Phase 5 (49 test):

| File | Cakupan |
|---|---|
| `Workflow/DefaultWorkflowTest` | default per entity (published, 1 initial, semua status), transisi = lifecycle lama, record baru terikat + history pembuatan, bootstrap mengikat record lama tanpa mengubah status, SystemSeeder tidak menimpa/menduplikasi, binding lazy |
| `Workflow/WorkflowVersioningTest` | v1/v2 (record lama tetap v1, baru v2), published immutable, satu draft, hapus draft vs published, switch default hanya untuk record baru, default tidak dapat dinonaktifkan, record memilih workflow usable |
| `Workflow/WorkflowValidationTest` | tepat satu initial (validator + DB), scope status, transisi duplikat & lintas versi, rule "who" & kecocokan operation, unreachable & dead-end, publish butuh draft valid & permission |
| `Workflow/WorkflowExecutionTest` | transisi valid + efek domain + history, bukan dari status saat ini, layar basi, aktor tidak berhak & record tak terlihat, permission rule, komentar & lampiran wajib, lintas workflow & lintas entity, history immutable |
| `Workflow/WorkflowAdministrationTest` | Staff/Management tidak bisa mengedit, membangun & publish workflow dari UI, template approval dari UI, status yang dipakai workflow tidak dapat dinonaktifkan |
| `Approval/ApprovalEngineTest` | request + snapshot, sequential + eksekusi transisi atas nama requester, self approval (default ditolak / diizinkan), reject & revisi wajib komentar, duplikat pending, approval basi dibatalkan, precheck domain sebelum request, minimum approvals, approver nonaktif, keputusan immutable, transisi ber-approval tidak dapat dieksekusi langsung |
| `Approval/ApproverResolverTest` | requester, department leader, project manager, project role, specific user, permission + visibility |
| `Approval/ApprovalVisibilityTest` | My Approvals hanya yang eligible, detail tidak bocor, approver yang ditunjuk hanya melihat ringkasan, approver permission tanpa akses tidak eligible, ApprovalProgress + kartu dashboard + aksi pending tidak ditawarkan ulang, keputusan lewat halaman |
| `Approval/TaskApprovalTest` | Task Done lewat halaman task menunggu leader department, lalu progress 100% |

Juga: `Ui/PageSmokeTest` (+9 halaman), `System/SeederTest` (demo workflow/approval).

## 15. Architecture Decisions

| ADR | Keputusan | Alasan / konsekuensi |
|---|---|---|
| ADR-066 | **Workflow engine menjadi sumber kebenaran transisi**; default workflow dibangun dari lifecycle Phase 2–4, kelas lifecycle dihapus | Tanpa big-bang: perilaku identik (test lama lulus), lalu satu sumber kebenaran. |
| ADR-067 | **Versi published immutable; record tetap di versinya; record baru memakai versi published dari workflow default** | Transaksi lama tidak berubah diam-diam; perubahan proses aman. |
| ADR-068 | **Workflow menentukan kelayakan transisi; domain action menentukan efek bisnis** (operation → handler) | Tidak memindahkan logika domain ke engine; efek tetap teruji. |
| ADR-069 | **Rule finite & data-only**; "who" = permission OR role kontekstual; bypass Super Admin lewat Gate | Aman (tanpa kode di DB), mudah dipahami admin. |
| ADR-070 | **Request menyalin (snapshot) tahap template; approver di-resolve live** | Edit template tidak mengubah request berjalan; perubahan organisasi tetap dihormati. Approver yang ditunjuk spesifik boleh memutuskan dengan ringkasan saja. |
| ADR-071 | **Self approval mati secara default** | Pemisahan pelaksana & pemeriksa (mis. PIC tidak menyetujui pekerjaannya). |
| ADR-072 | **Approval tidak membutuhkan status entity tambahan** | Record tetap "In Review" dengan indikator Approval Pending; tidak ada ledakan status. |
| ADR-073 | **Workflow/versi/template yang dipakai tidak pernah di-hard delete**; hanya draft yang belum dipakai | Integritas history & audit. |
| ADR-074 | **Eksekusi dua tahap dengan validasi ulang**; approval basi dibatalkan, tidak dipaksa | Data dapat berubah selama approval; tidak ada transisi yang melanggar aturan domain. |
| ADR-075 | **Konfigurasi workflow & template approval = permission administratif** (default Super Admin) | Proses inti tidak diubah sembarangan; dapat didelegasikan via Role & Permission. |

## 16. Known Limitations

- Tidak ada visual graph/drag-drop editor (builder terstruktur + daftar); tidak ada parallel branching, kondisi/expression, cron, atau automation.
- Tidak ada migrasi record ke versi workflow lain, pemetaan otomatis Ticket Type → Workflow, atau pemilihan workflow saat membuat Project/Task (action sudah menerima `workflow_id`).
- Tidak ada override "force approve" Management; approver unavailable diselesaikan dengan membatalkan request lalu memperbaiki template.
- My Approvals memfilter request pending secara live (maksimal 500 terbaru) karena approver kontekstual; untuk volume besar perlu denormalisasi kandidat approver.
- Tidak ada notifikasi; `WorkflowTransitionExecuted` dan event Ticket belum punya listener.
- Status baru buatan admin tidak otomatis masuk workflow: tambahkan lewat draft versi lalu publish.

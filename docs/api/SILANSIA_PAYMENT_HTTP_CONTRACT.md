# Payment HTTP — P9-4C

Kontrak backend untuk data sintetis pada PostgreSQL milik runner. **Belum boleh digunakan operasional.** `payment_http.enabled` dan `synthetic_validation` default `false`; adapter juga memerlukan environment testing, identitas database milik runner dan readiness P9-4B. UI P9-4D memakai kontrak ini dalam pengujian terisolasi. Scanner operasional, entitlement enforcement dan cutover belum tersedia.

## Request dan authorization

Semua endpoint memakai web/session authentication, akun aktif, CSRF Laravel, `Accept: application/json` dengan quality positif, tanpa header Inertia. Token hanya dalam body, tidak dalam query/URL. GET hanya menerima query yang disebutkan. UUID dipakai untuk semua target.

| Method dan path | Input | Authorization / hasil |
| --- | --- | --- |
| GET `/keluarga/billing/invoices` | `page`, `status=unpaid/paid/cancelled` opsional | Current AND frozen family owner; managed invoices saja, 20 records + `has_more`; cancelled hanya read, tanpa cancel command |
| GET `/keluarga/billing/invoices/{invoice}` | Tidak ada | Scope sama; ringkasan invoice dan public payment attempts |
| GET `/keluarga/billing/invoices/{invoice}/payment-context` | Tidak ada | Invoice unpaid, positif, belum pending/verified; context submit baru |
| POST `/keluarga/billing/invoices/{invoice}/payments` | Multipart `command_context`, `confirm=1`, satu file `proof` | 201 command fact submit; 200 jika replay identik. Nominal selalu dari backend |
| GET `/admin/billing/payments` | `page`, `status=pending/verified/rejected` opsional | Admin aktif; default antrean pending |
| GET `/admin/billing/invoices/{invoice}/payments/{payment}` | Tidak ada | Admin; payment managed/family_upload harus milik parent |
| GET `.../{payment}/review-context` | `operation=verify/reject` | Admin; context baru dengan expected state dari core |
| POST `.../{payment}/verify` | JSON `command_context`, `confirm=true`, `bank_receipt_checked=true` | Admin; bank receipt diperiksa manual; existing writer melakukan verify + paid atomik |
| POST `.../{payment}/reject` | JSON `command_context`, `confirm=true`, `reason_code`, `public_reason`, `private_note` opsional | Admin; public reason wajib ≤2.000 karakter, private note ≤2.000; terminal rejection |
| GET `/billing/payment-commands/recovery-context` | Tidak ada | Admin/Keluarga aktif; binding recovery baru untuk sesi saat ini |
| POST `/billing/payment-commands/reconcile` | JSON `command_context` asli, `recovery_context` baru | Current authorization; hanya durable replay, tanpa first mutation |
| GET `/billing/invoices/{invoice}/payments/{payment}/proof` | Tidak ada | Admin atau current AND frozen family owner; parent scoping; verified private attachment |

Perawat tidak mengelola pembayaran. Ownership transfer tidak memindahkan histori tagihan. Keluarga lama maupun keluarga baru tidak memperoleh invoice lama melalui transfer. Admin tetap boleh memeriksa pending proof yang sah. Tidak ada assisted intake, partial/overpayment/refund/reversal/cancel/reopen endpoint.

`amount` adalah string desimal. Ringkasan invoice menyertakan `elderly_id` milik sendiri untuk menghubungkan tagihan per lansia, tanpa profil/medical notes. Jangan mengirim nominal, actor, status, owner, revision bebas, path, hash, MIME, disk atau reference bebas dari client. Field di luar whitelist ditolak. Response tidak menserialisasi model mentah, private notes atau metadata proof.

## Validasi bukti F1

JPEG/JPG/PNG, nonempty, maksimal 2.097.152 bytes, masing-masing sisi maksimal 2.048 pixel dan total maksimal 4.000.000 pixel. Extension harus cocok dengan MIME server, magic/struktur dan hasil complete GD decode. PNG memakai whitelist chunk konservatif dan CRC; JPEG memeriksa marker/segment/scan/end. Truncation/trailing bytes dan signature active content yang dikenali ditolak. Beberapa image dengan metadata/chunk yang tidak didukung juga akan ditolak, bukan dire-encode.

Original bytes immutable tanpa resize/re-encode. Validasi bukan sertifikasi antivirus dan tidak membuktikan semua polyglot dapat dikenali. Scanner operasional masih OPEN; hanya synthetic owned testing yang diizinkan. File/path/hash client tidak dipercaya. Envelope multipart maksimum 2.162.688 bytes dan JSON maksimum 16.384 bytes; web server/PHP juga harus memiliki limit yang sesuai sebelum rollout.

## Context dan recovery

Envelope authenticated-encrypted dengan namespace payment sendiri mengikat actor, audience database-run, sesi, operation, invoice/revision/payment, request UUID, proof reference atau expected state, serta issued/expiry. Masa initial context 300 detik. Context bukan pengganti authorization atau CSRF.

Jika respons POST hilang, **jangan mengganti intent atau mengulang POST otomatis**. Pertahankan original context, minta fresh recovery context dan kirim reconcile. Original yang expired dapat dipakai untuk lookup committed outcome; initial mutation baru ditolak. Tidak perlu file browser setelah reload. Jangan menyimpan proof bytes/private note di localStorage/sessionStorage. Session rotation/logout membuat context lama tidak dapat dipakai; perlu review manual, bukan context baru untuk mengulangi transaksi yang belum pasti.

Absent/uncommitted → 409 `outcome_unresolved`, tidak berarti rollback. Candidate committed → core menghitung ulang canonical intent lalu mengembalikan command fact asli. Reject semantics direkonstruksi di server dari durable action/payment dan expected state asli; private note tidak perlu dipertahankan browser. Reconcile memakai row locks bila candidate ada, bukan transaksi SQL READ ONLY. Reconcile tidak membuat file/registry baru.

Response sukses: `command_fact`, `invoice_id`, `revision_id`, `payment_id`, `amount`, `recorded_at`, `replayed`. Fact `submission_recorded` tetap demikian setelah attempt ditolak atau invoice sudah paid; fact bukan status terkini.

## Storage dan operator

Objek dan registry privat dipublikasikan dengan exclusive create, per-reference lock, flush/sync dan read-back SHA-256/size/MIME. Metadata/binding immutable. Reference sama dengan bytes berbeda ditolak. Path confinement memeriksa canonical path dan symlink/junction. Tidak ada generic storage URL atau public disk.

Filesystem dan SQL tidak atomik bersama. File/registry setelah SQL rollback atau crash tetap disimpan privat. Publikasi parsial ditahan di tempat; tidak ditimpa, dihapus atau diperbaiki otomatis. Internal `inventory()` hanya menunjukkan opaque reference dan keberadaan registry/objek dengan disposition `manual_review_required`, bukan status pembayaran. Tidak ada route inventory publik.

Operator harus menginventarisasi secara berwenang, membandingkan dengan durable billing request/payment secara read-only dan menahan kasus ambigu. Tidak boleh auto-link, mark-paid, mengubah histori, mengganti reference, atau menghapus arsip. Retention duration, quota/admission, ACL/scanner, backup/restore RPO-RTO, recovery/disposal authority dan SOP manual lengkap masih gate rollout. Cleanup test hanya untuk root disposable sintetis milik runner, setelah semua worker selesai.

## Error dan privasi

401/403/404/409/413/415/419/422/429/500/503 memakai envelope statis `error.code/message/retry_mode`, `financial_outcome=undisclosed` dan correlation UUID. 406 untuk negosiasi Accept/Inertia yang salah. Tidak menyatakan rollback berdasarkan timeout/500/503. Jangan menafsirkan `retry_mode` sebagai izin automatic POST retry; simpan intent dan reconcile.

`Cache-Control: no-store, private`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`. Bukti dikirim sebagai attachment dengan nama statis. Logger boundary tidak meneruskan body, SQL, private path, token atau exception arguments. Native throttle terpisah: read 60, context 30, submit 5, review 10, reconcile 10, proof 20 per menit per actor/target. Monitoring dan alert staf tidak memakai bucket atau gate billing.

## Pengujian

### Adendum baca-saja P9-4D (D-UI01 disetujui pengguna)

Empat GET shell Inertia: `/keluarga/tagihan`, `/keluarga/tagihan/{invoice}`, `/admin/pembayaran`, `/admin/pembayaran/{invoice}/{payment}`. Shell tetap memerlukan session, akun aktif, role, parent/ownership scope, serta admission testing/storage yang sama; query asing ditolak. Endpoint JSON di atas tetap menolak `X-Inertia` dan HTML Accept. Tidak ada POST finansial baru.

GET JSON menambah `resident: {id, name_current}`. Admin memperoleh `financial_owner: {id, name_current}` dengan UUID penanggung dari snapshot invoice dan nama dari direktori saat ini. Antrean Admin menyertakan nomor dan bulan invoice; detail Admin memuat ringkasan invoice bersama whitelist payment dalam satu snapshot baca-saja. Label bukan snapshot nama historis. Data medis, ledger dan metadata proof tidak disertakan. Rincian interval layanan persis dan rekening resmi belum ada dalam kontrak payment; UI menampilkan bulan tagihan dan petunjuk menghubungi Admin, tanpa menghitung ulang nominal atau mengarang rekening.

Shell menyediakan `payment_ui_context` berisi versi, actor/role dan HMAC opaque konteks sesi, audience, serta `hold_key`. Metadata ini bukan command context atau izin finansial. GET Inertia shell dibaca ulang sebelum memperoleh context tindakan dan sebelum POST; perubahan actor/session/audience menahan tindakan. Hanya penanda boolean `1` pada key opaque disimpan dalam sessionStorage. Command context, request UUID/reference/expected state, proof dan catatan tetap ephemeral. Hold stabil lintas login actor/resource yang sama agar reload/login ulang tidak membuat pengajuan pengganti. Hilangnya context asli menuntut pemeriksaan manual; tidak ada tombol reset hold atau mutation retry otomatis.

Client payment terisolasi memakai credential same-origin, CSRF, native multipart boundary, pembacaan no-store yang dapat dibatalkan, dan satu POST per explicit intent. Timeout/500/503 bukan bukti rollback. Reconcile hanya menggunakan context asli dalam memori dan recovery context baru. Logout/unmount menutup akses state; private proof ditampilkan lewat Blob dari authorized attachment dan URL dicabut pada penutupan/unmount/logout. File/notes tidak disimpan dalam browser storage.

### Launcher runtime terisolasi

Entry canonical setelah P9-4E.1 adalah [tests/Support/run-framework-isolated.php](../../tests/Support/run-framework-isolated.php). Runner payment/HTTP/UI/PostgreSQL merupakan lapisan internal, **bukan entry standalone**. Jangan menjalankan PHPUnit, Artisan test, browser harness, atau direct runner dengan environment buatan untuk melewati launcher. Hilangnya konteks isolasi pada direct runner ditolak sebelum bootstrap Laravel; ini bukan izin mengabaikan launcher.

Petunjuk berikut hanya untuk pengujian sintetis yang disetujui pada pekerjaan berikutnya. **Tidak ada command runtime, preflight, database test, browser, Pint, atau build yang dijalankan pada remediasi dokumentasi P9-4F.2.** Pemeriksaan source tidak membuktikan launcher akan PASS pada environment saat ini.

| Mode launcher | Cakupan berdasarkan source |
| --- | --- |
| `preflight` | Membuat runtime root owned; resolved-path/logger probe positif dan 18 probe penolakan, tanpa fixture, migration, database access, atau financial POST |
| `targeted` | Runner owned payment dengan flag internal `--payment-http-only`: acceptance payment HTTP/security terarah beserta safety probes dan migration compatibility; bukan full regression atau browser acceptance |
| `browser` | Runner owned payment dengan flag internal `--payment-ui-only`: acceptance browser payment dan invoice beserta pemeriksaan pendukung; bukan full PostgreSQL regression |
| `full` | Runner owned payment tanpa flag pembatas, termasuk full owned PostgreSQL regression dan browser invoice/payment |
| `unit` | PHPUnit suite Unit melalui `phpunit.p94e1.xml`, cache hasil milik runner |
| `general` | PHPUnit `tests/Feature/ExampleTest.php` melalui konfigurasi terisolasi; bukan seluruh feature suite |
| `frontend` | Regresi Node frontend dengan preflight/cache terisolasi |
| `pint` | Laravel Pint seluruh repository dalam mode `--test`, cache di root owned; tidak memformat source |
| `build` | Vite melalui adapter test; output, dependency cache, dan font cache berada di root owned, bukan `public/build` bersama |

Source juga memiliki mode `format` yang dapat mengubah file test allowlisted. Mode itu tidak termasuk contoh acceptance di sini dan memerlukan persetujuan scope perubahan tersendiri. Flag internal runner bukan nama mode launcher.

#### Prasyarat dan batas isolasi

Launcher menyiapkan canonical temporary root `silansia-p94e1-<acak>` miliknya sendiri, marker ownership/nonce valid, PHP INI/prepend, dan environment child **sebelum Laravel diinisialisasi**. Resume root harus canonical dan marker-nya sesuai; path alias, symlink/junction escape, root hilang/tidak valid, atau child yang kehilangan environment ditolak. Jangan mengarang path root, menyalin nonce, atau mengarahkan root ke storage repository.

`LARAVEL_STORAGE_PATH` mengarah ke storage owned. Compiled views, bootstrap/config/package/route/event cache, file cache/locks, file session paths, private proof, serta temporary uploads harus tetap berada di root tersebut. Cache/session array adalah handler non-file eksplisit pada profil yang memakainya; path file cadangan tetap diperiksa agar tidak kembali ke workspace. PHP `sys_temp_dir`, `upload_tmp_dir`, `session.save_path`, dan `error_log` diarahkan ke root owned; TMP/TEMP/TMPDIR diwariskan ke child. Node/npm/Vite/build cache dan output juga terisolasi.

Logger tidak dianggap aman hanya karena teks `LOG_CHANNEL=null`: environment launcher menggunakan nilai terkutip yang benar, lalu guard memeriksa channel Laravel dan handler Monolog yang benar-benar di-resolve sebagai **satu `NullHandler`**, tanpa destination file Laravel. PHP error log tetap memakai path owned. Fallback logger, handler file, atau path di shared repository storage ditolak. Resolved probe berlangsung sebelum setiap operasi; snapshot protected storage diperiksa sebelum/sesudah child. Evidence launcher sendiri ditulis ke `docs/report/` dengan prefix P9-4E.1, terpisah dari framework runtime storage.

Fresh-root setup masih bergantung pada [baseline historis P9-4E.1](../report/SILANSIA_P9_4E_1_BASELINE.json): launcher membandingkan hash protected storage dengan baseline tersebut sebelum melanjutkan. Baseline harus tersedia dan cocok; laporan lokal yang Git-ignored belum tentu tersedia pada clone/deployment lain. **Jika baseline hilang, stale, atau berbeda: STOP.** Jangan mengedit baseline historis, melakukan rebaseline otomatis, menghapus/merestorasi shared log, atau mematikan guard. Perbedaan memerlukan review terpisah, bukan perbaikan otomatis melalui petunjuk ini.

Mode PostgreSQL menggunakan administrative database `postgres` pada loopback untuk membuat database test acak `silansia_test_<16 hex>` milik runner. Identitas target testing, server/port, OID, owner, marker COMMENT, dan cache diverifikasi sebelum mutasi serta cleanup; kegagalan guard berhenti tanpa fallback. Migration/fixture/financial POST hanya pada target sintetis owned, tidak pada `manpro_db` atau database development/production. Server Laravel browser memakai loopback port acak; Chrome memakai profil test baru, tanpa CDP attach atau profil pengguna. Child tidak memuat `.env` development. Tidak ada aktivasi gate operasional, scanner produksi, atau verifikasi bank nyata.

#### Contoh PowerShell untuk pekerjaan berikutnya

Jalankan dari root repository hanya setelah pengujian runtime mendapat persetujuan dan prasyarat baseline dipenuhi. `$php` harus menunjuk PHP kompatibel dengan dependency/extensi proyek. `$ownedRoot` **hanya** berasal dari hasil preflight sukses di bawah; jangan memakai root contoh atau root lama yang tidak diverifikasi. Launcher memverifikasi kembali ownership/resolved paths pada setiap mode. Jangan menampilkan isi marker/nonce.

```powershell
$preflightRaw = & $php tests/Support/run-framework-isolated.php preflight
if ($LASTEXITCODE -ne 0) { throw 'Preflight gagal; STOP untuk review.' }
$preflight = $preflightRaw | ConvertFrom-Json -ErrorAction Stop
if ($preflight.ok -ne $true -or $preflight.mode -ne 'preflight' -or [string]::IsNullOrWhiteSpace($preflight.root)) {
    throw 'Hasil preflight tidak valid; STOP.'
}
$ownedRoot = $preflight.root
foreach ($mode in @('targeted', 'browser', 'full', 'unit', 'general', 'frontend', 'pint', 'build')) {
    & $php tests/Support/run-framework-isolated.php $mode $ownedRoot
    if ($LASTEXITCODE -ne 0) { throw "Mode $mode gagal; STOP untuk review." }
}
```

Contoh tersebut adalah urutan acceptance jika seluruh cakupannya diizinkan, bukan keharusan mengulang seluruh suite untuk perubahan dokumentasi. Hasil skipped/NOT RUN tidak dihitung passed. Catat exit code, assertion, browser acceptance, dan preservation dari run yang benar-benar dilakukan; HTTP 200/build sukses tidak menggantikan browser acceptance. Pertahankan [Option B](../report/SILANSIA_P9_4F_1_OPTION_B_FINAL_DECISION_RECORD.md): family upload sendiri, Admin review/bantuan administratif, tanpa assisted submission atau perubahan financial contract.

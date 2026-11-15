# Kontrak internal initial invoice issuance — P9-2C

Tanggal: 14 November 2026, Asia/Jakarta. **Internal, owned PostgreSQL test database saja.** Tidak ada kontrak HTTP/preview token browser, route, UI, payment, revision/cancel writer, cutover, atau izin aktivasi development/production. `manpro_db` ditolak bahkan bila gate ON.

## API dan batas trust

Namespace `App\Services\Billing`:

- `InvoiceIssuanceService::preview(actorId, elderlyId, billingMonth): InvoiceIssuePreview` membaca sumber terkunci dan menghasilkan `InvoiceIssueConfirmation` serta `InvoiceEligibilityResult`; tidak membuat reservation/request/invoice.
- `issue(InvoiceIssueCommand): InvoiceIssueResult` hanya untuk trusted server caller. DTO readonly memuat actor UUID, elderly UUID, month `YYYY-MM-01`, request UUID dan confirmation readonly berisi fingerprint SHA-256. UUID dinormalisasi lowercase. Invalid command dilempar sebagai `InvoiceIssuanceFailure('invalid_command')`.
- Command tidak mempunyai rate, nominal, days, pilihan enrollment/owner/witness, policy bebas, nomor, status atau due/grace. Source dipilih ulang server. Actor ID bukan bukti izin.
- Result readonly berisi decision/reason; hanya sukses committed ISSUE membawa invoiceId, revisionId, amount string dan issuedAt UTC6. `replayed=true` berarti outcome R1 committed yang sama, bukan penerbitan ulang. HOLD/SKIP/REJECT tidak membawa estimasi atau membuat durable claim.
- `InvoiceIssueConfirmation` adalah konteks trusted internal, bukan capability, signature atau authorization token. Jangan deserialize input browser langsung menjadi command. Transport/signature/expiry/CSRF tetap desain terpisah; tidak ditambahkan pada C.

`InvoicePolicy::issue` benar-benar dipanggil melalui Gate untuk User yang diambil ulang dengan row lock, termasuk replay. Hanya Admin aktif; akun tidak ditemukan, Perawat, Keluarga, perubahan role/deactivation sebelum lock diperoleh ditolak sebelum financial outcome dikembalikan. Family inactive tidak menghapus kewajiban historis; current/historical owner tetap harus akun ber-role keluarga. Keaktifan resident tidak menjadi blocker K02. Recorder historis tidak diasumsikan masih memegang role/keaktifan pada waktu sekarang.

## Gates dan readiness

`config/invoice_issuance.php` membaca `INVOICE_ISSUANCE_ENABLED`, default boolean false; terpisah dari ownership gate dan approval reference. Tidak menambah variabel ke `.env`/`.env.example`. Kedua gate wajib tepat boolean true pada fixture; string `true` dalam config bukan izin.

Entry memerlukan testing, default silansia_testing, pgsql, URL NULL, config cache tidak aktif, path cache unik runner dan tidak berisi file/symlink, database acak silansia_test_16hex, owner/current role, host/port, alamat server, OID dan COMMENT token cocok konteks runner. pgsql development tidak boleh menjadi database test. Pemeriksaan ini berada pada kode App, tanpa ketergantungan autoload-dev Tests. Konteks tidak diambil dari DTO. Ini guard terhadap salah target, bukan sandbox terhadap pemegang privilege database/eksekusi kode.

Caller tidak boleh mempunyai outer transaction: service harus mengendalikan outer COMMIT. Isolation wajib READ COMMITTED dan session_replication_role origin. Ownership readiness existing memeriksa schema/migration records/ref/guards, lalu kontrak tambahan memeriksa:

1. Katalog sembilan tabel sumber/billing: nama/type/null/default kolom, FK/CHECK/UNIQUE termasuk valid/deferred, definisi dan valid/ready indeks, serta enam deferred constraints guard B. SHA-256 pinned `17c0f079263ed8fc89faf09acc661c52beee1447c9c75f2c1c1ea12e861048c0`, diukur dari migration asli pada owned PostgreSQL 18; tidak belajar dari target runtime.
2. Source migration B dinormalisasi LF dan dipin SHA-256 `8c29cdf61723cefedfd47a3d468cd28059206b285db8289be538e81b4a80d6c0`. File dibaca sebagai teks, tidak dieksekusi. Dua belas signature, return type, body prosrc exact, language, volatility, SECURITY INVOKER, search_path, kind, strict/leakproof/parallel properties diperiksa terhadap kontrak.
3. Delapan belas trigger diperiksa definisi event/table/function/WHEN, enable state dan deferred properties, bukan hanya nama.

Additional test-only fault triggers tidak menggantikan guard B. Katalog tidak memasukkan constraint trigger tambahan di luar enam final trigger B; ini memungkinkan deferred-fault fixture. Tidak mengklaim semua trigger tambahan atau privileged DDL otomatis aman. Perubahan versi/deparser/schema sah pun dapat fail closed; re-pin memerlukan review kontrak dan pengujian baru, bukan runtime fallback. Guard/migration A/B tidak diubah oleh C.

## Snapshot dan locking

Sebelum transaksi, reader mengumpulkan semua calon User IDs: actor, current owner, seluruh historical owners/recorders dan ownership action actors. Setelah begin, timeout local lock 5s, statement 15s, idle-in-transaction 20s. Readiness diperiksa, Users UUID ascending dikunci dan actor diotorisasi ulang. Elderly dikunci, kandidat dibaca ulang; perubahan set membatalkan seluruh transaksi dan maksimal satu restart. Tidak mengambil User lock terlambat.

Kemudian seluruh enrollments resident dikunci ascending UUID (termasuk closed dan record di luar bulan), seluruh period full chain ascending UUID, root resident/month bila ada; ownership actions dibaca lengkap melalui join period. Tidak mengambil lock master class/kamar atau membaca master rate sebagai tarif. Parent elderly serialization kompatibel dengan transfer/enrollment supported writers. Ini bukan predicate locking terhadap SQL enrollment mentah yang mengabaikan protokol parent.

Numeric dipilih `monthly_price::text`, NULL dipertahankan; timestamp PDO disimpan string offset dengan presisi aktual, tanpa serializer Eloquent precision0. Enrollments existing memang berpresisi detik; period/action ledger dan initial issuance berpresisi mikrodetik. Reader tidak membuat presisi palsu atau mengubah model dateFormat. Parser P9-2B tetap terbatas pada kontrak instant AD empat digit/offset eksplisit, bukan seluruh rentang BC/infinity/format tahun PostgreSQL; sumber di luar kontrak gagal tertutup, tidak diperbaiki secara spekulatif.

Evaluator P9-2B memeriksa full chain/current leaf/audit, whole-calendar-month mixed family K01, one witness seluruh charge interval, single chargeable enrollment, closed/no-stay/inactive K02. No-service tetap SKIP, NULL/multi/partial/empty ledger HOLD; tidak memperbaiki histori atau clipping ke capture. Untuk ISSUE, full PostgreSQL ownership validator juga dijalankan. Readiness dan fresh DB clock diambil lagi setelah locks/validation, kemudian kalkulasi final menggunakan evaluator yang sama. Tidak ada rumus kedua.

## Freshness dan canonical encoding

Domain separator adalah ASCII domain + LF, diikuti compact JSON array UTF-8, JSON_UNESCAPED_SLASHES, tanpa whitespace atau float. Arrays berurutan eksplisit; bukan insertion order dari object request. SHA-256 lowercase hex. Tidak menyertakan hash sendiri.

**Source domain:** `silansia.invoice.source.v1`. Array:

`["pg-billing-source-v1", elderlyId, currentFamilyId, month, policy, enrollments, periods, actions]`.

Enrollments `[id,elderlyId,classId,startUTC6,endUTC6-or-NULL,rawRateString-or-NULL]`; periods `[id,elderlyId,familyId,previousId,fromUTC6,toUTC6-or-NULL,recordedBy,recordedAtUTC6,source]`; actions `[periodId,actorId,action,occurredAtUTC6]`. Setiap list di-sort deterministik. Sumber invalid tetap difingerprint sebagai fakta raw dan tidak diperbaiki; evaluator menahan/menolak. Resident name/active status, master prices, stay dan existing-root ID tidak masuk fingerprint; root conflict serta auth divalidasi terpisah. Semua financial source yang dibaca, termasuk closed/history, terikat. Perubahan histori di luar interval dapat memerlukan preview ulang secara konservatif.

**Intent domain:** `silansia.invoice.issue.intent.v1`. Array `["issue", elderlyId, month, frozenConfirmationFingerprint]`. Actor/request UUID adalah namespace/key DB, tidak menjadi generated outcome intent. Tidak memasukkan fresh amount/clock/UUID atau menghitung ulang confirmation dari policy aktif saat replay. Frozen confirmation mengikat versi/source yang sudah dikonfirmasi. Fixed vector dengan elderly `22222222-2222-4222-8222-222222222222`, bulan 2026-10-01 dan fingerprint 64 huruf a menghasilkan `b99c8cc7239d88ec5591e86ed96420778908ae23cee8d02a998cde6eebb99709`.

**Content domain:** `silansia.invoice.revision.content.v1`. Urutan tepat:

`[elderly_id,billing_month,service_enrollment_id,financial_owner_id,ownership_period_id,issued_by,issued_at,policy_version,monthly_price,effective_started_at,effective_ended_at,chargeable_days,days_in_month,amount,currency,due_date,grace_period_ends_on]`.

UUID lowercase, money string canonical dua desimal, instant UTC enam pecahan, days integer, dates ISO. Owner/witness ikut hash meskipun disimpan di root, bukan kolom baru R1. Unknown extra keys/hash sendiri diabaikan; field wajib tidak boleh hilang. Fixed content vector lengkap pada InvoiceIssuanceContractTest menghasilkan `686abfa631499066a91d0f612b50fa6e757e05cf104911da3e0383d3375ef301`. Nilai expected dihitung independen dari bytes JSON/LF melalui SHA-256 .NET PowerShell. CHECK format hash DB tidak membuktikan isi; writer membentuk dan tes menghitung ulang hash.

## Graph dan tanggal

Satu transaksi: INSERT root managed v1 (UUID allocated/current R1 pointer) → R1 no1/previous NULL/reason initial_issue → complete immutable request issue dengan outcome root/R1/payment NULL → action invoice_issued seq1 → zero_charge seq2 bila nol → full invoice dan ownership validators → outer COMMIT.

Deferred current FK tetap deferred; tidak ada SET CONSTRAINTS IMMEDIATE pada graph parsial, disable trigger, session replication bypass atau INSERT-then-UPDATE request. Root/R1 memakai satu calculation result dan satu initial DB clock timestamp. Due tanggal WIB+7 kalender, grace +3 kalender, inklusif sampai akhir hari pada model kebijakan; tanpa shift libur. `InvoiceDueDates` menguji boundary bulan/tahun/leap day; C tidak menambahkan premium enforcement.

Nomor `SIL-YYYYMM-<root UUID>` tanpa PII. Positif unpaid/paid_at NULL; nol paid/paid_at floor ke detik server, tanpa Payment. Initial/revision/actions memakai UTC6 sama, completed_at sampel clock server setelah writes sebelumnya, bukan waktu COMMIT. created/updated root baru diisi detik server; timestamp existing tidak diubah.

## Idempotency, COMMIT dan failure

Actor row lock menserialisasi namespace durable `(actor_id,request_id)`. Matching committed issue/intent/target mengembalikan R1 frozen yang sama tanpa source freshness ulang atau writes; ini bukan current header versi kelak. Different intent/target menghasilkan idempotency_conflict. Different request atau actor lain dengan root existing menghasilkan existing_root_conflict. Inactive actor ditolak juga untuk replay. Outcome financial tidak dikembalikan sebelum authorization.

Maksimal dua whole-transaction attempts hanya untuk SQLSTATE 40P01/40001 atau stale User set. Tiap attempt reauthorize/readiness/source ulang, intent tetap. Lock timeout 55P03 dan statement timeout 57014 berbeda; keduanya bukan business rejection/success atau auto-retry. Integrity class23 dipisahkan dari HOLD/REJECT; safe rule/SQLSTATE diteruskan tanpa SQL/bindings/PII. Unexpected valid graph rejection oleh guard mengharuskan blocker review, bukan edit guard.

Respons sukses keluar hanya setelah COMMIT terkonfirmasi. Unknown COMMIT melepaskan koneksi, memverifikasi ulang owned identity dan mencoba satu rekonsiliasi outcome tanpa mutasi data di transaksi baru dengan authorization/actor lock. Ini bukan transaksi SQL READ ONLY karena menggunakan row locks. Tidak menjalankan insert bila request belum terlihat; tetap unknown_commit_outcome. Bila actor sudah nonaktif, financial outcome tetap ditutup. Tidak menyimpulkan rollback hanya dari koneksi putus atau request yang belum terlihat. Test ack-loss adalah fault simulation sesudah COMMIT nyata, bukan klaim real network partition diuji.

## Verifikasi dan batas lanjut

Runner default menjalankan schema, core, integrity B, ownership C dan issuance C. `--issuance-only` melabel suite lain NOT RUN; guards identitas/probes/rollback compatibility dan cleanup tetap dijalankan. Histori committed test tidak dihapus/reinsert; hanya seluruh DB owned dibuang setelah verifikasi.

Integration tests memeriksa numeric max exact, ownership mikrodetik, closed/no-stay/historical owner, K01/K02/HOLD/SKIP, graph/header/content hash, due/grace, durable conflicts/replay, legacy, tujuh fault stages termasuk deferred COMMIT, bounded transient SQLSTATE, serta independent-PID authorization lock waits dan lock timeout. PostgreSQL general skips tidak dihitung passed; hasil final ada pada laporan P9-2C.

## Perkembangan verifikasi P9-2D

Harness terpisah pada tests memakai independent PHP/backend PID, TransactionBeginning/Committing/Committed/RolledBack events dan QueryExecuted untuk observasi. Barrier hanya test worker/pipe, tidak ditambahkan ke App. Acknowledgment pelepasan pipe wajib diterima sebelum menunggu peer; Windows launcher PID dicatat terpisah dari interpreter. Lock wait harus mempunyai holder yang terbukti langsung atau melalui antrean blocker PostgreSQL, dengan traversal terbatas. Kasus independen dibuktikan dua transaksi terbuka sebelum salah satunya commit. Stale pre-read adalah race command sebelum TX, bukan klaim dua transaksi SQL overlap pada tahap itu.

Failure tests memisahkan 40P01/40001 yang di-inject dari deadlock/serialization nyata; timeout dan pemutusan backend owned merupakan error PostgreSQL nyata. Satu negative fixture merusak projection graph yang BELUM committed sesudah explicit validators untuk membuktikan deferred guard B menolak COMMIT; bukan correction writer atau penolakan graph valid. Ack-loss di-inject sesudah COMMIT nyata dan diuji bersama concurrent replay. Gates/timeout/policy/guard App tidak dilonggarkan agar test lulus.

Workflow existing hanya menulis transfer/enrollment pada waktu sekarang; tidak ada update tarif snapshot atau backdate. Karena arrears menuntut bulan selesai, transfer tepat batas bulan historis serta penciptaan enrollment baru yang chargeable di bulan lampau tidak disebut race live yang telah diuji. Fixture historis + mutasi prospektif menguji keputusan/freshness, dengan coverage temporal terpisah pada [laporan P9-2D](../report/SILANSIA_P9_2D_INVOICE_CONCURRENCY_AND_INTEGRATION_VERIFICATION_REPORT.md).

Belum ada production/development activation, least-privilege runtime deployment, preview transport, payment, correction/cancellation, capture/backfill atau retention implementation. Load produksi, arbitrary SQL mutation dan clock/server/network partitions yang lebih luas belum dibuktikan. Safety/AI/gait/monitoring/Perawat tidak bergantung writer/gate ini.

## Audit temporal P9-2D.1

Tidak ada timestamp caller pada supported transfer/enroll/close. Namun no-backdate bukan bukti bahwa seluruh histori bulan selesai tidak mungkin berubah: timestamp diambil sebelum COMMIT. Operasi yang telah mengambil waktu sebelum M1 dan memegang ancestor/parent locks dapat terlihat committed sesudah M1. Transfer memakai `clock_timestamp()` PostgreSQL setelah locks; enrollment/closure memakai `CarbonImmutable::now()->startOfSecond()` pada PHP. Clock PHP dan database bukan satu clock yang disinkronkan oleh kode. Ketidakselarasan clock tidak boleh diklasifikasi sebagai kasus mustahil. `transaction_timestamp()` pada trace adalah waktu mulai transaksi aktual, bukan waktu efektif domain.

Untuk operasi baru yang timestamp-nya benar-benar diambil sesudah M1 pada clock yang benar/selaras, memilih effective time sebelum M1 tidak tersedia melalui workflow. Ini tidak membatasi privileged Eloquent/raw SQL: model enrollment menerima atribut interval dan database tidak memiliki guard trusted-clock pada tabel tersebut. Direct writes tetap di luar supported writer/locking protocol.

Group `invoice-temporal` memakai fixture historis exact, workflow/lock waits dengan clock aktual, dan test-only binding `Tests\Support\TemporalLedgerClock` serta Carbon test clock dalam worker owned. Binding mengubah satu sampel waktu ledger yang memang dipakai close/successor/audit; tidak mengubah ledger App, integrity guard, PostgreSQL clock, OS clock atau transaction timestamp. Invoice tetap mengambil clock PostgreSQL aktual setelah bulan fixture selesai. Tes tersebut membuktikan respons writer pada kondisi waktu eksplisit, bukan rollover kalender nyata atau sinkronisasi NTP. Dua transaksi yang bertentangan dapat lebih dahulu tertahan pada Users yang sama sebelum mencapai elderly; trace menunjukkan ancestor lock wait yang aktual, bukan mengasumsikan selalu elderly row wait.

Runner default menambahkan stage temporal sesudah concurrency D. Mode `--temporal-only` menjalankan group temporal dengan semua ownership/cache/probe/cleanup guards yang sama dan melabel group lain NOT RUN. Trace D.1 memakai file baru `SILANSIA_P9_2D1_TEST_EVIDENCE_<run-id>.jsonl`; trace/report historis D tidak ditimpa. Hasil, klasifikasi PROVEN/NOT APPLICABLE/PARTIAL/BLOCKED dan batas penutupan tercantum pada [laporan P9-2D.1](../report/SILANSIA_P9_2D1_TEMPORAL_COVERAGE_CLOSURE_REPORT.md).

## Boundary HTTP Admin P9-3B

Adendum ini memperbarui status belum ada HTTP pada uraian tahap sebelumnya. Core writer/readiness/schema/canonical/calculator/evaluator/ledger dan guards tetap tidak berubah. Tujuh routes menggunakan prefix `/admin/billing/invoices` dan nama `admin.billing.invoices.*`:

| Metode/suffix | Nama | Transport/body |
| --- | --- | --- |
| GET `/` | index | Inertia `Admin/Billing/Invoices/Index` |
| GET `/create` | create | Inertia `Admin/Billing/Invoices/Create` |
| GET `/resident-options` | resident-options | JSON pilihan resident |
| POST `/preview` | preview | JSON `elderly_id`, `billing_month` |
| POST `/` | store | JSON tuple + `request_id`, `confirmation_token`, boolean `confirm=true` |
| POST `/reconcile` | reconcile | JSON tuple + `request_id`, `confirmation_token`; tanpa `confirm` |
| GET `/{invoice}` | show | UUID string, Inertia `Admin/Billing/Invoices/Show` |

Middleware web/auth/auth.session/active/role dan InvoicePolicy hanya mengizinkan Admin aktif. Tidak ada implicit model binding sebelum readiness. GET Inertia adalah kontrak backend, belum ada JSX/resolver page atau navigasi invoice; tidak browser-ready. Ketiga POST menolak X-Inertia/non-JSON response negotiation (406), body non-JSON (415), JSON bukan object atau key asing (422), dan body di atas 8192 bytes (413). CSRF/origin middleware existing dipertahankan. Dedicated per-actor limit read30/preview12/issue6/reconcile12 per menit; tidak ada session blocking dan safety limiter tidak diubah.

Read service memeriksa entry readiness sebelum query billing. Satu transaksi READ COMMITTED READ ONLY, lock_timeout 5s, statement_timeout 15s dan idle timeout 20s menjalankan transaction readiness existing lalu satu business SELECT untuk actor, total/page/projection. Data response berasal dari snapshot SELECT yang sama, termasuk pada halaman kosong; tidak ada lazy query setelah COMMIT. Urutan selalu mempunyai UUID tie-breaker dan jsonb_agg ORDER BY eksplisit. Pagination page1–10000/per_page15,30,50; hanya resident/month/status/sort yang diizinkan. Validator PostgreSQL existing dipanggil untuk managed rows dalam SELECT sebagai fail-closed integrity checks; function VOLATILE dapat mempunyai internal statement snapshots, tetapi tidak menyuplai response facts terpisah. Biaya validator dibatasi jumlah baris halaman, belum diukur pada skala produksi.

Resource whitelist menampilkan exact monetary strings, tanggal, frozen owner ID/period, current revision dan ringkasan initial issue/zero_charge actions. Nama menggunakan `name_current`, bukan snapshot historis. Legacy `billing_schema_version=NULL` minimal read-only, owner/revision NULL dan actions kosong; tidak disintesis dari current family. Managed invalid graph menghasilkan 500. Tidak ada raw model/journal, private notes, payment proof, hash/canonical payload, SQL, source fingerprint atau informasi medis.

Preview menghasilkan `kind=preview`, `issued=false`, keputusan/reason enum existing, calculation hanya untuk ISSUE, selainnya calculation/confirmation NULL. Token hanya dalam JSON preview, tidak di Inertia props/session/flash/query/log. Laravel Crypt mengikat exact schema version1/purpose `silansia.invoice.http.issue.v1`, hash audience dari environment+actual database+OID, actor/resident/request UUID, canonical month, frozen fingerprint, UTC6 issue/expiry. Max4096 karakter dan admission expiry lima menit memakai clock database. APP_KEY/encryption configuration tidak diubah. Expiry setelah admission tidak membatalkan transaksi writer; issue dengan token expired ditolak dan hanya reconcile boleh memakainya.

Issue mengembalikan JSON201 hanya untuk hasil ISSUE durable baru, JSON200 untuk durable authorized replay. Zero-charge tetap paid invoice tanpa Payment. Reconcile melakukan narrow READ ONLY lookup `(actor_id,request_id)` yang sudah committed; absent menghasilkan 409 `outcome_unresolved` tanpa memanggil writer. Present dapat memanggil existing issue dengan frozen command untuk locked reauthorization/replay karena complete-on-insert request immutable dan tidak dapat UPDATE/DELETE/TRUNCATE lewat guards B. Readiness/guard pin diperiksa sebelum lookup dan kembali dalam writer. Proof mencakup attempt UPDATE/DELETE/TRUNCATE CASCADE sesudah lookup COMMIT sebelum replay, ketiganya ditolak, serta fingerprint seluruh financial tables identik. Ini bukan perlindungan terhadap privileged DDL yang menghapus guards atau arbitrary SQL di luar kontrak writer. Tidak ada second writer/private reflection/queue/outer transaction HTTP.

Semua response billing mendapat `Cache-Control: no-store, private`; error JSON berisi `error.code/message/retry_mode`, `financial_outcome`, `correlation_id`. Unknown/unresolved berstatus outcome `unknown`; penolakan lain `undisclosed`, bukan bukti rollback atas upaya terdahulu. Error GET HTML/Inertia menggunakan komponen Error existing dengan URL aman dan tanpa shared props. Status auth401/403, scoped404, method405, negotiation406/415, CSRF419, validation422, limit429, stale/existing/conflict/HOLD409, unavailable/busy/unknown503 dan integrity500 dibedakan. Allow/Retry-After dipertahankan; detail exception/SQL/binding/token/stack/path disanitasi, reporter billing tidak meneruskan exception raw.

Pending intent frontend tetap belum diimplementasikan. Sampai P9-3C disetujui, tidak ada sessionStorage/recovery UI. Rancangannya harus mempertahankan tuple/token yang sama saat unknown/timeout/expired, memisahkan akun dan audience, menghapus akses browser lokal saat logout/account switch, serta tidak menyimpulkan rollback karena intent hilang, 401/403, atau absent lookup. Jangan mengganti request/fingerprint otomatis. Kehilangan APP_KEY/encryption keys/intent storage, tab offline, proxy logging, deployment runtime privileges dan produksi masih memerlukan prosedur operasional terpisah.

Runner default sekarang delapan stages termasuk HTTP sesudah D.1; `--http-only` melabel suite lain NOT RUN. Trace baru `SILANSIA_P9_3B_TEST_EVIDENCE_<run-id>.jsonl` memakai real HTTP Kernel, session database/cookie+CSRF, backend/PHP PID/barriers/lock waits; body/token melalui stdin privat, bukan argv atau trace. Ack-loss adalah fault injection sesudah COMMIT nyata, bukan real network partition. Lihat [laporan P9-3B](../report/SILANSIA_P9_3B_ADMIN_INVOICE_HTTP_INTEGRATION_REPORT.md) untuk hasil aktual dan batas penerimaan backend; tidak ada aktivasi local/production, migrasi baru, frontend, payment/revision/cancellation atau premium enforcement.

## Adendum konteks recovery server P9-3C

Pengecualian terbatas terhadap freeze HTTP P9-3B disetujui pengguna untuk metadata konteks dan tes sebelum UI. GET Create existing kini menambahkan props `recovery_context` dengan exact fields `version` (integer1), `actor_id` (UUID authenticated Admin), `session_context` (64 lowercase hex) dan `audience_context` (64 lowercase hex). `frontend_available=false` tetap berlaku sampai implementasi UI tersendiri. Tidak ada route baru, perubahan command body/token codec, shared props global, atau aktivasi billing.

`InvoiceRecoveryContext::forRequest` mensyaratkan session started, Admin Policy, entry readiness dan transaksi READ COMMITTED READ ONLY dengan full readiness yang sama, timeout lock5s/statement15s/idle20s. Satu SELECT memeriksa Admin aktif pada database aktual dan mengambil namespace environment/current database/OID yang sudah lolos owned guard. Helper tidak menulis marker ke session/database; normal session middleware tetap bekerja. Preflight Create existing untuk resident tetap dipertahankan dan ditutup sebelum transaksi metadata terpisah.

Kedua penanda merupakan HMAC-SHA256 menggunakan current key dari encrypter existing. Session message adalah compact JSON array `["silansia.invoice.recovery.session.v1", lowerActorUUID, sessionId]`; audience message `["silansia.invoice.recovery.audience.v1", environment, actualDatabase, actualOid]`. Pemisahan purpose mencegah penanda dipertukarkan antar tujuan. Browser harus membandingkan seluruh tuple version/actor/session/audience; session marker sendiri tidak menyertakan audience. Perubahan sesi atau actor mengubah session marker; perubahan environment/database/OID mengubah audience marker; key rotation mengubah keduanya. Tidak mengekspos raw session ID, CSRF, APP_KEY, DB name/OID/owner/context token, atau confirmation token.

Penanda bukan credential/authorization dan tidak diterima sebagai field POST tambahan atau pengganti confirmation_token. Policy, token actor/audience binding, TTL admission dan locked writer replay tetap authoritative dan tidak diubah. Guest/role lain/inactive/readiness OFF tidak memperoleh metadata. Tidak ada metadata ini pada dashboard, list/detail, preview atau error shared props.

Konsumen UI kelak wajib membaca konteks dari fresh authorized server GET sebelum memulihkan token sesudah refresh/pageshow/focus/account switch. Cached Inertia history dan cookie ciphertext bukan bukti konteks terkini. Berbeda/null/unavailable context tidak boleh mengizinkan pembacaan intent lama;401/403/logout/expiry atau local cleanup tetap bukan bukti rollback. Tidak mengganti request UUID/fingerprint, tidak automatic issue retry, dan unresolved tetap memerlukan same-intent reconciliation/manual review. Metadata yang telah diterima browser tidak dapat ditarik kembali; request yang sudah in-flight dan script same-origin tetap termasuk batas threat model.

Adendum ini tidak menyimpan token di browser dan tidak menyatakan sessionStorage aman terhadap XSS. Review browser persistence, cleanup lintas tab, storage failure/tampering, fresh-context lifecycle serta real browser testing merupakan pekerjaan UI berikutnya. Lihat [laporan adendum konteks](../report/SILANSIA_P9_3C_SERVER_CONTEXT_ADDENDUM_REPORT.md) untuk hasil aktual dan preservasi; laporan blocker awal dipertahankan sebagai histori.

# Kontrak kalkulasi dan eligibility invoice P9-2B

Status: **implemented sebagai komponen pure, belum terhubung ke HTTP, database reader, issuance atau billing activation**. Policy awal `calendar-wib-daily-half-up-v1` bukan versi invoice production yang telah diterbitkan.

## Batas komponen

Namespace `App\Services\Billing` mengelompokkan calculator, evaluator, enum dan DTO agar tidak menambah operasi billing ke ResidentService atau layanan keselamatan. Tidak ada dependency baru. Kode memakai PHP standar yang sesuai baseline PHP ^8.3 dan memerlukan integer 64-bit untuk kalkulasi uang. Tidak ada Eloquent/DB/config/environment/clock/network/file I/O, nomor invoice, UUID generation atau mutation.

`ISSUE` berarti eligibility domain **atas snapshot yang diberikan**. Itu bukan Admin authorization, pemeriksaan koneksi/lock/gate, hasil guard PostgreSQL, bukti keaslian histori, atau izin menerbitkan invoice. Caller P9-2C tetap harus membaca data lengkap dan tepercaya, mengunci serta memvalidasi ulang di PostgreSQL.

## Calculator API

`InvoiceCalculator::calculate(InvoiceCalculationInput): InvoiceCalculationResult`.

| Input immutable | Kontrak |
| --- | --- |
| billingMonth | YYYY-MM-01 valid, tahun AD empat digit nonzero |
| startedAt / endedAt | Instant dengan offset eksplisit; end nullable; end yang terisi wajib >start |
| monthlyPrice | String decimal exact; maksimum 12 digit integer dan 2 pecahan; bukan master rate |
| policyVersion | Tepat `InvoiceCalculator::POLICY_VERSION`; unsupported version ditolak |

Decimal yang diterima misalnya `0`, `1000`, `1234.5`, `3100000.00`. Canonical output dua pecahan, tanpa leading zero ambigu. Float/int/bool, NULL, exponent, NaN/Infinity, negatif, whitespace, separator/simbol uang dan presisi lebih ditolak. Slot scalar raw pada DTO boleh merepresentasikan nilai invalid agar tidak terjadi coercion menjadi string; nilai tersebut tidak pernah digunakan sebagai nominal atau output kalkulasi. Array/object tidak dapat menjadi harga DTO.

Timestamps diterima sebagai YYYY-MM-DD diikuti T atau spasi, HH:mm:ss, pecahan opsional 1–6 digit dan Z/±HH/±HH:MM. ±HH mendukung representasi PostgreSQL dengan offset eksplisit, bukan deteksi otomatis timezone DB. Offset maksimal±14:00; tanpa offset, negative-zero offset yang ambigu, tanggal rollover, leap-second atau presisi>6 ditolak. Parsing memakai format ketat, reset `!`, warning check dan round-trip; default timezone PHP tidak dipakai sebagai asumsi. Output clipped interval UTC `YYYY-MM-DDTHH:mm:ss.uuuuuuZ`. Di batas tahun AD paling awal, representasi UTC dapat berada pada tahun sebelumnya; evaluator memakai instant sumber tervalidasi untuk membandingkan coverage, bukan mem-parse ulang output sebagai input baru. Tidak mengklaim ada adapter PDO/Eloquent yang telah diuji.

Output: billingMonth, effectiveStartedAt/effectiveEndedAt, chargeableDays, daysInMonth, monthlyRate, amount, policyVersion. Bila tidak ada overlap, clipped start/end dan amount NULL, d=0; itu berbeda dari amount 0.00 dengan d>0. Calculator tidak menentukan ISSUE/HOLD atau paid status.

Contoh sintetis pemakaian internal, bukan endpoint atau penerbitan:

```php
$input = new InvoiceCalculationInput(
    billingMonth: '2028-01-01',
    startedAt: '2028-01-16T12:00:00+07:00',
    endedAt: null,
    monthlyPrice: '3100000.00',
    policyVersion: InvoiceCalculator::POLICY_VERSION,
);
$result = (new InvoiceCalculator)->calculate($input);
// d=16, N=31, amount='1600000.00'; tidak menyimpan record apa pun.
```

## Exact money dan waktu kalender

M=[tanggal1 WIB00:00, tanggal1 bulan berikut WIB00:00). I=[max(enrollment.start,M0),min(enrollment.end atau M1,M1)). End eksklusif, setiap tanggal WIB yang memiliki irisan positif dihitung penuh. Iterasi maksimal 31 interval hari kalender, tanpa 86400, pembagi tetap 30, float diffInDays atau minus 1 mikrodetik.

Tarif diparse menjadi integer0,01 rupiah setelah PHP_INT_SIZE=8. S maksimal 99.999.999.999.999. Sebelum perkalian, pastikan S≤intdiv(PHP_INT_MAX,d). P = S*d, q = intdiv(P,N), r = P%N; hasil = q+(2*r≥N?1:0). Pembulatan half-up satu kali, output string dua pecahan. Maksimum P=3.099.999.999.999.969 pada 31 hari, masih integer 64-bit; tidak ada fallback float/BCMath dependency.

Invalid calculator input melempar `InvoiceCalculationException` dengan `InvoiceReason`, pesan generik tanpa isi snapshot. Capability check memiliki protected test seams untuk mensimulasikan32-bit/overflow sebelum perkalian; bukan konfigurasi tarif. Uji ini tidak membuktikan telah menjalankan binary PHP 32-bit nyata.

## Eligibility API dan bukti snapshot

`InvoiceEligibilityEvaluator::evaluate(InvoiceEligibilityInput): InvoiceEligibilityResult`.

| Snapshot input | Isi dan tanggung jawab caller |
| --- | --- |
| elderlyId/currentFamilyId/residentActive | Current identity/status dari pembacaan tepercaya; status resident tidak dipakai sebagai blocker K02 |
| billingMonth/asOf/policyVersion | Kalender valid, clock eksplisit dari caller; evaluator tidak memanggil clock |
| existingInvoiceId | NULL bila query lengkap membuktikan belum ada root; ID valid apa pun memblokir root baru, termasuk paid/cancelled/legacy |
| enrollments | List EnrollmentSnapshot(id,elderlyId,serviceClassId,start/end,monthlyPrice) untuk menilai seluruh kandidat; bukan hanya active relation |
| ownershipPeriods | Seluruh chain OwnershipPeriodSnapshot(id,elderlyId,familyId,previousPeriodId,from/to,recordedBy,recordedAt,source) |
| ownershipActions | Facts OwnershipActionSnapshot(periodId,actorId,action,occurredAt), hanya ownership events resident tersebut |

List NULL berarti bukti belum dibaca; [] berarti caller menyatakan hasil lengkap kosong. List wajib berindeks0..n−1 berisi DTO final readonly yang sesuai. Tidak menerima raw object `valid=true` atau label `database_guard_passed`. Semua atribut DTO berupa scalar immutable atau list DTO readonly; array property dan elemen tidak dapat dimutasi.

Tidak mungkin membuktikan query completeness, role user, integritas data sumber atau keaslian clock hanya dari DTO. Caller masih bisa mengirim snapshot parsial/palsu; **jangan deserialize request browser langsung ke kontrak ini**. Caller P9-2C harus mengambil snapshot source sendiri dan menjalankan database guards. Unit tests tidak mengubah keterbatasan tersebut menjadi jaminan SQL.

Urutan keputusan primary reason deterministik:
1. Month/policy/context IDs/asOf invalid, month belum berakhir atau root existing → REJECT.
2. Enrollment evidence NULL/invalid → HOLD; hanya source interval valid diperiksa overlap. Tidak ada kandidat → SKIP; lebih dari satu, termasuk adjacent/kelas sama → HOLD.
3. Tepat satu kandidat: calculator strict; NULL/invalid rate→HOLD; unsupported runtime/overflow→REJECT.
4. Missing ownership evidence→HOLD; chain kosong→cutover-required.
5. Validasi chain/audit, current leaf, K01 whole month dan coverage satu witness.
6. ISSUE hanya sesudah semuanya lolos; semua keputusan non-ISSUE membawa calculation/owner/witness NULL.

Snapshot enrollment yang malformed dalam list yang disuplai ditahan untuk review, tidak diam-diam dibuang untuk menghasilkan SKIP. Enrollment valid di luar M tidak chargeable; NULL harga pada enrollment future yang tidak beririsan tidak dijadikan invoice nol.

### Validasi pure ownership

- IDs/context resident konsisten dan UUID case-insensitive; duplicate ID ditolak.
- from=recordedAt sebagai instant, from/to bukan masa depan terhadap asOf; toNULL atau to>from.
- Urut dari from/ID, root previousNULL source resident_create/cutover_capture, successor family_transfer menunjuk predecessor tepat, from=previous.to, keluarga successor berbeda.
- Tepat satu open leaf akhir, sama dengan currentFamilyId; closed tanpa successor, fork, cycle/disconnection, gap/overlap ditolak.
- Satu opening/capture action sesuai source/recorded actor/time, satu close bila closed, actor/time close sama opening successor; event tambahan/duplikat/hilang/foreign/forged facts ditolak.
- K01 menghitung distinct family dengan overlap positif **seluruh M**; transfer sesudah enrollment ditutup tetap HOLD. A→B→A tetap mixed. Transfer tepat M0/M1 tidak menghasilkan mixed palsu.
- I seluruhnya harus dicakup tepat satu witness; tidak memotong I ke capture atau mengabaikan 1 mikrodetik gap.

Tidak ada create/capture/repair/backfill. `financialOwnerId` dan `ownershipPeriodId` hanya dikembalikan pada ISSUE berdasarkan witness. Transfer bulan berikut dapat menghasilkan financial owner historis yang berbeda dari current owner; ini bukan grant family visibility.

### K02, tanpa stay, dan zero

Resident kini nonaktif tetap dapat ISSUE untuk histori valid; actor inactive adalah persoalan authorization terpisah. Tidak ada stay/master price input; enrollment tetap sumber biaya. SKIP(no service) ≠ ISSUE amount 0.00. Tarif0.00 dan tarif positif yang round menjadi0.00 dapat ISSUE domain; tidak membuat Payment, paid_at, invoice/revision/request/action.

## Decision dan reason codes

| Decision | Primary reason |
| --- | --- |
| ISSUE | eligible |
| SKIP | no_chargeable_service |
| REJECT | invalid_billing_month, unsupported_policy, invalid_command_context, invalid_as_of, billing_month_not_ended, existing_invoice_root, unsupported_integer_runtime, integer_overflow |
| HOLD | incomplete_snapshot_evidence, invalid_enrollment_snapshot, multiple_enrollments, null_monthly_rate, invalid_monthly_rate, ownership_cutover_required, invalid_ownership_chain, stale_current_owner, invalid_ownership_audit, mixed_owner_calendar_month, partial_ownership_coverage |

Calculator juga memakai invalid_instant/invalid_interval. Enum adalah kode domain internal, bukan teks UI/API yang telah dipublikasikan. Invalid container/type di boundary PHP menghasilkan TypeError/InvalidArgumentException; command/schema reader P9-2C perlu memetakan secara aman, bukan memercayai client.

## Tanggung jawab P9-2C yang tetap deferred

Admin Policy/active account/CSRF; gate OFF/readiness; fresh database clock dan seluruh User/elderly/enrollment/period locks; complete source reads dan numeric/timestamptz mapping; full PostgreSQL chain/invoice guards; replay/unique root/preview; ID/nomor/hash; actual transaction/R1/actions/COMMIT/due/grace/zero paid. Tidak satu pun menjadi fitur baru P9-2B.

Pure check bukan pengganti B: tidak memeriksa snapshot database yang sama dengan COMMIT, invoice coverage historis existing, role/FK/privilege nyata, sumber tariff historis atau provenance clock. Unsupported raw SQL tetap di luar supported writer protocol.

Unit tests dapat dijalankan tanpa Laravel bootstrap/database:

```powershell
& 'C:/Users/Dije Naufal/.config/herd/bin/php85/php.exe' vendor/bin/phpunit --configuration=phpunit.xml --do-not-cache-result --fail-on-warning --fail-on-risky --fail-on-skipped tests/Unit/Billing
```

Regresi PostgreSQL existing hanya melalui runner owned yang telah disetujui. Tidak menjalankan migration/seeder/reset pada manpro_db. Gate ownership/billing tidak diaktifkan oleh pemakaian calculator atau evaluator.


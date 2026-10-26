# Panduan database Silansia

## Cakupan dan rencana Phase 1

Urutan implementasi: audit dependency/status migration → persetujuan konversi kunci legacy → migration UUID dan tabel domain → model/relasi dan seeder → pengujian PostgreSQL terisolasi → penerapan lokal → dokumentasi akhir. Tidak ada controller, endpoint, halaman, job, atau layanan bisnis baru.

- [ERD, kolom, relasi, dan batasan constraint](SILANSIA_ERD.md)
- [Laporan lengkap Phase 1](../../SILANSIA_PHASE_1_REPORT.md)
- [Laporan implementasi proyek](../../SILANSIA_IMPLEMENTATION_REPORT.md)
- [Laporan remediasi audit Phase 1](../../SILANSIA_PHASE_1_FIX_REPORT.md)

## Menjalankan secara lokal

Gunakan PHP yang kompatibel dengan `composer.lock`, ekstensi `pdo_pgsql`, PostgreSQL, Composer, dan Node/npm. Lingkungan ini telah diverifikasi dengan PHP Herd 8.5.11 dan Laravel 13.33.0. Bila `php` belum ada di PATH PowerShell, gunakan:

```powershell
$php = 'C:\Users\Dije Naufal\.config\herd\bin\php85\php.exe'
& $php --version
```

Untuk checkout baru, jalankan `composer install` dan `npm ci` hanya bila dependency belum tersedia. Salin `.env.example` ke `.env` **hanya jika `.env` belum ada**, lalu isi koneksi PostgreSQL privat yang sesuai. Contoh menyediakan nama database `silansia`; database harus dibuat terlebih dahulu. Isi `APP_KEY` menggunakan `php artisan key:generate` hanya pada instalasi baru tanpa key. Jangan mengganti key pada instalasi yang sudah memiliki data terenkripsi.

```powershell
& $php artisan migrate:status
& $php artisan migrate
& $php artisan db:seed --class=ServiceClassSeeder
npm.cmd run build
& $php artisan serve
# Terminal lain, bila memakai pengembangan Vite:
npm.cmd run dev
```

Migration konversi hanya menerima tabel users kosong dan sesi tanpa user terhubung. Jika kondisi tidak terpenuhi, proses berhenti dan diperlukan rancangan pemetaan integer–UUID tersendiri. Jangan menghapus data untuk melewati pengaman. Migration historis tetap asli. PK `sessions.id` tetap string sesuai Laravel, FK `sessions.user_id` UUID.

Seeder normal hanya membuat master VIP/kelas 2/kelas 3. Harga awal NULL; `firstOrCreate` mempertahankan UUID, tarif, dan konfigurasi yang sudah ada saat rerun. Tidak ada seed akun admin atau pasien. Pembuatan akun dan aktivasi menjadi cakupan tahap berikutnya.

Revisi manfaat final Phase 3B (22 Oktober 2026) menggunakan kolom yang sudah ada, tanpa migration. Kelas baru memakai `CanonicalServiceClasses`: seluruh kelas mendapat notifikasi keluarga instan setelah verifikasi, riwayat insiden terverifikasi, dan rekap gait bulanan; VIP dan Kelas 2 mendapat rekap mingguan; hanya VIP mendapat laporan harian, kamar privat, dan CCTV keluarga. Monitoring AI/alert petugas berlaku global untuk semua kelas tanpa syarat pembayaran, bukan kolom entitlement per kelas.

Seeder tetap **tidak mengubah konfigurasi existing**. Kelas yang masih menyimpan flag lama akan ditandai tidak sesuai canonical terbaru; mutasi terkait tetap ditolak. Sinkronisasi existing memerlukan persetujuan terpisah, transaksi, lock baris kelas menurut UUID, pemeriksaan ulang selisih yang disetujui, pembaruan flag benefit saja, dan pembandingan data terlindungi sebelum commit. Jangan menggunakan rollback, reset DB, atau `updateOrCreate` untuk memaksakan sinkronisasi. Nama, harga, UUID, kode, jenis/kapasitas kamar, status, stay/enrollment, serta riwayat tidak boleh ditimpa. Rincian: [laporan revisi manfaat Phase 3B](../report/SILANSIA_PHASE_3B_BENEFIT_REVISION_REPORT.md).

Migration aditif `2026_10_21_000003_reject_nan_monetary_values.php` menolak numeric NaN pada harga kelas/enrollment dan nominal invoice/payment. Migration mengunci keempat tabel terhadap penulisan selama preflight/DDL, memeriksa seluruh kolom sebelum menambahkan CHECK, lalu membatalkan transaksi bila ada NaN lama. Data tidak diperbaiki/dihapus otomatis; koreksi nominal memerlukan keputusan terpisah. Constraint nonnegatif dan aturan nullable lama tetap dipertahankan. Tidak ada perubahan kolom atau migration historis.

## Pengujian database yang aman

```powershell
& $php tests/Support/run-postgres.php
```

Runner hanya menerima `APP_ENV=local`/`testing`, menolak config cache aktif, dan memerlukan role PostgreSQL dengan izin `CREATEDB`. Kredensial dibaca dari koneksi `pgsql` yang sudah dikonfigurasi; tidak dicetak atau disalin ke file. Untuk lingkungan yang tidak menyediakan izin tersebut, hasil runner akan gagal dengan pesan keterbatasan, bukan beralih ke SQLite.

Runner melakukan:

1. Membuat satu DB sementara `silansia_test_` ditambah 16 karakter heksadesimal acak; mencatat OID, owner, alamat/port server, serta token ownership acak pada COMMENT database.
2. Membuat direktori cache sementara unik milik run tersebut. Child menolak path cache yang berbeda atau berkas cache yang sudah ada **sebelum bootstrap Laravel**, sehingga cache stale tidak dimuat. XML PHPUnit tidak lagi memaksa path cache statis.
3. Menjalankan enam probe penolakan sebelum baseline: cache stale, environment salah, konfigurasi database salah, konteks hilang, OID salah, dan token salah. Database target tetap belum memiliki tabel migrations setelah probe.
4. Menerapkan migration Laravel bawaan melalui `tests/Support/postgres-child.php`. Child hanya menerima action baseline/migrate/rollback/status/phpunit, bukan perintah shell bebas.
5. Menguji guard konversi dengan exception RuntimeException dan pesan yang tepat. Snapshot tipe/default/nullability kolom, seluruh record migration, serta seluruh baris user/session fixture harus sama sebelum/sesudah penolakan. Error koneksi/SQL lain tidak dianggap hasil negatif yang sukses.
6. Menerapkan tiga migration Phase 1 dan dua migration Phase 4B, membaca status, lalu menjalankan seluruh suite PostgreSQL Phase 1–4B melalui `phpunit.pgsql.xml`, dengan `--fail-on-risky` dan `--fail-on-warning`. Hasil terbaru ada pada laporan Phase 4B; angka remediasi Phase 1 di bawah tetap historis.
7. Melakukan rollback **hanya lima migration domain Phase 1/4B pada DB sementara**, dimulai dari dua migration gateway, membandingkan snapshot baseline legacy lengkap, lalu menerapkan ulang seluruh migration tersebut. Guard cache/ownership/target tetap aktif; timeout child suite dibatasi 360 detik, action lain 180 detik.
8. Menutup koneksi dan memverifikasi nama/OID/owner/server/token kembali sebelum menghapus hanya DB acak milik runner. Jika ownership tidak dapat dibuktikan, cleanup ditolak dan runner gagal; database tidak dihapus secara spekulatif. Direktori cache milik run dibersihkan tanpa penghapusan rekursif. Tidak ada `migrate:fresh` atau `db:wipe`.

Koneksi terpisah `silansia_testing` tidak mempunyai nama database default. `PostgresTestGuard` mencocokkan environment, nama koneksi, konfigurasi host/port/database/username, current_database(), OID, owner, alamat/port server aktual, serta token COMMENT. Pemeriksaan ada di child yang menjalankan mutasi, bukan hanya di parent. Parent juga memverifikasi target sebelum mutasi fixture. Setiap kasus PHPUnit memeriksa guard sebelum transaksi dan seeder fixture; database operasional tidak digunakan oleh suite constraint.

Preflight PHPUnit memakai koneksi terpisah tanpa bootstrap handler Laravel, lalu PHPUnit mengelola lifecycle aplikasi setiap tes secara normal. Pemeriksaan risky/warning tetap aktif. Jangan menjalankan child atau suite PostgreSQL secara langsung dengan konfigurasi buatan; konteks ownership dan cache dibuat oleh runner. Jika konteks hilang atau identitas tidak cocok, eksekusi berhenti sebelum fixture/migration.

```powershell
& $php artisan test --no-ansi
& $php vendor/bin/pint --test app/Models database/seeders database/migrations config/database.php tests/Feature/Database tests/Support
git diff --check
```

Suite umum mempertahankan konfigurasi SQLite kerangka Laravel. Tes PostgreSQL **sengaja skipped** pada suite umum; hasil **40 tes/716 assertion** harus dilihat dari runner PostgreSQL terpisah. Suite umum saat remediasi: 2 lulus, 40 skipped. Kasus tambahan mencakup NaN, nominal negatif, nol/pecahan/batas maksimum, aturan NULL, serta pembatalan preflight NaN tanpa perubahan fixture. Tes negatif memakai model segar dan memeriksa nama constraint bila praktis; tes sesi memastikan record dan seluruh atribut selain user_id tetap sama.

Build Vite dibutuhkan agar smoke test halaman bawaan menemukan manifest. Tidak ada perubahan source frontend pada remediasi ini; build tidak dijalankan ulang karena aset tersedia dan smoke test lulus.

## Aturan penulisan data tahap berikutnya

Gunakan model Eloquent untuk menghasilkan UUID. Penulisan SQL langsung perlu UUID eksplisit. Semua waktu event disimpan di kolom `timestamptz`, dengan koneksi uji UTC. Model domain menggunakan format waktu yang menyertakan offset; gunakan timestamp ISO 8601 dengan offset untuk input. Tanggal invoice tetap DATE dan grace period dihitung tiga hari kalender, bukan 72 jam.

Jangan memakai `is_active` users/camera atau status tagihan sebagai mekanisme mematikan pemantauan keselamatan. Pengaturan `service_classes` hanya menyatakan benefit keluarga. Pembatasan manfaat setelah grace period dan pemulihan setelah pembayaran belum menjadi logika yang berjalan pada Phase 1.

Terapkan protokol transaksi/locking yang dijelaskan pada ERD sebelum membuat UI penempatan, enrollment, ingestion, atau pembayaran. FK tidak memeriksa peran pengguna, kesesuaian kelas, okupansi, maupun entitlement.

Phase 3C menerapkan layanan penempatan/enrollment tanpa migration baru sesuai persetujuan pengguna. Enrollment harus ada sebelum stay, boleh aktif tanpa stay, dan tidak dapat ditutup selama stay aktif. Enrollment baru menolak tarif kelas NULL dan menyimpan tarif kelas yang dikunci sebagai snapshot desimal. Penutupan stay/enrollment dilakukan terpisah tanpa mengubah riwayat harga/invoice/payment. Waktu operasi memakai waktu server, tanpa backdate/penjadwalan. Urutan lock: actor User → kelas menurut UUID → kamar → lansia → record operasional. Layanan mensyaratkan PostgreSQL READ COMMITTED dan menghitung ulang okupansi setelah lock. Unique partial indexes dan CHECK Phase 1 dipertahankan; operasi SQL langsung tetap harus mematuhi aturan layanan karena tidak ada trigger lintas tabel tambahan. Hasil aktual tersedia pada [laporan Phase 3C](../report/SILANSIA_PHASE_3C_REPORT.md).

`source_reference` kamera dienkripsi melalui cast Eloquent dan disembunyikan saat serialisasi. `proof_path` pembayaran harus menunjuk storage privat; uploader dan validasi berkas belum dibuat. Catatan insiden, metadata AI, data notifikasi, dan catatan verifikasi disembunyikan saat serialisasi model terkait. Perlindungan ini perlu dilengkapi Policies, query scope, dan resources yang sesuai pada tahap berikut, terutama untuk relasi yang dimuat secara eksplisit.

Phase 4A menyediakan konfigurasi kamera Admin tanpa perubahan schema. Satu kamera per kamar tetap dibatasi UNIQUE `cameras.room_id`, termasuk saat nonaktif. Registrasi nonaktif dan aktivasi terpisah tidak menyatakan konektivitas. Pemindahan hanya kamera nonaktif tanpa deteksi ke kamar aktif kosong, dengan konfirmasi; kamera dengan deteksi tetap pada kamar historis. Source type dikunci setelah deteksi, sedangkan referensi dapat diganti/dikosongkan secara eksplisit saat nonaktif. Webcam memakai NULL, video memakai referensi file existing di root lokal privat, dan RTSP menyimpan URL rtsp/rtsps terenkripsi tanpa koneksi. Props/old input tidak memuat sumber privat. Mutasi mengunci actor → kelas → kamar menurut UUID → kamera dan memakai token versi untuk konflik edit. Tidak ada detach/delete, perubahan manfaat, atau penulisan histori AI/billing oleh fitur ini. Guard kamar/kelas Phase 3B tetap berlaku meskipun kamera nonaktif. Lihat [laporan Phase 4A](../report/SILANSIA_PHASE_4A_REPORT.md).

## Gateway hasil AI Phase 4B

Schema aditif menambah `camera_operating_periods` dengan UUID kamera/kamar/kelas, snapshot room_type/room_capacity/source_type, dan opened_at/closed_at presisi 6. FK memakai RESTRICT, CHECK menjaga bentuk snapshot/interval, dan partial UNIQUE memastikan satu periode terbuka per kamera. `ai_detections.camera_period_id` nullable untuk legacy; FK gabungan dengan camera_id mencegah periode kamera lain. Occurred_at/received_at diperluas dari timestamptz(0) ke timestamptz(6), tanpa mengarang fraksi historis atau mengubah timestamp stay/enrollment/kamera. Semua migration lama tetap asli.

Lifecycle kamera membuka/menutup periode dalam transaksi aktivasi/deactivation, tanpa backfill atau toggle existing otomatis. ID periode baru pada reaktivasi membedakan konfigurasi meski perubahan terjadi pada detik sama. Deteksi baru gateway wajib memiliki periode yang sah; legacy boleh NULL. Histori kamar dibaca melalui cameraPeriod, bukan camera.room sekarang. Tidak ada trigger aplikasi yang mengklaim menegakkan seluruh aturan lintas tabel untuk SQL langsung.

Gateway default nonaktif, memakai HMAC per-source, allowlist kamera/type, nonce database cache bersama, dan UNIQUE external_event_id existing dengan namespace source/event UUID. Metadata hanya provenance server kecil, bukan JSON model bebas. Fall membuat pending tanpa identitas/verifikasi/notifikasi; gait VIP memerlukan stay/enrollment pada waktu event dan shared tetap unknown. Monitoring keselamatan tidak memeriksa pembayaran atau benefit keluarga. [Kontrak web v1](../api/SILANSIA_AI_GATEWAY_V1.md) dan [laporan Phase 4B](../report/SILANSIA_PHASE_4B_REPORT.md) menjelaskan batasnya.

Rollback migration penghubung gateway menolak deteksi yang sudah memakai periode atau fraksi waktu agar tidak merusak histori. Tidak menjalankan rollback pada database pengembangan. Semua tes perubahan schema, NaN/Infinity confidence, race, dan cleanup hanya memakai DB owned runner.

## Kontrak AI sementara

`ai_detections` adalah envelope: `camera_id`, `external_event_id` unik global, `event_type`, `occurred_at`, `received_at`, serta confidence/metadata opsional. `gait_observations` menyimpan kategori dan asumsi identitas; kamera/waktu/ID eksternal diperoleh melalui `aiDetection`, tanpa menduplikasi kolom. `fall_incidents` menyimpan respons dan verifikasi manusia secara terpisah dari deteksi.

Kontrak ini belum disepakati atau diuji dengan layanan AI nyata. Kategori gait, jenis event, dan skala confidence merupakan asumsi sementara yang diikat CHECK PostgreSQL. Jika kontrak final berbeda, lakukan migration aditif/perubahan constraint terencana; mengganti konfigurasi runtime saja tidak mengubah CHECK. Nama event dari produsen perlu dipetakan pada adapter ingestion yang belum dibuat. Tidak ada observasi otomatis, endpoint, inferensi, diagnosis, biometrik, maupun angka gait klinis yang dihasilkan Phase 1.

Rollback domain menghapus tabel beserta isinya: hanya dilakukan otomatis di DB uji terisolasi. Pada database operasional berisi data, gunakan perubahan maju dan backup/persetujuan khusus; jangan menjalankan rollback sekadar untuk mengubah desain.

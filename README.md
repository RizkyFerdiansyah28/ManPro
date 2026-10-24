# Silansia

Aplikasi web pemantauan lansia untuk panti. Implementasi saat ini mencakup fondasi database Phase 1, autentikasi/provisioning/otorisasi Phase 2, pengelolaan lansia dan hubungan keluarga Phase 3A, pengelolaan kelas layanan dan kamar Phase 3B, serta masa tinggal dan enrollment Phase 3C.

Laravel 13, PostgreSQL, Inertia React, Tailwind CSS 4, dan Vite digunakan sesuai dependency yang sudah tersedia. Entitas aplikasi memakai UUID. Tidak ada registrasi publik.

## Menjalankan lokal

Gunakan PHP dengan ekstensi pdo_pgsql, Composer, Node/npm, dan PostgreSQL. Pada mesin ini PHP tersedia melalui Herd:

~~~powershell
$php = 'C:/Users/Dije Naufal/.config/herd/bin/php85/php.exe'
& $php --version
~~~

Untuk checkout baru, pasang dependency yang belum tersedia dengan composer install dan npm ci. Buat .env dari .env.example hanya jika .env belum ada, isi koneksi PostgreSQL privat, dan buat APP_KEY hanya pada instalasi baru. Jangan menimpa .env atau mengganti key instalasi yang sudah digunakan. Hindari composer setup pada instalasi yang sudah memiliki key.

~~~powershell
& $php artisan migrate:status
& $php artisan migrate
npm.cmd run build
& $php artisan serve
# Alternatif saat mengembangkan frontend, pada terminal terpisah:
npm.cmd run dev
~~~

Migration UUID Phase 1 sengaja berhenti bila ada user legacy atau sesi terhubung yang belum dipetakan. Jangan menghapus data untuk melewati guard. Tidak ada migration baru untuk Phase 2 maupun Phase 3A–3C.

## Membuat Admin awal

Jalankan sendiri setelah meninjau implementasi dan menyetujui pembuatan akun:

~~~powershell
& $php artisan silansia:bootstrap-admin
~~~

Command meminta nama, email, kata sandi tersembunyi, dan konfirmasi. Kata sandi minimal 12 karakter, mencakup huruf besar/kecil dan angka, maksimal 72 byte. Command tidak menerima kata sandi lewat argumen, tidak menyediakan kredensial bawaan, dan menolak mode noninteraktif maupun bootstrap kedua bila Admin sudah ada. Input tersembunyi memerlukan terminal yang mendukung; kegagalan tidak beralih ke input kata sandi terbuka.

Bootstrap Admin tidak dijalankan otomatis. Bila Admin belum tersedia, lakukan bootstrap secara manual. Buka /login, lalu Admin membuat akun perawat/keluarga melalui /admin/accounts/create. Akun baru nonaktif; sampaikan kredensial secara aman lalu aktifkan melalui daftar akun.

## Akses akun

| Role aktif | Beranda | Kelola akun |
| --- | --- | --- |
| admin | /admin | Ya, hanya perawat/keluarga |
| perawat | /perawat | Tidak |
| keluarga | /keluarga | Tidak |

Daftar akun memiliki pencarian nama/email, filter role/status, dan pagination 15 akun. Reset kata sandi membutuhkan kata sandi Admin saat ini, mempertahankan status/role, dan mencabut sesi akun tujuan. UI tidak menyediakan perubahan role, pengelolaan Admin, atau pemulihan Admin yang lupa kata sandi.

Login meregenerasi sesi. Logout menginvalidasi sesi dan mengganti token CSRF. Akun nonaktif ditolak saat login maupun pada permintaan berikutnya. Middleware auth.session menolak sesi dengan hash kata sandi lama. Pembatasan login: 5 kegagalan per email/IP dalam 60 detik, ditambah 30 permintaan per IP/menit. Reset Admin dibatasi 6 permintaan per Admin/menit.

Gunakan SESSION_DRIVER=database seperti konfigurasi proyek agar deactivation/reset dapat menghapus semua sesi akun tujuan. Driver sesi lain belum dijadikan kontrak operasional Phase 2; jangan menggantinya tanpa pengujian revokasi.

Di luar lingkungan local, gunakan HTTPS, APP_DEBUG=false, cookie sesi Secure/HttpOnly dan SameSite yang sesuai, serta konfigurasi proxy terpercaya. Enkripsi riwayat Inertia aktif secara default di luar local. Untuk mengaktifkannya secara lokal, gunakan HTTPS/localhost dan INERTIA_ENCRYPT_HISTORY=true; HTTP domain Herd non-HTTPS tidak mendukung Web Crypto. Tidak ada perubahan .env otomatis.

## Pengelolaan lansia — Phase 3A

Admin membuka `/admin/residents` untuk mencari, memfilter status, menambah, dan melihat detail lansia. Nama serta tanggal lahir dapat diubah melalui halaman edit. Usia dihitung dari tanggal lahir, tanpa kolom usia tersimpan. Data baru berstatus aktif; penetapan status memakai tindakan terpisah dengan konfirmasi.

Pilih penanggung jawab dari selector keluarga aktif yang dapat dicari berdasarkan nama/email, maksimal 15 akun per halaman. Satu keluarga dapat memiliki beberapa lansia. Pergantian keluarga melalui form khusus dengan konfirmasi, bukan payload edit biodata. Form yang sudah usang akan ditolak bila keluarga saat ini telah berubah.

Keluarga yang dinonaktifkan tetap menjadi referensi historis; edit biodata tidak menghapus atau mengganti hubungan itu. Penetapan baru hanya menerima akun keluarga aktif. Setelah reassignment, akses baca mengikuti keluarga yang baru; keluarga lama tidak dapat membuka detail tersebut lagi. Record invoice, pembayaran, insiden, gait, stay, dan enrollment tidak diubah.

Sesuai keputusan pengguna, **penonaktifan lansia ditolak jika masih ada masa tinggal atau enrollment aktif**. Tidak ada penutupan otomatis; penutupan relasi perlu workflow fase berikutnya. Tidak tersedia hard-delete lansia.

Keluarga membuka `/keluarga/residents` untuk melihat daftar/detail miliknya sendiri, termasuk status nonaktif. Tidak tersedia tindakan perubahan. Akses lintas keluarga menghasilkan 404; perawat tetap hanya memakai akses Phase 2 dan tidak memperoleh akses data lansia melalui Phase 3A.

## Kelas layanan dan kamar — Phase 3B

Admin membuka `/admin/service-classes` untuk melihat tiga kelas resmi: VIP (`vip`, pribadi), Kelas 2 (`class_2`, bersama), dan Kelas 3 (`class_3`, bersama). Nama dan tarif bulanan dapat diedit. Tarif boleh kosong, atau angka nonnegatif dengan maksimal 12 digit sebelum titik dan 2 digit desimal. Perubahan tarif hanya berlaku pada konfigurasi kelas, tanpa mengubah snapshot enrollment, invoice, atau pembayaran. Kode, jenis kamar, dan manfaat tidak dapat diubah melalui web; konfigurasi yang menyimpang dari aturan resmi memerlukan pemeriksaan operator.

Matriks manfaat final yang disetujui 22 Oktober 2026:

| Manfaat | VIP | Kelas 2 | Kelas 3 |
| --- | --- | --- | --- |
| Kamar privat | Ya | Tidak | Tidak |
| Monitoring AI oleh petugas | Ya | Ya | Ya |
| Notifikasi darurat instan ke keluarga setelah verifikasi | Ya | Ya | Ya |
| Riwayat insiden terverifikasi | Ya | Ya | Ya |
| Rekap gait bulanan | Ya | Ya | Ya |
| Rekap gait mingguan | Ya | Ya | Tidak |
| Laporan gait harian | Ya | Tidak | Tidak |
| CCTV live untuk keluarga | Ya | Tidak | Tidak |

Monitoring AI dan alert petugas merupakan aturan keselamatan global dan tidak bergantung pada kelas maupun pembayaran. Matriks ini menggantikan aturan manfaat lama, termasuk ketentuan lama yang mengecualikan notifikasi instan bagi Kelas 3. Matriks tidak berarti integrasi AI/notifikasi/CCTV sudah berjalan.

Seeder membuat kelas yang belum ada menggunakan matriks final, tetapi **tidak menyinkronkan baris existing**. Menjalankan seeder ulang tidak memperbaiki flag lama. Halaman manfaat membedakan matriks final dari konfigurasi tersimpan yang belum sesuai. Guard canonical tetap menolak mutasi yang memerlukan konfigurasi sesuai sampai sinkronisasi data disetujui dan dilaksanakan. Tidak ada sinkronisasi otomatis pada startup, request, migration, atau seeder. Lihat [laporan revisi manfaat](docs/report/SILANSIA_PHASE_3B_BENEFIT_REVISION_REPORT.md) untuk selisih data dan langkah sinkronisasi yang menjaga nama, harga, UUID, status, serta riwayat.

Admin membuka `/admin/rooms` untuk mencari kode/nama kamar, memfilter kelas/status, menambah kamar, dan mengedit informasi. Daftar menampilkan 15 kamar per halaman dan jumlah penghuni dari stay dengan `ended_at IS NULL`. Kamar baru aktif dan harus memakai kelas aktif. Kode tetap unik mengikuti constraint PostgreSQL yang sudah ada; VIP wajib kapasitas tepat 1, sedangkan kamar bersama memiliki kapasitas positif. Batas 2147483647 adalah batas teknis kolom integer, bukan batas operasional yang dianjurkan.

Sesuai keputusan pengguna:

- Penonaktifan kelas ditolak jika terdapat kamar aktif, stay aktif pada kamarnya, enrollment aktif, atau kamera tertaut pada kamarnya, termasuk kamera nonaktif.
- Perubahan kelas kamar hanya diizinkan jika kamar belum pernah memiliki stay dan tidak memiliki kamera. Kelas tujuan harus aktif dan perubahan dikonfirmasi. Form usang ditolak jika referensi kelas saat ini sudah berubah.
- Penonaktifan kamar ditolak selama terdapat stay aktif atau kamera tertaut. Kamar dengan hanya stay yang sudah ditutup dapat dinonaktifkan.
- Edit nama/kode/kapasitas dalam kelas yang sama tetap diizinkan bila aman, termasuk mempertahankan kelas saat ini yang nonaktif. Kapasitas tidak boleh kurang dari jumlah penghuni aktif. Aktivasi kamar memerlukan kelas aktif.

Aktivasi/penonaktifan membutuhkan konfirmasi dan tidak mengubah dependensi secara otomatis. Kelas dan kamar tidak memiliki tindakan hard-delete. Perawat maupun keluarga tidak mendapat akses pengelolaan ini. UI manfaat hanya menampilkan konfigurasi; CCTV, laporan gait, notifikasi, dan billing belum diimplementasikan.

Mutasi memakai transaksi serta lock berurutan pada actor, kelas (urut UUID jika lebih dari satu), lalu kamar. Workflow penempatan/enrollment/kamera pada fase mendatang harus mengikuti protokol lock dan memeriksa status/kapasitas ulang; Phase 3B tidak menyediakan workflow tersebut.

## Masa tinggal dan enrollment — Phase 3C

Dari detail lansia Admin (`/admin/residents/{id}`), buka **Lihat riwayat masa tinggal dan enrollment**. Halaman `/admin/residents/{id}/operations` memuat status aktif dan riwayat masing-masing 15 record per halaman. Status kamar/kelas saat ini hanya ditambahkan pada halaman Admin; keluarga tidak mendapat akses operasional baru.

Keputusan pengguna untuk Phase 3C:

- Buat enrollment terlebih dahulu. Enrollment boleh aktif tanpa masa tinggal.
- Enrollment baru membutuhkan kelas aktif dengan konfigurasi canonical final dan tarif yang sudah diatur. Tarif NULL ditolak; nilai nol yang sengaja dikonfigurasi tetap valid. Snapshot disalin dari tarif kelas yang dikunci saat transaksi, tanpa harga bebas dari form. Snapshot tidak berubah ketika tarif kelas kemudian diperbarui.
- Mulai masa tinggal melalui pemilihan kamar aktif yang kelasnya sesuai enrollment aktif. Ketersediaan dalam UI berasal dari stay aktif, tetapi kapasitas diperiksa ulang setelah lock saat penyimpanan. Tidak ada transfer otomatis atau penggantian record aktif.
- Tutup masa tinggal dengan konfirmasi; enrollment tetap aktif. Penutupan enrollment ditolak selama ada masa tinggal aktif. Tutup enrollment melalui tindakan terpisah setelah masa tinggal ditutup.
- Lansia nonaktif tidak dapat memulai operasi baru. Penutupan tetap tersedia bagi Admin untuk menyelesaikan record lama meskipun entitas terkait sudah nonaktif; riwayat tidak dihapus.

Waktu mulai/akhir memakai waktu server dengan presisi detik dan ditampilkan dalam WIB. Tidak ada input backdate atau penjadwalan. Waktu akhir harus lebih besar daripada waktu mulai; penutupan pada detik yang sama ditolak sampai waktu mulai terlewati. Riwayat dengan waktu akhir di masa depan memerlukan pemeriksaan operator sebelum memulai record baru.

Tidak ada invoice, payment, perubahan kamera, integrasi AI, pengiriman notifikasi, atau pembatasan benefit berdasarkan pembayaran yang dibuat oleh workflow ini. Semua mutasi hanya tersedia bagi Admin aktif melalui autentikasi sesi, Policy, Form Request, CSRF, dan scoped binding lansia/record.

Sesuai persetujuan pengguna, tidak ada migration atau trigger baru. Integritas lintas tabel ditegakkan melalui layanan transaksi PostgreSQL **READ COMMITTED**, dengan urutan actor → kelas menurut UUID → kamar → lansia → record operasional. Isolation level lain ditolak sebelum penulisan karena perhitungan okupansi setelah menunggu lock memerlukan snapshot statement terbaru. Partial unique index lama tetap melindungi duplikasi aktif. SQL langsung yang melewati layanan tidak memperoleh seluruh jaminan lintas tabel ini.

Tes konkurensi memakai worker terpisah yang hanya menerima konteks database acak milik runner. Jangan menjalankan `tests/Support/operations-worker.php` secara langsung. Fixture committed serta cleanup di tes tersebut dibatasi oleh guard dan UUID fixture pada DB sementara; tidak memakai database pengembangan.

## Verifikasi

~~~powershell
& $php tests/Support/run-postgres.php
& $php artisan test --no-ansi
& $php vendor/bin/pint --test app routes tests/Feature tests/Support/FacilityFeatureTestCase.php tests/Support/PostgresFeatureTestCase.php tests/Support/operations-worker.php bootstrap/app.php config/inertia.php --no-ansi
npm.cmd run build
git diff --check
~~~

Runner PostgreSQL membuat database acak miliknya sendiri, memeriksa identitas/OID/owner/server/token serta cache child, dan membersihkannya setelah selesai. Role PostgreSQL memerlukan CREATEDB. Suite autentikasi dan database memakai transaksi setelah guard lulus. Jangan menjalankan child langsung atau mengarahkan suite ke database pengembangan.

Tes Laravel umum memakai SQLite in-memory; kasus PostgreSQL sengaja skipped di sana dan harus diverifikasi melalui runner. Jangan menggunakan migrate:fresh, db:wipe, atau rollback pada database pengembangan.

Lihat [laporan Phase 3C](docs/report/SILANSIA_PHASE_3C_REPORT.md) untuk hasil implementasi/verifikasi operasional terbaru, serta [laporan revisi manfaat Phase 3B](docs/report/SILANSIA_PHASE_3B_BENEFIT_REVISION_REPORT.md) dan [hasil sinkronisasi manfaat](docs/report/SILANSIA_PHASE_3B_BENEFIT_SYNC_FINAL_REPORT.md). [Laporan Phase 3B awal](docs/report/SILANSIA_PHASE_3B_REPORT.md) dan [laporan Phase 3A](docs/report/SILANSIA_PHASE_3A_REPORT.md) dipertahankan sebagai riwayat. Ketentuan manfaat lama pada laporan historis digantikan matriks final di atas. Lihat juga [panduan database](docs/database/README.md) dan [ERD](docs/database/SILANSIA_ERD.md) untuk fondasi Phase 1. Laporan pribadi dalam docs/report/ dapat diabaikan oleh Git dan tidak selalu tersedia pada checkout lain.

Fase berikutnya menunggu persetujuan terpisah: AI, insiden, kamera/CCTV, notifikasi, dan billing belum diimplementasikan. Tidak ada otomatisasi transfer penghuni.

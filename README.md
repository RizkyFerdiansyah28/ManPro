# Silansia

Aplikasi web pemantauan lansia untuk panti. Implementasi saat ini mencakup fondasi database Phase 1, autentikasi/provisioning/otorisasi Phase 2, serta pengelolaan lansia dan hubungan keluarga Phase 3A.

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

Migration UUID Phase 1 sengaja berhenti bila ada user legacy atau sesi terhubung yang belum dipetakan. Jangan menghapus data untuk melewati guard. Tidak ada migration baru untuk Phase 2 maupun Phase 3A.

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

## Verifikasi

~~~powershell
& $php tests/Support/run-postgres.php
& $php artisan test --no-ansi
& $php vendor/bin/pint --test app routes tests/Feature/Authentication tests/Feature/Residents tests/Feature/ExampleTest.php tests/Support/PostgresFeatureTestCase.php bootstrap/app.php config/inertia.php --no-ansi
npm.cmd run build
git diff --check
~~~

Runner PostgreSQL membuat database acak miliknya sendiri, memeriksa identitas/OID/owner/server/token serta cache child, dan membersihkannya setelah selesai. Role PostgreSQL memerlukan CREATEDB. Suite autentikasi dan database memakai transaksi setelah guard lulus. Jangan menjalankan child langsung atau mengarahkan suite ke database pengembangan.

Tes Laravel umum memakai SQLite in-memory; kasus PostgreSQL sengaja skipped di sana dan harus diverifikasi melalui runner. Jangan menggunakan migrate:fresh, db:wipe, atau rollback pada database pengembangan.

Lihat [laporan Phase 3A](docs/report/SILANSIA_PHASE_3A_REPORT.md) untuk hasil aktual dan batas verifikasi, serta [panduan database](docs/database/README.md) dan [ERD](docs/database/SILANSIA_ERD.md) untuk fondasi Phase 1. Laporan pribadi dalam docs/report/ dapat diabaikan oleh Git dan tidak selalu tersedia pada checkout lain. Laporan historis tetap dipertahankan.

Phase 3B dan seterusnya belum diimplementasikan: pengelolaan kamar/layanan, penempatan, enrollment, AI, insiden, kamera, notifikasi, dan billing menunggu persetujuan terpisah.

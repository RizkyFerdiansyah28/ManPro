# Browser acceptance invoice sintetis

Suite ini hanya memakai `http://127.0.0.1:5187`. Tidak memakai Laravel port 8000, database, akun development, CDP attach atau profil Chrome pengguna.

Prasyarat: dependency development existing telah di-install (termasuk @playwright/test yang disetujui untuk P9-3C.3B) dan Google Chrome lokal. Tidak perlu `playwright install` atau download browser tambahan.

Di root repository, jalankan fixture jika belum ada listener sehat:

```powershell
node tests/Frontend/serve-billing-fixture.mjs
```

Di terminal kedua, pilih identifier evidence baru agar tidak menimpa hasil sebelumnya:

```powershell
$env:SILANSIA_BROWSER_RUN = 'LOCAL_1'
node node_modules/@playwright/test/cli.js test --config tests/Browser/playwright.billing.config.mjs
```

Satu worker, tanpa retry test, dengan Chrome sandbox aktif dan browser context baru per kasus. Semua request halaman dibatasi ke origin fixture; skenario API disimulasikan per-context melalui request interception. WebSocket hanya boleh ke host/port fixture. Tidak memasang webServer otomatis atau menghentikan proses pengguna.

Assertion finansial memeriksa request issue/reconcile browser, penyimpanan attempted sebelum POST, tuple asli dan fresh context. Log menyimpan boolean/hitungan/path, tanpa body token/cookie atau storage dump. Screenshot hanya data sintetis. Tracing, video dan storage-state export dimatikan.

Output memakai prefix `SILANSIA_P9_3C3B_` dalam `docs/report/`: hasil JSON Playwright, JSONL request, screenshot desktop/mobile, dan direktori artefak kegagalan. Gunakan identifier run berbeda pada pengulangan.

Browser acceptance ini menguji perilaku frontend terhadap kontrak sintetis. Ini tidak membuktikan COMMIT database, real authorization/CSRF, token HMAC atau production E2E. Tes Node frontend tetap dijalankan terpisah dengan pola `tests/Frontend/*.test.mjs`; file browser `.spec.mjs` tidak dicampur ke Node runner tersebut.

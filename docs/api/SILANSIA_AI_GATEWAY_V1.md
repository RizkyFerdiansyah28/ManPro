# Kontrak Web Gateway AI Silansia v1

Kontrak batas aplikasi web yang disetujui untuk Phase 4B. Integrasi dengan model nyata belum dilakukan. Tidak ada inference, akses kamera, streaming, frame/video, atau notifikasi dalam gateway ini.

## Status dan konfigurasi

Default gateway nonaktif, audience kosong, registry sumber kosong. .env operasional tidak dibuat ulang atau diedit oleh implementasi. Gateway memerlukan PostgreSQL dan database cache pada koneksi default yang sama; kegagalan cache/DB gagal tertutup. Tidak memasang Sanctum/Passport.

Konfigurasi server berada di config/ai_gateway.php:

- AI_GATEWAY_ENABLED: false secara default.
- AI_GATEWAY_AUDIENCE: identitas environment, 1–100 karakter ASCII huruf/angka/titik/underscore/titik dua/dash.
- AI_GATEWAY_SOURCES_JSON: array sumber. Setiap sumber memiliki id UUID lowercase, enabled boolean, camera_ids berupa allowlist UUID tanpa wildcard, event_types fall/gait, serta keys berupa array id UUID, enabled boolean, secret opaque literal 32–256 byte tanpa whitespace/control.
- Secret adalah key mesin tersendiri, bukan APP_KEY, password manusia, atau source_reference kamera. Nilai literal secret dipakai sebagai byte key HMAC, tanpa decoding base64/hex otomatis. Gunakan nilai acak yang kuat, simpan privat, dan jangan memasukkannya ke frontend, URL, log, laporan, atau Git.
- Rotasi menggunakan key UUID baru pada source UUID tetap; jangan mengganti source UUID untuk retry. Sinkronkan konfigurasi dan reload worker secara terkontrol; registry config tidak menjamin revocation atomik lintas worker.
- Pengaktifan/koneksi layanan AI nyata memerlukan fase integrasi tersendiri. Contoh/fixture test tidak boleh dijadikan credential produksi.

Tidak ada endpoint untuk provisioning key atau mengambil credential RTSP. CLI read-only `silansia:ai-gateway-manifest --source=<UUID>` mengeluarkan source_id dan daftar camera_id/camera_period_id/opened_at yang diizinkan dan masih terbuka. Tidak mengeluarkan secret, nama penghuni, URL, atau sumber video. Tidak membuat periode/aktivasi otomatis.

## Endpoint dan transport

- POST /api/ai/v1/fall-detections
- POST /api/ai/v1/gait-observations

API stateless dengan autentikasi mesin; cookie/password/sesi manusia tidak menggantikannya. CSRF route browser tetap utuh. HTTPS wajib di luar local/testing; konfigurasi trusted proxy tidak diperluas otomatis.

Gunakan Content-Type application/json, satu object, maksimal 8192 byte, tanpa query string atau Content-Encoding. Tidak ada batch/upload/binary atau arbitrary metadata. Pesan error menggunakan Bahasa Indonesia.

## Autentikasi HMAC

Header wajib:

| Header | Format |
| --- | --- |
| X-Silansia-Source | Stable source UUID lowercase |
| X-Silansia-Key | Key UUID lowercase yang aktif |
| X-Silansia-Timestamp | Unix seconds canonical, dalam ±300 detik dari waktu server |
| X-Silansia-Nonce | 64 lowercase hex acak, baru untuk setiap attempt |
| Authorization | Silansia-HMAC-SHA256 diikuti spasi dan signature lowercase hex 64 karakter |

Materi signature adalah sembilan baris berikut, dipisahkan byte LF (0x0A), tanpa LF terakhir:

~~~text
SILANSIA-AI-V1
<audience>
POST
<path endpoint persis>
<source UUID>
<key UUID>
<timestamp request>
<nonce>
<SHA256 hex lowercase body mentah>
~~~

Signature = HMAC-SHA256 hex lowercase menggunakan byte secret literal. Sign byte JSON yang benar-benar dikirim. Whitespace/order key memengaruhi signature request, tetapi tidak memengaruhi hash idempotensi semantik.

Nonce diklaim melalui operasi cache add atomik dengan TTL 660 detik. Semua worker memakai database cache bersama; nonce yang sama ditolak walau body identik. Jika response hilang atau retry diperlukan, pakai timestamp request dan nonce baru, sedangkan ID event dan payload semantik tetap.

Rate awal: 120/menit/IP sebelum auth, 120 fall/menit/sumber dan 600 gait/menit/sumber setelah auth, bucket type terpisah. Counter cache abuse tidak menyimpan body/credential. Cache tidak tersedia menghasilkan 503, tidak menerima event tanpa proteksi replay.

## Body

| Field | Wajib | Format |
| --- | --- | --- |
| contract_version | Ya | String "1" |
| external_event_id | Ya | UUID lowercase dari produsen; stabil pada retry |
| event_type | Ya | fall atau gait, harus cocok endpoint |
| camera_id | Ya | UUID lowercase kamera yang diizinkan sumber |
| camera_period_id | Ya | UUID lowercase periode operasi milik kamera |
| occurred_at | Ya | RFC3339 dengan Z atau offset eksplisit; fraksi 0–6 digit |
| confidence | Tidak | NULL atau string desimal finite 0–1 dengan maksimal 5 fraksi |
| category | Hanya gait | walking_normal, walking_abnormal, no_walking_detected |

Timestamp tidak menerima waktu tanpa zona, -00:00 (offset tidak diketahui), tanggal tidak valid, leap second, atau lebih dari enam digit fraksi. Disimpan sebagai instant UTC dengan presisi enam fraksi. Confidence menerima "0", "1", "0.85000"; menolak JSON number/bool, string kosong/whitespace, exponent, NaN/Infinity, nilai negatif/>1 atau presisi berlebih. Tidak ada metrik atau unit gait numerik.

Validasi memakai JSON mentah dari body bertanda tangan; hasil normalisasi input global Laravel tidak dipakai sebagai DTO. Nilai kosong/whitespace invalid tidak diubah menjadi nilai valid. Field lain ditolak, termasuk elderly_id, family_id, room_id, received_at, identity_basis, status/verifikasi, benefit, harga, URL/source_reference, gambar/video, dan metadata bebas.

Contoh **skematis** (placeholder bukan UUID literal yang valid; tidak pernah dikirim sebagai data produksi):

~~~json
{
  "contract_version": "1",
  "external_event_id": "<event-uuid>",
  "event_type": "gait",
  "camera_id": "<camera-uuid>",
  "camera_period_id": "<period-uuid>",
  "occurred_at": "2026-10-25T00:00:01.123456Z",
  "confidence": "0.85000",
  "category": "walking_normal"
}
~~~

Untuk fall, event_type fall dan tidak boleh ada category.

## Periode dan waktu historis

Periode menyimpan snapshot kamera/kamar/kelas/tipe kamar/kapasitas/source_type saat aktivasi, tanpa URL/credential. Dibuka pada transisi nonaktif→aktif, ditutup saat deactivation, dan aktivasi berikutnya memakai UUID baru. Interval [opened_at, closed_at) setengah terbuka.

Tidak melakukan backfill dari created_at/updated_at kamera. Kamera existing aktif tanpa periode perlu transisi Admin yang nyata di luar gateway; tidak otomatis di-toggle. ID periode wajib disuplai producer melalui provisioning/discovery operator yang sah.

Event baru tidak boleh di masa depan terhadap received_at server dan maksimal terlambat 86400 detik. Periode tidak diketahui, kamera lain, sebelum cutover, saat gap nonaktif, atau tepat/setelah closed_at ditolak. Timestamp tidak digeser untuk memaksakan kecocokan.

Event periode lama yang sah dapat diterima meskipun kamera sekarang nonaktif, pindah kamar, atau sumber telah diganti. Sejarah kamar dibaca dari aiDetection.cameraPeriod.room, **bukan** camera.room saat ini. Trust sumber masih diperlukan: signature tidak membuktikan frame, identitas manusia, atau kebenaran model.

## Persistence dan atribusi

Fall membuat satu ai_detection dan satu fall_incident pending dalam satu transaksi. Identitas/responder/acknowledgement/verifier/resolution/notes tetap NULL. VIP satu penghuni pun tidak otomatis diidentifikasi atau diverifikasi. Tidak membuat/mengirim notifikasi.

Gait menyimpan salah satu kategori existing. Kamar shared selalu elderly_id NULL/identity_basis unknown. VIP hanya menggunakan vip_room_single_resident jika snapshot VIP/private/capacity 1, tepat satu stay historis, dan tepat satu enrollment historis yang cocok kelas pada waktu event. Jika kosong/ambigu/overlap/tidak cocok, simpan room-level unknown. Tidak memakai pengenalan wajah/biometrik.

Status billing, pembayaran, atau hak CCTV keluarga tidak menghambat safety ingestion. Source tidak mendapatkan PII atau akses keluarga. Ownership dan entitlement laporan keluarga adalah urusan fase web berikutnya.

## Idempotensi

DB external_event_id dibentuk backend sebagai `v1:<source UUID>:<producer event UUID>`, maksimal 76 karakter. UNIQUE existing menjadi arbiter. Rotasi key tidak mengubah identitas event.

Hash SHA256 dibuat dari JSON array berurutan dengan nilai:

~~~text
[1, "1", source_id, producer_event_id, event_type,
 camera_id, camera_period_id, occurred_at_UTC_enam_fraksi,
 confidence_lima_fraksi_atau_null, category_atau_null]
~~~

Metadata hanya provenance server yang dibatasi 1 KiB; body/model output tidak disimpan. Key ID pertama, source/event ID, versi kontrak/hash dan payload hash disimpan tanpa secret.

Retry identik mengembalikan ID yang sama tanpa update timestamp/history/child. Whitespace/order key JSON, offset ekuivalen, confidence "0.85"/"0.85000", nonce baru, dan rotasi key tidak mengubah identitas semantik. Perubahan payload untuk ID sama menghasilkan 409. Legacy tanpa provenance yang cocok tidak diambil alih.

Duplicate sah tetap dilayani setelah batas umur/deactivation, tetapi source/scope/key yang telah dicabut tetap ditolak. Verifikasi manusia yang terjadi kemudian tidak di-reset. Klaim delivery adalah at-least-once dengan duplicate suppression, bukan exactly-once delivery.

## Response

Sukses baru: HTTP 201. Duplicate: HTTP 200.

~~~json
{"accepted":true,"duplicate":false,"detection_id":"<UUID>"}
~~~

Error menggunakan error.code, error.message, request_id server, dan opsional errors berisi field allowlist/pesan aman. Tidak memantulkan payload, secret, signature, SQL, stack trace, URL kamera, penghuni, atau data keluarga.

| HTTP | Arti |
| --- | --- |
| 400 | JSON/framing invalid; HTTPS diwajibkan |
| 401 | Signature/source/key/time request/replay tidak sah |
| 403 | Scope sumber menolak kamera/type |
| 404/405 | Route/versi path/metode tidak tersedia |
| 409 | Payload conflict atau camera_state_changed |
| 413/415 | Ukuran atau transport tidak didukung |
| 422 | Payload/waktu/periode/kategori tidak valid |
| 429 | Rate limit; gunakan Retry-After |
| 503 | Disabled/cache/DB/lock/infrastruktur tidak tersedia |

camera_state_changed dapat dicoba lagi dengan nonce baru dan konteks capture yang tetap sah. event_conflict perlu ditinjau produsen; jangan mengganti ID hanya untuk menutupi conflict. 503/response hilang di-retry dengan event ID dan isi semantik sama.

## Lock dan batas pengujian

Urutan gateway: kelas UUID → kamar UUID → calon lansia UUID → kamera → periode → deteksi/child. Layanan Admin tetap mempunyai prefix actor User. Discovery diperiksa ulang setelah lock; perubahan tidak mengambil class/room lock tambahan setelah kamera. READ COMMITTED dan lock_timeout 5 detik digunakan untuk transaksi gateway.

Periode dipertahankan oleh layanan lifecycle, dengan partial UNIQUE satu periode terbuka dan FK gabungan period/camera pada deteksi. SQL langsung tetap harus mematuhi aturan layanan. Tidak ada trigger aplikasi tambahan yang mengklaim menjamin seluruh overlap/atribusi lintas tabel.

Tes gateway memakai HTTP kernel dan proses PHP/PostgreSQL terpisah di DB acak owned runner. Tidak sama dengan integrasi service model nyata, uji browser, uji perangkat, atau verifikasi produksi.


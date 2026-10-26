<?php

return [
    // Props dan request privat tidak boleh direkam atau dibaca melalui endpoint debug.
    'devtools' => ['enabled' => false],
    // Web Crypto memerlukan HTTPS/loopback; HTTP domain lokal Herd tetap dapat digunakan.
    // Di luar local, wajib gunakan HTTPS agar riwayat akun dapat dienkripsi.
    'history' => ['encrypt' => (bool) env('INERTIA_ENCRYPT_HISTORY', env('APP_ENV', 'production') !== 'local')],
];

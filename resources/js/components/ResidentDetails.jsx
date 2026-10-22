export default function ResidentDetails({ resident }) {
    const birthDate = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(`${resident.birth_date}T00:00:00`));
    return (
        <section className="mt-8 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
            <h2 className="text-lg font-semibold">Biodata lansia</h2>
            <dl className="mt-6 grid gap-6 text-sm sm:grid-cols-2">
                <div><dt className="text-slate-500">Nama lengkap</dt><dd className="mt-2 break-words font-semibold">{resident.name}</dd></div>
                <div><dt className="text-slate-500">Status</dt><dd className="mt-2 font-semibold">{resident.is_active ? 'Aktif' : 'Nonaktif'}</dd></div>
                <div><dt className="text-slate-500">Tanggal lahir</dt><dd className="mt-2 font-semibold">{birthDate}</dd></div>
                <div><dt className="text-slate-500">Usia saat ini</dt><dd className="mt-2 font-semibold">{resident.age} tahun</dd></div>
            </dl>
            {resident.family && <div className="mt-8 border-t border-slate-100 pt-6"><h3 className="text-sm font-semibold">Keluarga penanggung jawab</h3><p className="mt-3 text-sm">{resident.family.name}</p><p className="mt-1 break-all text-sm text-slate-500">{resident.family.email}</p>{!resident.family.is_active && <p className="mt-3 text-sm text-amber-800">Akun keluarga nonaktif. Hubungan keluarga tetap tersimpan.</p>}</div>}
        </section>
    );
}

import FormField from './FormField';
import { ManagementControl } from './Management/ManagementForm';

export default function RoomFields({ form, classes, room = null }) {
    const cannotChangeClass = Boolean(room && (room.has_stay_history || room.has_camera));
    const current = room?.service_class.id;
    const changingClass = Boolean(room && form.data.service_class_id !== current);
    const selected = classes.find((item) => item.id === form.data.service_class_id);
    return (
        <>
            <FormField id="code" label="Kode kamar" required maxLength={40} value={form.data.code} onChange={(event) => form.setData('code', event.target.value)} error={form.errors.code} hint="Gunakan kode kamar yang unik." />
            <FormField id="name" label="Nama kamar" required maxLength={255} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} />
            <div className="space-y-2">
                <label htmlFor="service_class_id" className="ui-field-label">Kelas layanan <span className="ui-field-required" aria-hidden="true">Wajib</span></label>
                <ManagementControl field="service_class_id" as="select" id="service_class_id" required disabled={cannotChangeClass} value={form.data.service_class_id}
                    onChange={(event) => form.setData({ ...form.data, service_class_id: event.target.value, ...(room ? { confirm_class_change: false } : {}) })}
                    aria-invalid={Boolean(form.errors.service_class_id)} aria-describedby="class-help"
                >
                    <option value="">Pilih kelas layanan</option>
                    {classes.map((item) => <option key={item.id} value={item.id} disabled={!item.is_active && item.id !== current}>{item.name}{!item.is_active ? ' (nonaktif)' : ''}</option>)}
                </ManagementControl>
                <p id="class-help" className="text-xs leading-6 text-ui-text-secondary">{cannotChangeClass ? 'Kelas dipertahankan karena kamar memiliki riwayat masa tinggal atau kamera tertaut.' : 'Penetapan kelas baru hanya dapat menggunakan kelas aktif.'}</p>
                {selected && <p className="text-sm text-ui-neutral">Jenis kamar: {selected.room_type === 'private' ? 'Pribadi / VIP' : 'Bersama'}</p>}
            </div>
            <FormField id="capacity" label="Kapasitas penghuni" type="number" required min={1} max={2147483647} step={1} value={form.data.capacity} onChange={(event) => form.setData('capacity', event.target.value)} error={form.errors.capacity} hint={`Kamar VIP harus berkapasitas tepat 1. Kamar bersama memiliki kapasitas positif.${room ? ` Penghuni aktif saat ini: ${room.active_occupancy}.` : ''}`} />
            {changingClass && <div className="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                <label className="ui-management-choice"><ManagementControl field="confirm_class_change" type="checkbox" checked={form.data.confirm_class_change} onChange={(event) => form.setData('confirm_class_change', event.target.checked)} /><span>Saya mengonfirmasi perubahan kelas kamar yang belum memiliki stay atau kamera.</span></label>
                {form.errors.confirm_class_change && <p role="alert" className="ui-management-note ui-management-note--danger">{form.errors.confirm_class_change}</p>}
            </div>}
            {form.errors.current_service_class_id && <p role="alert" className="ui-management-note ui-management-note--danger">{form.errors.current_service_class_id}</p>}
        </>
    );
}

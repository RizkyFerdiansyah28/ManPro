<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\AvailableAccountEmail;
use App\Support\AccountPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class BootstrapAdmin extends Command
{
    protected $signature = 'silansia:bootstrap-admin';

    protected $description = 'Membuat Admin awal secara interaktif tanpa kredensial bawaan';

    public function handle(): int
    {
        if (! app()->runningInConsole() || ! $this->input->isInteractive()) {
            $this->error('Bootstrap Admin harus dijalankan secara interaktif melalui terminal.');

            return self::FAILURE;
        }
        try {
            if (User::query()->where('role', 'admin')->exists()) {
                $this->error('Admin awal sudah tersedia. Bootstrap tidak dijalankan ulang.');

                return self::FAILURE;
            }
            $data = [
                'name' => trim((string) $this->ask('Nama Admin')),
                'email' => Str::lower(trim((string) $this->ask('Email Admin'))),
                'password' => $this->secret('Kata sandi (minimal 12 karakter, huruf besar/kecil dan angka)', false),
                'password_confirmation' => $this->secret('Konfirmasi kata sandi', false),
            ];
            $validator = Validator::make($data, [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['bail', 'required', 'string', 'email:rfc', 'max:254', new AvailableAccountEmail],
                'password' => AccountPassword::rules(),
            ], [...AccountPassword::messages(), 'required' => ':attribute wajib diisi.',
                'email' => 'Format email tidak valid.', 'max' => ':attribute terlalu panjang.']);
            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $this->error($message);
                }

                return self::FAILURE;
            }
            DB::transaction(function () use ($data) {
                // Serialisasi bootstrap bersamaan; tidak menghapus atau menimpa akun lama.
                DB::statement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
                if (User::query()->where('role', 'admin')->exists()
                    || User::query()->whereRaw('LOWER(email) = ?', [$data['email']])->exists()) {
                    throw new \RuntimeException('Bootstrap tidak dapat dilanjutkan.');
                }
                $admin = new User;
                $admin->name = $data['name'];
                $admin->email = $data['email'];
                $admin->password = $data['password'];
                $admin->role = 'admin';
                $admin->is_active = true;
                $admin->save();
            });
        } catch (Throwable) {
            // Hindari mencetak exception yang mungkin memuat input sensitif atau SQL.
            $this->error('Bootstrap gagal. Pastikan input tersembunyi tersedia, database siap, dan belum ada Admin.');

            return self::FAILURE;
        }
        $this->info('Admin awal berhasil dibuat. Kredensial tidak ditampilkan.');

        return self::SUCCESS;
    }
}

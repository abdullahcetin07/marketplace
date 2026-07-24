<?php

declare(strict_types=1);

/*
| Yönetici panelindeki Kullanıcı kaynağı (Filament) için etiketler. Yalnızca
| sunum dizeleri — davranış Identity Action'larında yaşar.
|
| @see App\Modules\Identity\Presentation\Filament\Resources\UserResource
*/

return [
    'singular' => 'Kullanıcı',
    'plural' => 'Kullanıcılar',

    'name' => 'Ad',
    'first_name' => 'Ad',
    'last_name' => 'Soyad',
    'email' => 'E-posta',
    'phone' => 'Telefon',
    'type' => 'Tür',
    'status' => 'Durum',
    'status_active' => 'Aktif',
    'status_suspended' => 'Askıya alınmış',
    'two_factor' => '2FA',
    'last_login' => 'Son giriş',
    'registered' => 'Kayıt tarihi',

    'reason' => 'Gerekçe',
    'reason_help' => 'Denetim kaydına yazılır. Bu değişikliğin neden yapıldığını açıklayın.',

    'reset_password' => 'Parolayı sıfırla',
    'disable_two_factor' => '2FA’yı kapat',
];

<?php
/*
 * Golden Partner · event.goldenpartner.com.tr — ÖRNEK AYARLAR
 * Sunucuda bu dosyanın kopyasını "ayarlar.php" adıyla oluşturup gerçek değerleri oraya yazın.
 * ayarlar.php repoya girmez (.gitignore), her yayında korunur. İkisi de tarayıcıya kapalıdır.
 */
return [
    // Talepler bu adres(ler)e e-posta ile gelir. Birden fazlaysa virgülle ayırın.
    'mail_to'   => 'BURAYA@goldenpartner.com.tr',

    // Gönderen adresi: bu alan adında (goldenpartner.com.tr) bir adres olmalı,
    // yoksa e-postalar spam'e düşebilir. Hostinger'da açılmış bir posta kutusu en sağlıklısı.
    'mail_from' => 'noreply@goldenpartner.com.tr',
    'mail_from_name' => 'Golden Partner Etkinlik',

    // Saleson / House of On CRM — Ayarlar → Çalışma alanı → Entegrasyonlar → Lead API
    // sayfasından alınan anahtar. Boş bırakılırsa CRM'e gönderim atlanır.
    'crm_url'     => 'https://app.houseofon.com.tr/api/public/leads',
    'crm_api_key' => '',
    'crm_tags'    => ['izmir-etkinlik', 'aralik-2026'],

    // Etkinlik adı (e-posta konusu ve CRM notunda görünür)
    'event_name'  => 'İzmir Özel Yatırım Etkinliği · 4–5 Aralık 2026',
];

"""GitHub secret'larından sunucuya gidecek ayarlar.php'yi üretir (repoya girmez)."""
import os, sys

def q(v: str) -> str:
    return "'" + v.replace("\\", "\\\\").replace("'", "\\'") + "'"

mail = ",".join(p.strip() for p in os.environ.get("LEAD_MAIL_TO", "").replace(";", ",").split(",") if p.strip())
key = os.environ.get("CRM_API_KEY", "").strip()
out = sys.argv[1]

php = f"""<?php
// Bu dosya GitHub Actions tarafından LEAD_MAIL_TO / CRM_API_KEY secret'larından üretilir.
// Elle yapılan değişiklikler bir sonraki dağıtımda ezilir; değiştirmek için GitHub secret'larını güncelleyin.
return [
    'mail_to'        => {q(mail)},
    'mail_from'      => 'noreply@goldenpartner.com.tr',
    'mail_from_name' => 'Golden Partner Etkinlik',
    'crm_url'        => 'https://app.houseofon.com.tr/api/public/leads',
    'crm_api_key'    => {q(key)},
    'crm_tags'       => ['izmir-etkinlik', 'aralik-2026'],
    'event_name'     => 'İzmir Özel Yatırım Etkinliği · 4–5 Aralık 2026',
];
"""
with open(out, "w", encoding="utf-8") as f:
    f.write(php)
print("ayarlar.php üretildi:", "mail=" + ("var" if mail else "yok"), "crm=" + ("var" if key else "yok"))

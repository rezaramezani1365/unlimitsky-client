# این پوشه = کل مخزن GitHub فروشنده

محتوای **`client/`** را به‌عنوان **ریشهٔ مخزن عمومی** روی GitHub بگذار (repo جدا، مثلاً `unlimitsky-client`).

```
unlimitsky-client/          ← ریشه repo (نه داخل پوشه client دیگر)
├── admin/
├── api/
├── bin/
├── install/
├── scripts/install.sh      ← کاربر با curl همین را اجرا می‌کند
├── install-ubuntu.sh
├── config.sample.php
├── README.md
└── wordpress-plugin/
```

## نصب برای فروشنده

```bash
curl -fsSL https://raw.githubusercontent.com/YOUR_USER/unlimitsky-client/main/scripts/install.sh | sudo bash -s -- \
  --port 8082 --admin-pass 'Pass123' --open-firewall \
  --license-url 'https://license.yourdomain.com/api/v1.php' \
  --license-token 'SECRET'
```

فروشنده **vendor** را نمی‌بیند — فقط به API لایسنس وصل می‌شود.

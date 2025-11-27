# DropboxClient PHP Library

Library PHP sederhana untuk berinteraksi dengan **Dropbox API v2**, mendukung:

- OAuth2 Authorization Code Flow
- Refresh Access Token otomatis
- Membuat folder
- Upload file kecil (upload langsung)
- Upload file besar (chunked / session upload)
- Penanganan error terpusat

Note: Library ini dikembangkan berdasarkan logika Dropbox API v1 oleh Stieven Kalengkian
kemudian di optimize dengan bantuan AI khususnya untuk upload file besar dan penanganan error.

---

## 📦 Instalasi

Setelah library ini dipublish di GitHub dan Packagist, instalasi dapat dilakukan melalui composer:

`composer require koyabu/dropboxapi`

---

## 🔧 Konfigurasi Dropbox App

1. Buka https://www.dropbox.com/developers/apps
2. Buat aplikasi baru
3. Aktifkan Permission yang diperlukan
4. Atur Redirect URI, contoh:
   ```
   https://example.com/dropbox/callback
   ```
5. Catat **App Key** dan **App Secret**

---

## 🚀 Cara Menggunakan

### 1. Inisialisasi Class

```php
use Koyabu\Dropbox;

$dropbox = new Dropbox([
    'client_id'     => 'APP_KEY_ANDA',
    'client_secret' => 'APP_SECRET_ANDA',
    'redirect_uri'  => 'https://example.com/dropbox/callback',
    'scope'         => 'files.content.write files.content.read',
]);
```

---

## 🔐 Mendapatkan Authorization Code

Untuk pertama kali, panggil:

```php
echo $dropbox->getAuthUrl();
```

Ini akan menampilkan URL Authorize Dropbox. User akan login dan mendapatkan **authorization code**.

---

## 🔑 Tukar Authorization Code menjadi Token

```php
$response = $dropbox->getAccessToken($_GET['code']);

// Simpan token ke database atau file
$token = json_decode($response, true);
```

Token yang didapat berupa:

```json
{
  "access_token": "...",
  "refresh_token": "...",
  "expires_in": 14400
}
```

---

## 🔁 Refresh Token (Otomatis)

Library secara otomatis akan melakukan refresh token jika token sudah expired.

Pastikan Anda sudah menyimpan:

- access token
- refresh token

Lalu assign:

```php
$dropbox = new Dropbox([
    'app_key' => 'APP_KEY_ANDA',
    'app_secret' => 'APP_SECRET_ANDA',
    'refresh_token' => 'refresh_token_dari_auth',
    'auto_refresh' => true
]);
```

---

# 📁 Membuat Folder

```php
$result = $dropbox->createFolder('/backup/project/');

if (!$result) {
    echo $dropbox->getLastError();
}
```

---

# 📤 Upload File Kecil (< 150 MB)

```php
$dropbox->uploadFile(
    __DIR__ . '/local/file.zip',
    '/backup/file.zip'
);
```

---

# 📤 Upload File Besar (> 150 MB)

Upload dibagi chunk 4 MB (bisa diubah):

```php
$dropbox->uploadLargeFile(
    __DIR__ . '/video.mp4',
    '/backup/video.mp4'
);
```

Jika berhasil, akan mengembalikan metadata file.
Jika gagal, `false` → cek error dengan:

```php
echo $dropbox->getLastError();
```

---

# 📘 Struktur Direktori (Disarankan untuk Publishing)

```
/your-library
│── src/
│   └── Dropbox.php
│── composer.json
│── README.md
│── LICENSE
```

---

# 📝 Catatan Penting

- Pastikan timezone dan server clock sinkron untuk menghindari error token.
- Refresh token harus disimpan secara permanen (database/file).
- Access token sebaiknya disimpan sementara, akan berubah setelah refresh.
- Untuk file besar, library sudah mendukung retry sederhana.

---

# 🤝 Kontribusi

Pull request, bug report, dan perbaikan sangat diterima.

---

# 📄 Lisensi

MIT License atau sesuai kebutuhan Anda.

---

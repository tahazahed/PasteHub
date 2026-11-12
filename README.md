<div dir="rtl">

<a id="فارسی"></a>

# پیست هاب (PasteHub)

انتقال ساده و سریع متن و فایل بین گوشی و کامپیوتر، بدون نیاز به حساب کاربری. محتوا را ارسال کنید، کد پنج‌رقمی بگیرید و همان کد را در دستگاه مقصد وارد کنید.

[English](#english) · [فارسی](#فارسی)

## امکانات

- ارسال متن یا فایل و دریافت آن با یک شناسه تصادفی پنج‌رقمی
- نمایش زنده درصد پیشرفت هنگام بارگذاری فایل
- رابط فارسی، راست‌چین و واکنش‌گرا برای موبایل و دسکتاپ
- حذف دسترسی به محتوا پس از ۲۴ ساعت
- بدون دیتابیس، Composer یا وابستگی JavaScript
- مناسب برای میزبانی اشتراکی PHP و cPanel

## پیش‌نیازها

- PHP 8.0 یا جدیدتر
- افزونه `mbstring`
- هاست Apache/cPanel با امکان نوشتن PHP در پوشه `storage`
- HTTPS برای استفاده امن‌تر

## نصب در cPanel

1. فایل‌های پروژه را در `public_html` یا یک زیرپوشه آن بارگذاری کنید.
2. در cPanel نسخه PHP را روی 8.0 یا جدیدتر قرار دهید و افزونه `mbstring` را فعال کنید.
3. دسترسی نوشتن پوشه `storage` را برای PHP فراهم کنید. معمولاً سطح دسترسی `755` کافی است؛ از `777` استفاده نکنید.
4. دامنه را با HTTPS باز کنید.
5. فایل `config.example.php` را در File Manager کپی و نام نسخه را `config.php` کنید؛ مقدار رمز نمونه را با یک رمز طولانی و تصادفی عوض کنید. فایل `config.php` در Git نادیده گرفته می‌شود و نباید در مخزن عمومی قرار بگیرد.

فایل‌های تا ۱۰۰ مگابایت بدون رمز و فایل‌های بزرگ‌تر فقط با رمز `config.php` پذیرفته می‌شوند. برنامه و فایل `.user.ini` برای فایل‌های بالای ۱۰۰ مگابایت محدودیت داخلی PHP تعیین نمی‌کنند. بعضی هاست‌ها فایل `.user.ini` را نادیده می‌گیرند یا سقف جداگانه‌ای در وب‌سرور دارند. اگر آپلودهای بزرگ کار نکردند، مقادیر `upload_max_filesize` و `post_max_size` و محدودیت‌های وب‌سرور را از cPanel/پشتیبانی هاست بررسی کنید. اعمال `.user.ini` گاهی چند دقیقه طول می‌کشد.

در اولین اجرا، برنامه فایل فهرست انتقال‌ها را در `storage/items.json` می‌سازد. این پوشه با `.htaccess` از دسترسی مستقیم وب بسته شده است. اگر وب‌سرور شما `.htaccess` را پشتیبانی نمی‌کند، دسترسی HTTP به `storage` را از تنظیمات هاست مسدود کنید.

## استفاده

1. در تب **ارسال** متن را بنویسید یا فایل را انتخاب کنید.
2. دکمه ساخت کد را بزنید و شناسه پنج‌رقمی را برای دستگاه مقصد بفرستید.
3. در دستگاه مقصد تب **دریافت** را باز کنید، کد را وارد کنید و متن را کپی یا فایل را دانلود کنید.

فایل تا ۱۰۰ مگابایت بدون رمز ارسال می‌شود؛ برای فایل بزرگ‌تر رمز تنظیم‌شده در `config.php` لازم است. محدودیت نهایی اندازه و زمان انتقال به منابع و سیاست هاست هم بستگی دارد. انقضای ۲۴ساعته در اولین درخواست بعد از زمان انقضا فضای ذخیره‌سازی را پاک‌سازی می‌کند.

## حریم خصوصی و امنیت

PasteHub حساب کاربری ندارد؛ کد پنج‌رقمی تنها راه دریافت محتواست. رمز آپلود بزرگ فقط اجازه ارسال فایل را می‌دهد و جایگزین کد دریافت نیست. محتوا روی سرور ذخیره می‌شود و رمزگذاری سرتاسری ندارد؛ برای همین HTTPS را فعال کنید و از یک رمز تصادفی قوی در `config.php` استفاده کنید.

## ساختار پروژه

```text
.
├── index.php       # رابط کاربری و API
├── .user.ini       # سقف آپلود PHP برای cPanel
├── config.example.php # نمونه تنظیم رمز آپلود؛ config.php خصوصی می‌ماند
├── storage/
│   ├── .htaccess   # مسدودسازی دسترسی مستقیم وب
│   └── index.html
└── README.md
```

</div>

<a id="english"></a>

# PasteHub

Move text and files between your phone and computer with a short, one-time code. No account is required: upload or paste content, share the five-digit code, and retrieve it on the other device.

## Features

- Share text or files with a random five-digit retrieval code
- Live upload progress for files
- Responsive Persian, right-to-left interface for phones and desktops
- Transfers expire after 24 hours
- No database, Composer, or JavaScript dependencies
- Designed for shared PHP hosting and cPanel

## Requirements

- PHP 8.0 or newer
- The `mbstring` PHP extension
- Apache/cPanel hosting with PHP write access to the `storage` directory
- HTTPS for safer use

## Deploy on cPanel

1. Upload the project files to `public_html` or a subdirectory inside it.
2. Select PHP 8.0 or newer in cPanel and enable `mbstring`.
3. Allow PHP to write to the `storage` directory. `755` is usually sufficient; avoid `777` permissions.
4. Open the site over HTTPS.
5. In File Manager, copy `config.example.php` to `config.php` and replace its sample value with a long, random password. `config.php` is ignored by Git and must stay out of the public repository.

Files up to 100 MB do not need a password. Larger files require the password from `config.php`. The application and included `.user.ini` do not set a PHP size limit for files above 100 MB. Some hosts ignore `.user.ini` or enforce separate web-server caps. If large uploads fail, check `upload_max_filesize`, `post_max_size`, and web-server limits with cPanel or your hosting provider. `.user.ini` changes may take a few minutes to apply.

On first run, the app creates `storage/items.json`. The included `.htaccess` blocks direct web access to this directory. If your web server does not honor `.htaccess`, block HTTP access to `storage` through your hosting configuration.

## Usage

1. In the **Send** tab, enter text or choose a file.
2. Select **Create retrieval code** and share the five-digit code with the destination device.
3. On the destination, open the **Receive** tab, enter the code, then copy the text or download the file.

Files up to 100 MB can be sent without a password; larger files require the password configured in `config.php`. The host's resource and transfer policies still apply. Expired transfers are cleaned from storage on the first request after expiration.

## Privacy and security

PasteHub does not use accounts. The five-digit code grants access to a transfer, so share it only with the intended recipient. The large-upload password authorizes sending files and does not replace the retrieval code. Content is stored on the server and is not end-to-end encrypted. Use HTTPS and a strong random password in `config.php`.

## Project structure

```text
.
├── index.php       # User interface and API
├── .user.ini       # PHP upload limits for cPanel
├── config.example.php # Upload-password template; keep config.php private
├── storage/
│   ├── .htaccess   # Blocks direct web access
│   └── index.html
└── README.md
```



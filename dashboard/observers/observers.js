// Observers module minimal JS
(function () {
    'use strict';

    function toEnglishDigits(value) {
        const persian = '۰۱۲۳۴۵۶۷۸۹';
        const arabic = '٠١٢٣٤٥٦٧٨٩';
        return String(value).replace(/[۰-۹٠-٩]/g, d => {
            let idx = persian.indexOf(d);
            if (idx >= 0) return String(idx);
            idx = arabic.indexOf(d);
            return idx >= 0 ? String(idx) : d;
        });
    }

    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
        return null;
    }

    async function checkAuthAndRedirect() {
        try {
            const adminSession = getCookie('adminSession');
            if (!adminSession) {
                window.location.href = '/';
                return false;
            }
            try {
                const session = JSON.parse(decodeURIComponent(adminSession));
                if (session.type !== 'admin') {
                    window.location.href = '/';
                    return false;
                }
                // prefer display name from config (AdminNickName); fall back to session.username
                let displayName = session.username || '';
                try {
                    const resp = await fetch('/API/getConfig.php', { cache: 'no-store' });
                    if (resp && resp.ok) {
                        const cfg = await resp.json();
                        if (cfg && cfg.AdminNickName) {
                            displayName = cfg.AdminNickName;
                        }
                        // update footer university text if available
                        if (cfg && cfg.University) {
                            const ft = document.getElementById('footerText');
                            if (ft) ft.textContent = `نسار - ${cfg.University}`;
                        }
                    }
                } catch (e) {
                    // ignore config errors and keep session username
                }

                const userEl = document.getElementById('adminUsername');
                if (userEl && displayName) userEl.textContent = displayName;
                return true;
            } catch (e) {
                window.location.href = '/';
                return false;
            }
        } catch (e) {
            return false;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        checkAuthAndRedirect().then((ok) => {
            if (ok) {
                // after auth, check locations table
                (async function checkLocations() {
                    try {
                        const resp = await fetch('/API/getLocationsCount.php', { cache: 'no-store' });
                        if (!resp || !resp.ok) return;
                        const data = await resp.json();
                        const count = Number(data.locations || 0);
                        if (count === 0) {
                            await Swal.fire({
                                icon: 'warning',
                                title: 'تعریف مکان‌ها انجام نشده',
                                html: '<div style="text-align: justify;line-height:1.8">جدول مکان‌ها (ساختمان / کلاس) خالی است. لطفاً پایگاه‌داده را از طریق بخش به‌روزرسانی پایگاه داده به‌روز کنید، همچنین از خروجی نرم‌افزار ساد اطمینان حاصل کنید که ساختمان و نام کلاس برای صندلی‌های اختصاص یافته تعریف شده باشند.</div>',
                                confirmButtonText: 'باشه',
                                customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' }
                            });
                            // navigate to dashboard after confirmation
                            window.location.href = '/dashboard';
                        }
                    } catch (e) {
                        // ignore failures silently
                    }
                })();
            }
        });

        const backBtn = document.getElementById('backToDashboardBtn');
        if (backBtn) backBtn.addEventListener('click', () => { window.location.href = '/dashboard'; });

        const goHome = document.getElementById('goHomeBtn');
        if (goHome) goHome.addEventListener('click', () => { window.location.href = '/dashboard'; });

        // Footer click: show about modal similar to dashboard
        const copyrightFooter = document.getElementById('copyrightFooter');
        if (copyrightFooter) {
            copyrightFooter.addEventListener('click', async () => {
                let university = 'دانشگاه پیام نور مرکز بیجار';
                try {
                    const cfgResp = await fetch('/API/getConfig.php', { cache: 'no-store' });
                    if (cfgResp && cfgResp.ok) {
                        const cfg = await cfgResp.json();
                        if (cfg && cfg.University) university = cfg.University;
                    }
                } catch (e) {
                    // ignore
                }

                const VERSION = window.APP_VERSION || '';
                function toPersianDigits(num) { const persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹']; return String(num).replace(/\d/g, d => persianDigits[d]); }
                const escapeHtml = (text) => { const div = document.createElement('div'); div.textContent = text; return div.innerHTML; };

                let countdownInterval;
                Swal.fire({
                    title: 'درباره نِسار',
                    html: `
                <div style="line-height:1.9;font-size:1.05rem;text-align:justify;">
                داشبورد نِسار (نسخه 0.3.0) یک اپلیکیشن تحت‌وب پیشرفته و مدرن است که با استفاده از خروجی‌های نرم‌افزار ساد، به همکاران دانشگاه پیام نور امکان می‌دهد برنامه‌ریزی و مدیریت آزمون‌ها، از جمله زمان‌بندی، تخصیص صندلی و ملزومات اجرایی را به‌صورت یکپارچه و متمرکز انجام داده و در عین حفظ ساختار رسمی در برگزاری، به صرفه‌جویی در زمان و منابع مورد نیاز برای آزمون کمک کند.
                        <br>
            این برنامه به سفارش <span style="color: lime; font-weight: bold;">${escapeHtml(university)}</span> و توسط <a href="https://t.me/RealDream" target="_blank" style="color: gold; font-weight: bold; text-decoration: none; border: none; outline: none;">مهدی حسنی</a> توسعه یافته است.
        </div>
        <div class="swal2-countdown">
            <span class="swal2-countdown-value">${toPersianDigits(30)}</span>
        </div>
    `,
                    timer: 30000,
                    showConfirmButton: false,
                    allowOutsideClick: true,
                    allowEscapeKey: true,
                    customClass: { popup: 'swal2-rtl swal2-glass' },
                    didOpen: () => {
                        const valueEl = Swal.getHtmlContainer()?.querySelector('.swal2-countdown-value');
                        if (valueEl) {
                            let remaining = 30;
                            countdownInterval = setInterval(() => {
                                remaining--;
                                if (remaining < 0) {
                                    clearInterval(countdownInterval);
                                } else {
                                    valueEl.textContent = toPersianDigits(remaining);
                                }
                            }, 1000);
                        }
                    },
                    willClose: () => { if (countdownInterval) clearInterval(countdownInterval); }
                });
            });
        }
    });
})();

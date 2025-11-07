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
                        } else {
                            // Load and render locations list
                            try { await loadLocations(); } catch (e) { /* ignore */ }
                        }
                    } catch (e) {
                        // ignore failures silently
                    }
                })();
            }
        });

        // Helpers for locations listing and editing
        function getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        }

        async function loadLocations() {
            const el = document.getElementById('locationsList');
            if (!el) return;
            el.innerHTML = '<div style="padding:1rem;text-align:center;color:var(--text-muted);">در حال بارگیری...</div>';
            try {
                const r = await fetch('/API/getLocations.php', { cache: 'no-store' });
                if (!r.ok) throw new Error('fetch_failed');
                const j = await r.json();
                const locations = Array.isArray(j.locations) ? j.locations : [];
                renderLocations(locations);
            } catch (err) {
                el.innerHTML = '<div style="padding:1rem;text-align:center;color:var(--text-muted);">خطا در بارگیری مکان‌ها</div>';
            }
        }

        function renderLocations(locations) {
            const container = document.getElementById('locationsList');
            if (!container) return;
            if (!locations.length) {
                container.innerHTML = '<div style="padding:1rem;color:var(--text-muted);">هیچ مکانی یافت نشد.</div>';
                return;
            }

            const rows = locations.map(loc => {
                const id = Number(loc.id || 0);
                const building = loc.building ? loc.building : '';
                const cls = loc.class_name ? loc.class_name : '';
                const rp = Number(loc.required_proctors || 0);
                const title = (building || cls) ? `${building}${building && cls ? ' / ' : ''}${cls}` : 'نامشخص';
                return `
                    <div class="location-row d-flex align-items-center justify-content-between" data-id="${id}" style="padding:0.45rem 0.6rem;border-bottom:1px solid rgba(255,255,255,0.04);">
                        <div style="flex:1;min-width:200px;">
                            <div style="font-weight:700;">${escapeHtml(title)}</div>
                        </div>
                        <div style="width:200px;display:flex;align-items:center;gap:0.5rem;justify-content:flex-end;">
                            <input type="number" min="0" step="1" class="form-control form-control-sm rp-input" value="${rp}" style="width:88px;text-align:center;" />
                            <button class="btn btn-sm btn-success rp-save" title="ذخیره" style="width:34px;padding:0;">✓</button>
                            <button class="btn btn-sm btn-secondary rp-cancel" title="لغو" style="width:34px;padding:0;">✕</button>
                        </div>
                    </div>
                `;
            }).join('');

            container.innerHTML = '<div class="locations-list">' + rows + '</div>';

            // Attach handlers
            container.querySelectorAll('.location-row').forEach(row => {
                const id = row.getAttribute('data-id');
                const input = row.querySelector('.rp-input');
                const saveBtn = row.querySelector('.rp-save');
                const cancelBtn = row.querySelector('.rp-cancel');
                if (!input || !saveBtn || !cancelBtn) return;

                // store original value
                input.dataset.original = input.value;

                input.addEventListener('input', () => {
                    const v = input.value.trim();
                    // require a number
                    const ok = /^\d+$/.test(v);
                    saveBtn.disabled = !ok || Number(v) === Number(input.dataset.original);
                });

                cancelBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    input.value = input.dataset.original || '0';
                    saveBtn.disabled = true;
                });

                saveBtn.addEventListener('click', async (e) => {
                    e.preventDefault();
                    const v = input.value.trim();
                    if (!/^\d+$/.test(v)) {
                        await Swal.fire({ icon: 'error', title: 'خطا', text: 'لطفاً یک مقدار عددی وارد کنید', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
                        return;
                    }
                    const num = Number(v);
                    // Show loading
                    Swal.fire({ title: 'در حال ذخیره...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); }, customClass: { popup: 'swal2-rtl swal2-glass' } });
                    try {
                        const csrf = getCsrfToken();
                        const resp = await fetch('/API/saveLocationProctors.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json; charset=utf-8', 'X-CSRF-Token': csrf },
                            body: JSON.stringify({ id: Number(id), required_proctors: num })
                        });
                        const j = await resp.json();
                        Swal.close();
                        if (resp.ok && j && j.success) {
                            input.dataset.original = String(num);
                            saveBtn.disabled = true;
                            await Swal.fire({ icon: 'success', title: 'ذخیره شد', timer: 1200, showConfirmButton: false, customClass: { popup: 'swal2-rtl swal2-glass' } });
                        } else {
                            await Swal.fire({ icon: 'error', title: 'خطا', text: (j && j.error) ? j.error : 'خطا در ذخیره‌سازی', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
                        }
                    } catch (err) {
                        Swal.close();
                        await Swal.fire({ icon: 'error', title: 'خطا', text: 'خطا در ارتباط با سرور', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
                    }
                });
            });
        }

        function escapeHtml(text) {
            const d = document.createElement('div'); d.textContent = text || ''; return d.innerHTML;
        }

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

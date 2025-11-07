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

    // Global save button handling: enable if any row has an enabled save button
    function updateGlobalSaveButton() {
        const saveAllBtn = document.getElementById('saveAllBtn');
        if (!saveAllBtn) return;
        // Enable/disable SaveAll based on current input values:
        // - All `.rp-input` must be numeric and >= 1
        // - At least one input must have a pending change (value != original)
        // Note: final server-side check is still performed on SaveAll click (prevents console tampering).
        const inputs = Array.from(document.querySelectorAll('.rp-input'));
        if (!inputs.length) { saveAllBtn.disabled = true; return; }

        let allValid = true;
        let anyPending = false;
        for (const input of inputs) {
            const raw = String(input.value || '').trim();
            const normalized = toEnglishDigits(raw).replace(/[^0-9]/g, '');
            if (!/^\d+$/.test(normalized)) { allValid = false; break; }
            const num = Number(normalized || 0);
            if (num < 1) { allValid = false; break; }
            const orig = Number(input.dataset.original || 0);
            if (num !== orig) anyPending = true;
        }

        // Enable only when all inputs are valid and there's at least one pending change.
        saveAllBtn.disabled = !(allValid && anyPending);
    }

    let currentZerosCount = 0;

    async function getZerosCount() {
        try {
            const resp = await fetch('/API/getLocationsZeros.php', { cache: 'no-store' });
            if (!resp.ok) return null;
            const j = await resp.json();
            return Number(j.zeros || 0);
        } catch (e) {
            return null;
        }
    }

    async function checkZerosAndUpdateSaveAll() {
        const saveAllBtn = document.getElementById('saveAllBtn');
        if (!saveAllBtn) return;
        const zeros = await getZerosCount();
        if (zeros === null) {
            // if API failed, be conservative and keep disabled
            saveAllBtn.disabled = true;
            return;
        }
        currentZerosCount = zeros;
        // Enable only when there are no zero-valued locations
        saveAllBtn.disabled = zeros > 0;
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
                // After rendering, check server-side zeros to update SaveAll button
                try { await checkZerosAndUpdateSaveAll(); } catch (e) { /* ignore */ }
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
                    <div class="location-row" data-id="${id}">
                        <div class="location-title">${escapeHtml(title)}</div>
                        <div class="rp-controls">
                            <!-- use text+inputmode so Persian/Arabic digits can be entered; JS normalizes to ASCII digits -->
                            <input type="text" inputmode="numeric" pattern="\\d*" class="rp-input" value="${rp}" aria-label="تعداد مراقبین" />
                            <button class="rp-btn rp-save" title="ذخیره">✓</button>
                            <button class="rp-btn rp-cancel" title="لغو">✕</button>
                            <div class="rp-warning" aria-live="polite" aria-atomic="true"></div>
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

                // store original value (normalized to english digits)
                input.value = toEnglishDigits(input.value || '0');
                input.dataset.original = input.value;

                // Normalize Persian/Arabic digits on input and paste, validate and toggle save button
                function normalizeAndValidate() {
                    const before = input.value || '';
                    // convert persian/aribic digits to ascii digits
                    const normalized = toEnglishDigits(before);
                    // remove any non-digit characters (this prevents letters/symbols)
                    const cleaned = normalized.replace(/[^0-9]/g, '');
                    if (cleaned !== before) {
                        // update the input value while attempting to preserve caret (best-effort)
                        const pos = input.selectionStart || 0;
                        input.value = cleaned;
                        try { input.setSelectionRange(Math.min(pos, input.value.length), Math.min(pos, input.value.length)); } catch (e) { /* ignore */ }
                    }
                    const v = (input.value || '').trim();
                    // require a non-empty number (only digits)
                    const ok = /^\d+$/.test(v);
                    const numericV = Number(v || 0);
                    // show inline warning when value < 1
                    const warningEl = row.querySelector('.rp-warning');
                    if (!ok || numericV < 1) {
                        //if (warningEl) warningEl.textContent = 'مقدار باید عددی و بیشتر یا مساوی ۱ باشد';
                        input.classList.add('rp-invalid');
                    } else {
                        if (warningEl) warningEl.textContent = '';
                        input.classList.remove('rp-invalid');
                    }
                    saveBtn.disabled = !ok || numericV < 1 || numericV === Number(input.dataset.original);
                    // update global save button state
                    updateGlobalSaveButton();
                }

                input.addEventListener('input', normalizeAndValidate);
                input.addEventListener('paste', () => { setTimeout(normalizeAndValidate, 0); });

                cancelBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    input.value = input.dataset.original || '0';
                    saveBtn.disabled = true;
                    const warningEl = row.querySelector('.rp-warning'); if (warningEl) warningEl.textContent = '';
                    input.classList.remove('rp-invalid');
                    updateGlobalSaveButton();
                });

                saveBtn.addEventListener('click', async (e) => {
                    e.preventDefault();
                    const vRaw = input.value.trim();
                    const v = toEnglishDigits(vRaw);
                    input.value = v; // ensure the displayed value is normalized before validation
                        if (!/^\d+$/.test(v)) {
                        // show validation errors as toast
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'error',
                            title: 'لطفاً یک مقدار عددی وارد کنید',
                            showConfirmButton: false,
                            timer: 2500,
                            timerProgressBar: true,
                                customClass: { popup: 'swal2-rtl' }
                        });
                        return;
                    }
                    const num = Number(v);
                        if (num < 1) {
                            Swal.fire({
                                toast: true,
                                position: 'top-end',
                                icon: 'error',
                                title: 'مقدار باید بیشتر یا مساوی ۱ باشد',
                                showConfirmButton: false,
                                timer: 3000,
                                timerProgressBar: true,
                                customClass: { popup: 'swal2-rtl' }
                            });
                            return;
                        }
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
                            const warningEl = row.querySelector('.rp-warning'); if (warningEl) warningEl.textContent = '';
                            input.classList.remove('rp-invalid');
                            // After a successful save, re-check zeros on server and update global save button
                            try { await checkZerosAndUpdateSaveAll(); } catch (e) { /* ignore */ }
                            await Swal.fire({
                                toast: true,
                                position: 'top-end',
                                icon: 'success',
                                title: 'ذخیره شد',
                                showConfirmButton: false,
                                timer: 1200,
                                timerProgressBar: true,
                                customClass: { popup: 'swal2-rtl' }
                            });
                        } else {
                            await Swal.fire({
                                toast: true,
                                position: 'top-end',
                                icon: 'error',
                                title: (j && j.error) ? j.error : 'خطا در ذخیره‌سازی',
                                showConfirmButton: false,
                                timer: 3000,
                                timerProgressBar: true,
                                customClass: { popup: 'swal2-rtl' }
                            });
                        }
                    } catch (err) {
                        Swal.close();
                        await Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'error',
                            title: 'خطا در ارتباط با سرور',
                            showConfirmButton: false,
                            timer: 3000,
                            timerProgressBar: true,
                            customClass: { popup: 'swal2-rtl' }
                        });
                    }
                });
                // Run initial validation to set save button state and warnings
                try { normalizeAndValidate(); } catch (e) { /* ignore */ }
            });
            // After attaching handlers, ensure global Save button reflects current state
            updateGlobalSaveButton();
        }

        function escapeHtml(text) {
            const d = document.createElement('div'); d.textContent = text || ''; return d.innerHTML;
        }

        const backBtn = document.getElementById('backToDashboardBtn');
        if (backBtn) backBtn.addEventListener('click', () => { window.location.href = '/dashboard'; });

        // Show locations card when the locations icon is clicked (button placed near back-to-dashboard)
        // Show a confirmation first: editing counts will change final reports.
        const showLocationsBtn = document.getElementById('showLocationsBtn');
        if (showLocationsBtn) {
            showLocationsBtn.addEventListener('click', async () => {
                const card = document.getElementById('locationsCard');
                if (!card) return;

                // Confirmation modal before revealing the card
                const result = await Swal.fire({
                    title: 'توجه',
                    html: '<div style="text-align:justify;line-height:1.7">با ویرایش تعداد مراقبین در این صفحه، گزارش نهایی مراقبین تغییر خواهد کرد. لطفاً قبل از ادامه از درستی مقادیر اطمینان حاصل کنید.</div>',
                    icon: 'warning',
                    showCancelButton: false,
                    confirmButtonText: 'ادامه، متوجه شدم',
                    allowOutsideClick: true,
                    customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' }
                });

                if (result.isConfirmed || result.isDismissed) {
                    // Un-hide the card and load locations after confirmation (or dismiss)
                    card.style.display = '';
                    try { await loadLocations(); } catch (e) { /* ignore load errors here */ }
                    try { card.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) { /* ignore */ }
                }
            });
        }

        const goHome = document.getElementById('goHomeBtn');
        if (goHome) goHome.addEventListener('click', () => { window.location.href = '/dashboard'; });

        // Global "ذخیره" button (under the card) — enabled when any row has pending changes
        const saveAllBtn = document.getElementById('saveAllBtn');
        if (saveAllBtn) {
            // initial state check via server
            try { checkZerosAndUpdateSaveAll(); } catch (e) { /* ignore */ }

            saveAllBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                if (saveAllBtn.disabled) return;

                // Gather pending changes from the UI (unsaved edits)
                const rows = Array.from(document.querySelectorAll('.location-row'));
                const pending = [];
                rows.forEach(r => {
                    const inp = r.querySelector('.rp-input');
                    if (!inp) return;
                    const orig = Number(inp.dataset.original || 0);
                    const now = Number(toEnglishDigits((inp.value || '').trim()) || 0);
                    if (now !== orig) pending.push({ id: Number(r.getAttribute('data-id')), value: now, input: inp });
                });

                if (!pending.length) {
                    return Swal.fire({ toast: true, position: 'top-end', icon: 'info', title: 'تغییری برای ذخیره وجود ندارد', showConfirmButton: false, timer: 1800, timerProgressBar: true, customClass: { popup: 'swal2-rtl' } });
                }

                // Fetch current server-side locations to see which rows are still zero on the server
                let serverLocations = [];
                try {
                    const resp = await fetch('/API/getLocations.php', { cache: 'no-store' });
                    if (resp && resp.ok) {
                        const j = await resp.json();
                        serverLocations = Array.isArray(j.locations) ? j.locations : [];
                    }
                } catch (e) {
                    // if fetching server state fails, be conservative and block
                    return Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: 'خطا در بررسی وضعیت مکان‌ها', showConfirmButton: false, timer: 2200, timerProgressBar: true, customClass: { popup: 'swal2-rtl' } });
                }

                // IDs that are zero on server
                const serverZeroIds = serverLocations.filter(l => Number(l.required_proctors || 0) === 0).map(l => Number(l.id));

                // Pending positive IDs that will be set >0 by this batch
                const pendingPositiveIds = new Set(pending.filter(p => Number(p.value) > 0).map(p => Number(p.id)));

                // If there exists any server-zero id that is not covered by pendingPositiveIds, block the batch
                const uncovered = serverZeroIds.some(id => !pendingPositiveIds.has(id));
                if (uncovered) {
                    saveAllBtn.disabled = true;
                    return Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: 'برخی مکان‌ها هنوز مقدار ۰ دارند؛ ابتدا آن‌ها را ویرایش و ذخیره کنید', showConfirmButton: false, timer: 3000, timerProgressBar: true, customClass: { popup: 'swal2-rtl' } });
                }

                // Show a loading modal and perform batch save sequentially
                Swal.fire({ title: 'در حال ذخیره‌ی گروهی...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); }, customClass: { popup: 'swal2-rtl swal2-glass' } });

                const csrf = getCsrfToken();
                let failed = 0;
                for (const p of pending) {
                    try {
                        const resp = await fetch('/API/saveLocationProctors.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json; charset=utf-8', 'X-CSRF-Token': csrf },
                            body: JSON.stringify({ id: Number(p.id), required_proctors: Number(p.value) })
                        });
                        const j = await resp.json();
                        if (resp.ok && j && j.success) {
                            p.input.dataset.original = String(p.value);
                        } else {
                            failed++;
                        }
                    } catch (err) {
                        failed++;
                    }
                }

                Swal.close();
                try { await checkZerosAndUpdateSaveAll(); } catch (e) { /* ignore */ }

                if (failed === 0) {
                    await Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'ذخیره‌ی گروهی با موفقیت انجام شد', showConfirmButton: false, timer: 1800, timerProgressBar: true, customClass: { popup: 'swal2-rtl' } });
                    // hide the locations card after a successful batch save
                    try {
                        const card = document.getElementById('locationsCard');
                        if (card) card.style.display = 'none';
                    } catch (e) { /* ignore */ }
                } else {
                    await Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: `خطا در ذخیره ${failed} مورد`, showConfirmButton: false, timer: 3000, timerProgressBar: true, customClass: { popup: 'swal2-rtl' } });
                }
            });
        }

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

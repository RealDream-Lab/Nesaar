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

    function toPersianDigits(num) {
        const persianDigits = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        return String(num).replace(/\d/g, d => persianDigits[d]);
    }

    // Simple HTML escaper usable across this module
    function escapeHtml(text) {
        const d = document.createElement('div');
        d.textContent = text || '';
        return d.innerHTML;
    }

    // Chart instance for session stats (kept across renders)
    let sessionStatsChart = null;
    // Spinner safety timeout id to avoid it remaining visible indefinitely
    let sessionStatsSpinnerTimeout = null;

    // Prefer Vazir font for charts (Chart.js is included before this script)
    try {
        if (typeof Chart !== 'undefined' && Chart.defaults && Chart.defaults.font) {
            Chart.defaults.font.family = "'Vazir', Tahoma, Arial, sans-serif";
            Chart.defaults.font.size = 12;
        }
    } catch (e) { /* ignore if Chart not available yet */ }

    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
        return null;
    }

    async function checkAuthAndRedirect() {
        // show spinner overlay while we fetch and render (useful for large datasets)
        const spinner = document.getElementById('sessionChartSpinner');
        if (spinner) {
            // clear any existing safety timeout
            try { if (sessionStatsSpinnerTimeout) { clearTimeout(sessionStatsSpinnerTimeout); sessionStatsSpinnerTimeout = null; } } catch (e) {}
            spinner.style.display = 'flex';
            // safety: hide spinner after 8s if something goes wrong
            sessionStatsSpinnerTimeout = setTimeout(() => {
                try {
                    if (spinner && spinner.style.display !== 'none') {
                        spinner.style.display = 'none';
                        console.warn('sessionStatsChart spinner hidden by safety timeout');
                    }
                } catch (e) { /* ignore */ }
                sessionStatsSpinnerTimeout = null;
            }, 8000);
        }
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
                            timer: 5000,
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
                                timer: 5000,
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
                                timer: 5000,
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
                    // require explicit confirmation: disable outside click and Escape key
                    allowOutsideClick: false,
                    allowEscapeKey: false,
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
                    return Swal.fire({ toast: true, position: 'top-end', icon: 'info', title: 'تغییری برای ذخیره وجود ندارد', showConfirmButton: false, timer: 5000, timerProgressBar: true, customClass: { popup: 'swal2-rtl' } });
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
                    return Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: 'خطا در بررسی وضعیت مکان‌ها', showConfirmButton: false, timer: 5000, timerProgressBar: true, customClass: { popup: 'swal2-rtl' } });
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
                //Swal.fire({ title: 'در حال ذخیره‌ی گروهی...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); }, customClass: { popup: 'swal2-rtl swal2-glass' } });

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
                    // Show a non-toast loading modal only when SaveAll is invoked and we're about to compute the proctor summary
                    Swal.fire({
                        title: 'ذخیره‌ی گروهی با موفقیت انجام شد',
                        html: '<div style="margin-top:0.4rem;font-weight:600">در حال محاسبه مراقبین مورد نیاز</div>',
                        icon: 'success',
                        showConfirmButton: false,
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading(); },
                        customClass: { popup: 'swal2-rtl swal2-glass' }
                    });

                    // Compute and show proctor summary after successful batch save (the summary function will close the loading modal)
                    try {
                        await computeAndShowProctorSummary();
                    } catch (e) {
                        // ignore summary errors but notify user
                        console.warn('Proctor summary failed', e);
                        Swal.close();
                        await Swal.fire({ toast: true, position: 'top-end', icon: 'warning', title: 'محاسبهٔ خلاصهٔ مراقبین ناموفق بود', showConfirmButton: false, timer: 5000, timerProgressBar: true, customClass: { popup: 'swal2-rtl' } });
                    }

                    // refresh session stats card so it reflects updated required_proctors
                    try { await renderSessionStatsCard(); } catch (e) { console.warn('refresh stats failed', e); }

                    // hide the locations card after a successful batch save
                    try {
                        const card = document.getElementById('locationsCard');
                        if (card) card.style.display = 'none';
                    } catch (e) { /* ignore */ }
                } else {
                    await Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: `خطا در ذخیره ${failed} مورد`, showConfirmButton: false, timer: 5000, timerProgressBar: true, customClass: { popup: 'swal2-rtl' } });
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
                ماژول مراقبینِ داشبورد نِسار (نسخه ${escapeHtml(VERSION)}) یک اپلیکیشن تحت‌وب پیشرفته و مدرن است که با استفاده از خروجی‌های نرم‌افزار ساد، به همکاران دانشگاه پیام نور امکان می‌دهد برنامه‌ریزی و مدیریت آزمون‌ها، از جمله زمان‌بندی، تخصیص صندلی و ملزومات اجرایی را به‌صورت یکپارچه و متمرکز انجام داده و در عین حفظ ساختار رسمی در برگزاری، به صرفه‌جویی در زمان و منابع مورد نیاز برای آزمون کمک کند.
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

        

    // Kick off initial rendering of the session stats card on page load
    (async () => {
        try { await renderSessionStatsCard(); } catch (e) { console.warn('Initial session stats load failed', e); }
    })();

    // Compute per-session proctor needs and show a summary modal
    async function computeAndShowProctorSummary() {
        // Fetch locations (required_proctors per building/class)
        const locResp = await fetch('/API/getLocations.php', { cache: 'no-store' });
        if (!locResp.ok) throw new Error('failed to fetch locations');
        const locJson = await locResp.json();
        const locations = Array.isArray(locJson.locations) ? locJson.locations : [];
        const locMap = new Map();
        locations.forEach(l => {
            const key = `${(l.building||'').trim()}||${(l.class_name||'').trim()}`;
            locMap.set(key, Number(l.required_proctors || 0));
        });

        // Fetch statistics to get future sessions
        const statsResp = await fetch('/API/getStatistics.php', { cache: 'no-store' });
        if (!statsResp.ok) throw new Error('failed to fetch statistics');
        const stats = await statsResp.json();
        const futureExams = Array.isArray(stats.futureExams) ? stats.futureExams : [];

        if (!futureExams.length) {
            await Swal.fire({ title: 'خلاصهٔ آمار مراقبین مورد نیاز', html: '<div style="text-align: right; direction: rtl">جلسهٔ آتی پیدا نشد.</div>', icon: 'info', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass' } });
            return;
        }

        const perSessionTotals = [];
        // Limit concurrency to avoid overwhelming server: process sequentially
        for (const fe of futureExams) {
            const d = fe.exam_date;
            const t = fe.exam_time;
            try {
                const repResp = await fetch(`/API/getNextExamReport.php?exam_date=${encodeURIComponent(d)}&exam_time=${encodeURIComponent(t)}`, { cache: 'no-store' });
                if (!repResp.ok) {
                    // skip this session on error
                    continue;
                }
                const rep = await repResp.json();
                const students = Array.isArray(rep.students) ? rep.students : [];
                const usedKeys = new Set();
                students.forEach(s => {
                    const key = `${(s.building||'').trim()}||${(s.class_name||'').trim()}`;
                    usedKeys.add(key);
                });
                let sessionSum = 0;
                let missingLocations = 0;
                usedKeys.forEach(key => {
                    if (locMap.has(key)) sessionSum += Number(locMap.get(key)) || 0;
                    else missingLocations++;
                });
                perSessionTotals.push({ exam_date: d, exam_time: t, proctors: sessionSum, missingLocations });
            } catch (e) {
                // skip errors per-session but continue
                console.warn('Failed to fetch session', d, t, e);
            }
        }

        if (!perSessionTotals.length) {
            await Swal.fire({ title: 'خلاصهٔ آمار مراقبین مورد نیاز', html: '<div style="text-align: right; direction: rtl">نتیجه‌ای برای جلسات پیدا نشد یا خطا در دریافت جزئیات جلسات وجود دارد.</div>', icon: 'error', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass' } });
            return;
        }

        const proctorsArr = perSessionTotals.map(p => p.proctors);
        const max = Math.max(...proctorsArr);
        const min = Math.min(...proctorsArr);
        const total = proctorsArr.reduce((a, b) => a + b, 0);

    // Format numbers into Persian digits for display
    const fmt = (n) => toPersianDigits(n);

    // Highlight numeric values only (yellow text), preserve spacing and background
    const yellow = '#FFC107';
    const spanTotal = `<span style="color:${yellow};">${fmt(total)}</span>`;
    const spanMax = `<span style="color:${yellow};">${fmt(max)}</span>`;
    const spanMin = `<span style="color:${yellow};">${fmt(min)}</span>`;

    // Build the Persian sentence and keep it as HTML so numbers can be colored
    const html = `<div style="text-align: justify; direction: rtl; font-size:1.02rem">برای پوشش تمام جلسات به مجموع ${spanTotal} مراقب نیاز است. در پُرجمعیت‌ترین جلسه ${spanMax} نفر و در خلوت‌ترین تنها ${spanMin} نفر کافی‌اند.</div>`;

    // Close any loading modal and display the final summary modal using the project's standard Swal classes
    try { Swal.close(); } catch (e) { /* ignore */ }
    await Swal.fire({ icon: 'info', title: 'خلاصهٔ نیاز مراقبین', html, confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
    }

    // Render session stats card (fills #sessionStatsContent) — chart-only (no summary text)
    async function renderSessionStatsCard() {
        const card = document.getElementById('sessionStatsCard');
        const container = document.getElementById('sessionStatsContent');
        if (!card || !container) return;

        // spinner element used to show loading overlay while fetching/rendering
        const spinner = document.getElementById('sessionChartSpinner');
        if (spinner) {
            try { if (sessionStatsSpinnerTimeout) { clearTimeout(sessionStatsSpinnerTimeout); sessionStatsSpinnerTimeout = null; } } catch (e) {}
            spinner.style.display = 'flex';
            // safety: hide spinner after 8s if something goes wrong while fetching many sessions
            sessionStatsSpinnerTimeout = setTimeout(() => {
                try {
                    if (spinner && spinner.style.display !== 'none') {
                        spinner.style.display = 'none';
                        console.warn('sessionStatsChart spinner hidden by safety timeout');
                    }
                } catch (e) { /* ignore */ }
                sessionStatsSpinnerTimeout = null;
            }, 8000);
        }

        try {
            // fetch locations and build map
            const locResp = await fetch('/API/getLocations.php', { cache: 'no-store' });
            if (!locResp.ok) throw new Error('failed to fetch locations');
            const locJson = await locResp.json();
            const locations = Array.isArray(locJson.locations) ? locJson.locations : [];
            const locMap = new Map();
            locations.forEach(l => {
                const key = `${(l.building||'').trim()}||${(l.class_name||'').trim()}`;
                locMap.set(key, Number(l.required_proctors || 0));
            });

            // fetch sessions (prefer all sessions if API provides it)
            const statsResp = await fetch('/API/getStatistics.php', { cache: 'no-store' });
            if (!statsResp.ok) throw new Error('failed to fetch statistics');
            const stats = await statsResp.json();
            const sessions = Array.isArray(stats.allExams) ? stats.allExams : (Array.isArray(stats.futureExams) ? stats.futureExams : []);

            if (!sessions.length) {
                // nothing to show — keep canvas empty
                // ensure chart is destroyed if exists
                if (sessionStatsChart) { try { sessionStatsChart.destroy(); } catch(e){} sessionStatsChart = null; }
                if (spinner) { try { spinner.style.display = 'none'; } catch (e) {} }
                if (sessionStatsSpinnerTimeout) { clearTimeout(sessionStatsSpinnerTimeout); sessionStatsSpinnerTimeout = null; }
                return;
            }

            const perSessionTotals = [];
            // batch fetch session reports with limited concurrency
            const concurrency = 4;
            for (let i = 0; i < sessions.length; i += concurrency) {
                const batch = sessions.slice(i, i + concurrency);
                const promises = batch.map(async (fe) => {
                    const d = fe.exam_date;
                    const t = fe.exam_time;
                    try {
                        const repResp = await fetch(`/API/getNextExamReport.php?exam_date=${encodeURIComponent(d)}&exam_time=${encodeURIComponent(t)}`, { cache: 'no-store' });
                        if (!repResp.ok) {
                            // If server returns non-OK, return a placeholder so session stays in the list
                            return { exam_date: d, exam_time: t, proctors: 0, missingLocations: 0, student_count: Number(fe.student_count || 0), student_ids: [] };
                        }
                        const rep = await repResp.json();
                        const students = Array.isArray(rep.students) ? rep.students : [];
                        // collect student ids for de-duplication across time-slots on same date
                        const studentIds = students.map(s => s.student_id).filter(Boolean);
                        const usedKeys = new Set();
                        students.forEach(s => {
                            const key = `${(s.building||'').trim()}||${(s.class_name||'').trim()}`;
                            usedKeys.add(key);
                        });
                        let sessionSum = 0;
                        let missingLocations = 0;
                        usedKeys.forEach(key => {
                            if (locMap.has(key)) sessionSum += Number(locMap.get(key)) || 0;
                            else missingLocations++;
                        });
                        return { exam_date: d, exam_time: t, proctors: sessionSum, missingLocations, student_count: Number(fe.student_count || students.length || 0), student_ids: studentIds };
                    } catch (e) {
                        console.warn('Failed to fetch session', d, t, e);
                        // Return placeholder on error so we keep session alignment
                        return { exam_date: d, exam_time: t, proctors: 0, missingLocations: 0, student_count: Number(fe.student_count || 0), student_ids: [] };
                    }
                });
                const results = await Promise.all(promises);
                results.forEach(r => { if (r) perSessionTotals.push(r); });
            }

            if (!perSessionTotals.length) {
                // nothing to render — keep chart empty
                if (sessionStatsChart) { try { sessionStatsChart.destroy(); } catch(e){} sessionStatsChart = null; }
                if (spinner) { try { spinner.style.display = 'none'; } catch (e) {} }
                if (sessionStatsSpinnerTimeout) { clearTimeout(sessionStatsSpinnerTimeout); sessionStatsSpinnerTimeout = null; }
                return;
            }

            // Aggregate perSessionTotals into per-day stacked values (one label per date).
            const fmt = (n) => toPersianDigits(n);

            // unique dates (preserve order)
            const dates = [];
            const dateIndex = new Map();
            perSessionTotals.forEach(p => {
                const d = p.exam_date || '';
                if (!dateIndex.has(d)) {
                    dateIndex.set(d, dates.length);
                    dates.push(d);
                }
            });

            console.debug('renderSessionStatsCard: aggregated dates count:', dates.length);


            // unique time slots across sessions (preserve order)
            const times = Array.from(new Set(perSessionTotals.map(p => p.exam_time)));

            console.debug('renderSessionStatsCard: time slots found:', times);

            // build per-date per-time sums and per-date unique student sets
            const perTimePerDate = {};
            times.forEach(t => { perTimePerDate[t] = new Array(dates.length).fill(0); });
            const studentsPerDate = new Array(dates.length).fill(0);
            const studentIdSets = new Array(dates.length).fill(null).map(() => new Set());

            perSessionTotals.forEach(p => {
                const d = p.exam_date || '';
                const t = p.exam_time || '';
                const di = dateIndex.get(d);
                // accumulate proctors
                if (typeof perTimePerDate[t] !== 'undefined') {
                    perTimePerDate[t][di] += Number(p.proctors || 0);
                }
                // accumulate student ids (if available) or fallback to student_count (will sum later)
                if (Array.isArray(p.student_ids) && p.student_ids.length) {
                    p.student_ids.forEach(id => { if (id) studentIdSets[di].add(String(id)); });
                } else if (Number(p.student_count || 0) > 0) {
                    // If no IDs, add a placeholder count in a separate accumulator; we'll sum counts per date
                    // Use a special set to track numeric counts by adding synthetic ids to avoid double-counting across sessions.
                    // Simpler: keep a numeric accumulator if IDs unavailable
                    studentIdSets[di].add('__count__' + (Math.random().toString(36).slice(2,8)) + '|' + Number(p.student_count || 0));
                }
            });

            // debug: sizes
            try {
                console.debug('renderSessionStatsCard: dates:', dates);
                console.debug('renderSessionStatsCard: times:', times);
                times.forEach(t => {
                    console.debug('renderSessionStatsCard: perTimePerDate[' + t + '].length=', perTimePerDate[t].length, 'values=', perTimePerDate[t]);
                });
                console.debug('renderSessionStatsCard: studentsPerDate=', studentsPerDate);
            } catch (e) { /* ignore debug failures */ }

            // finalize studentsPerDate: when sets contain synthetic entries, sum their numeric parts; otherwise use set size
            for (let i = 0; i < dates.length; i++) {
                let sum = 0;
                let synthetic = 0;
                studentIdSets[i].forEach(val => {
                    if (typeof val === 'string' && val.startsWith('__count__')) {
                        synthetic += Number(val.split('|')[1] || 0);
                    } else {
                        sum += 1;
                    }
                });
                studentsPerDate[i] = sum + synthetic;
            }

            // labels are the dates (show fully)
            const labels = dates.map(d => escapeHtml(d));

            // Color assignment: if exactly 3 time-slots, make two blue shades and one warm color
            const blue1 = 'rgba(26,111,166,0.95)';
            const blue2 = 'rgba(18,140,205,0.95)';
            const warm = 'rgba(255,193,7,0.95)';
            const red = 'rgba(233,30,99,0.95)';
            const fallbackPalette = [blue1, blue2, 'rgba(0,170,136,0.95)', warm, 'rgba(233,30,99,0.95)', 'rgba(156,39,176,0.95)'];

            const green = 'rgba(76,175,80,0.95)';
            const colorMap = new Map();
            if (times.length === 3) {
                // default: two blues and one warm
                const defaults = [blue1, blue2, warm];
                times.forEach((t, i) => {
                    // if the time is 11 (starts with '11'), assign green regardless
                    if (String(t).trim().startsWith('11')) {
                        colorMap.set(t, green);
                    } else {
                        colorMap.set(t, defaults[i % defaults.length]);
                    }
                });
            } else {
                times.forEach((t, i) => {
                    if (String(t).trim().startsWith('11')) colorMap.set(t, green);
                    else colorMap.set(t, fallbackPalette[i % fallbackPalette.length]);
                });
            }
            // Build per-time color arrays (one color per dataset/time-slot)
            const perTimeColor = {};
            times.forEach((t, i) => { perTimeColor[t] = colorMap.get(t) || fallbackPalette[i % fallbackPalette.length]; });

            // Hide the legend element (we don't need a separate color legend when the chart is self-explanatory)
            const legendEl = document.getElementById('sessionTimeLegend');
            if (legendEl) {
                legendEl.innerHTML = '';
                legendEl.style.display = 'none';
            }

            // Ensure canvas exists (index.php includes a canvas with this id, but be defensive)
            let canvas = document.getElementById('sessionStatsChart');
            if (!canvas) {
                const wrap = document.createElement('div');
                wrap.style.marginTop = '0.6rem';
                wrap.style.height = '520px';
                canvas = document.createElement('canvas');
                canvas.id = 'sessionStatsChart';
                canvas.style.width = '100%';
                canvas.style.height = '520px';
                wrap.appendChild(canvas);
                container.appendChild(wrap);
            }

            // Create or update Chart.js instance
            const ctx = canvas.getContext('2d');
            if (sessionStatsChart) {
                try {
                    // Build stacked datasets per time-slot (one entry per date)
                    const newDatasets = [];
                    times.forEach(t => {
                        newDatasets.push({
                            label: t,
                            data: perTimePerDate[t] || new Array(labels.length).fill(0),
                            backgroundColor: perTimeColor[t],
                            borderRadius: 6,
                            barThickness: 22,
                            maxBarThickness: 44,
                            barPercentage: 0.9,
                            categoryPercentage: 0.85,
                            stack: 'proctors',
                            yAxisID: 'y'
                        });
                    });
                    newDatasets.push({
                        label: 'دانشجویان',
                        data: studentsPerDate,
                        type: 'line',
                        borderColor: 'rgba(233,30,99,0.95)',
                        backgroundColor: 'rgba(233,30,99,0.12)',
                        fill: true,
                        tension: 0.25,
                        pointRadius: 3,
                        yAxisID: 'y1'
                    });

                    sessionStatsChart.data.labels = labels;
                    sessionStatsChart.data.datasets = newDatasets;
                    // ensure bars at the edges are not clipped
                    try { sessionStatsChart.options.scales.x.offset = true; } catch (err) { /* ignore */ }
                    // tooltip formatting
                    sessionStatsChart.options.plugins.tooltip = sessionStatsChart.options.plugins.tooltip || {};
                    sessionStatsChart.options.plugins.tooltip.filter = function(tooltipItem) {
                        const ds = tooltipItem.chart.data.datasets[tooltipItem.datasetIndex];
                        const v = ds.data[tooltipItem.dataIndex];
                        if ((ds.type === 'bar' || ds.yAxisID === 'y') && Number(v) === 0) return false;
                        return true;
                    };
                    sessionStatsChart.options.plugins.tooltip.callbacks = sessionStatsChart.options.plugins.tooltip.callbacks || {};
                    sessionStatsChart.options.plugins.tooltip.callbacks.label = function(ctx) {
                        const ds = ctx.dataset || ctx.chart.data.datasets[ctx.datasetIndex];
                        const v = ds.data[ctx.dataIndex];
                        const label = ds.label || '';
                        return label + ': ' + toPersianDigits(Number(v || 0));
                    };
                    sessionStatsChart.update();
                } catch (e) {
                    try { sessionStatsChart.destroy(); } catch (ignored) {}
                    sessionStatsChart = null;
                }
            }

            if (!sessionStatsChart) {
                // Build stacked datasets per time-slot (one entry per date)
                const datasets = [];
                times.forEach(t => {
                    datasets.push({
                        label: t,
                        data: perTimePerDate[t] || new Array(labels.length).fill(0),
                        backgroundColor: perTimeColor[t],
                        borderRadius: 6,
                        barThickness: 22,
                        maxBarThickness: 44,
                        barPercentage: 0.9,
                        categoryPercentage: 0.85,
                        stack: 'proctors',
                        yAxisID: 'y'
                    });
                });
                datasets.push({
                    label: 'دانشجویان',
                    data: studentsPerDate,
                    type: 'line',
                    borderColor: 'rgba(233,30,99,0.95)',
                    backgroundColor: 'rgba(233,30,99,0.12)',
                    fill: true,
                    tension: 0.25,
                    pointRadius: 3,
                    yAxisID: 'y1'
                });

                // eslint-disable-next-line no-undef
                sessionStatsChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: datasets
                    },
                    options: {
                        plugins: { 
                            legend: { display: true, labels: { boxWidth:12 } },
                            tooltip: {
                                // filter out bar entries with zero value
                                filter: function(tooltipItem) {
                                    const ds = tooltipItem.chart.data.datasets[tooltipItem.datasetIndex];
                                    const v = ds.data[tooltipItem.dataIndex];
                                    if ((ds.type === 'bar' || ds.yAxisID === 'y') && Number(v) === 0) return false;
                                    return true;
                                },
                                callbacks: {
                                    label: function(ctx) {
                                        const ds = ctx.dataset || ctx.chart.data.datasets[ctx.datasetIndex];
                                        const v = ds.data[ctx.dataIndex];
                                        const label = ds.label || '';
                                        return label + ': ' + toPersianDigits(Number(v || 0));
                                    }
                                }
                            }
                        },
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        scales: {
                            x: { offset: true, ticks: { maxRotation: 0, minRotation: 0, autoSkip: false, callback: function(value, index) { return value; } }, grid: { display: false } },
                            y: { beginAtZero: true, position: 'left', grid: { color: 'rgba(0,0,0,0.04)' } },
                            y1: { beginAtZero: true, position: 'right', grid: { display: false } }
                        }
                    }
                });
            }
            // hide spinner after rendering
            if (spinner) { try { spinner.style.display = 'none'; } catch(e) {} }
            if (sessionStatsSpinnerTimeout) { clearTimeout(sessionStatsSpinnerTimeout); sessionStatsSpinnerTimeout = null; }
        } catch (e) {
            // Log full error stack to console to help debugging
            try { console.error('renderSessionStatsCard failed', e && e.stack ? e.stack : e); } catch (err) { /* ignore */ }
            // Show a small inline hint so the user doesn't see a completely blank area
            try {
                if (container) container.innerHTML = '<div style="padding:1rem;color:var(--text-muted);direction:rtl;text-align:right">خطا در بارگذاری نمودار — لطفاً کنسول مرورگر را برای جزئیات بررسی کنید.</div>';
            } catch (err) { /* ignore */ }
            if (sessionStatsChart) { try { sessionStatsChart.destroy(); } catch(e){} sessionStatsChart = null; }
            if (spinner) spinner.style.display = 'none';
            if (sessionStatsSpinnerTimeout) { clearTimeout(sessionStatsSpinnerTimeout); sessionStatsSpinnerTimeout = null; }
            return;
        }
    }
})();

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

    function toEnglishDigits(value) {
        const persian = '۰۱۲۳۴۵۶۷۸۹';
        const arabic = '٠١٢٣٤٥٦٧٨٩';
        return value.split('').map(char => {
            let index = persian.indexOf(char);
            if (index !== -1) return index.toString();
            index = arabic.indexOf(char);
            if (index !== -1) return index.toString();
            return char;
        }).join('');
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

    // CSRF token helper (module scope) - make available to functions declared
    // outside the DOMContentLoaded handler (e.g. computeAndShowProctorSummary)
    function getCsrfToken() {
        try {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        } catch (e) {
            return '';
        }
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

    // Lightweight assignment summary used as a reliable global helper.
    // This simple version only reads persisted ExamsDetil and Proctors counts
    // and fills the assignment card fields. It's deliberately small and
    // placed before DOMContentLoaded so the header button can call it
    // without timing issues or hoisting surprises in different browsers.
    async function loadAssignmentSummary() {
        const daysEl = document.getElementById('assignmentDays');
        const sessionsEl = document.getElementById('assignmentSessions');
        const totalEl = document.getElementById('assignmentTotalRequired');
        const registeredEl = document.getElementById('assignmentRegisteredProctors');
        const perProctorEl = document.getElementById('assignmentSessionsPerProctor');

        try {
            if (daysEl) daysEl.textContent = '...';
            if (sessionsEl) sessionsEl.textContent = '...';
            if (totalEl) totalEl.textContent = '...';
            if (registeredEl) registeredEl.textContent = '...';
            if (perProctorEl) perProctorEl.textContent = '...';
        } catch (e) {}

        // fetch persisted exams detail (counts and sum)
        let exams = [];
        try {
            const r = await fetch('/API/getExamsDetail.php', { cache: 'no-store' });
            if (r && r.ok) {
                const j = await r.json();
                exams = Array.isArray(j.exams) ? j.exams : [];
            }
        } catch (e) { console.warn('getExamsDetail failed', e); }

        // fetch registered proctors count
        let registered = 0;
        try {
            const r = await fetch('/API/getProctors.php', { cache: 'no-store' });
            if (r && r.ok) {
                const j = await r.json();
                const arr = Array.isArray(j.proctors) ? j.proctors : [];
                registered = arr.length;
            }
        } catch (e) { console.warn('getProctors failed', e); }

        // compute summary from persisted ExamsDetil
        let days = 0, sessions = 0, totalRequired = 0;
        if (exams && exams.length) {
            sessions = exams.length;
            const dates = new Set();
            exams.forEach(e => { dates.add((e.exam_date||'').trim()); totalRequired += Number(e.required_proctors || 0); });
            days = dates.size;
        }

        const toPersian = (n) => { try { return toPersianDigits(n); } catch (e) { return String(n); } };

        if (daysEl) daysEl.textContent = days > 0 ? toPersian(days) : '-';
        if (sessionsEl) sessionsEl.textContent = sessions > 0 ? toPersian(sessions) : '-';
        if (totalEl) totalEl.textContent = totalRequired > 0 ? toPersian(totalRequired) : '-';
        if (registeredEl) registeredEl.textContent = toPersian(registered);

        if (perProctorEl) {
            if (registered > 0 && totalRequired > 0) perProctorEl.textContent = toPersian(Math.ceil(totalRequired/registered));
            else perProctorEl.textContent = '-';
        }

        // enable/disable assignment buttons: require at least one session entry and some proctors
        const assignDailyBtn = document.getElementById('assignDailyBtn');
        const assignScatteredBtn = document.getElementById('assignScatteredBtn');
        if (assignDailyBtn) assignDailyBtn.disabled = !(sessions > 0 && registered > 0);
        if (assignScatteredBtn) assignScatteredBtn.disabled = !(sessions > 0 && registered > 0);
        const assignManualBtn = document.getElementById('assignManualBtn');
        if (assignManualBtn) assignManualBtn.disabled = false;
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

        // Helpers for locations listing and editing (module-scoped helpers are used)

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

        // Load and render ExamsDetil rows (per-session proctors)
        async function loadExamsDetail() {
            const container = document.getElementById('examsDetailList');
            const saveAll = document.getElementById('saveExamsDetailAllBtn');
            if (!container) return;
            try {
                container.innerHTML = '<div style="text-align:center;padding:1rem;color:var(--text-muted)">در حال بارگذاری...</div>';
                const resp = await fetch('/API/getExamsDetail.php', { cache: 'no-store' });
                if (!resp.ok) throw new Error('failed');
                const j = await resp.json();
                const exams = Array.isArray(j.exams) ? j.exams : [];
                renderExamsDetail(exams);
                // enable the "تغییر لازم نیست" button so user can proceed without saving
                try { const noChangeBtn = document.getElementById('noChangeNeededBtn'); if (noChangeBtn) noChangeBtn.disabled = false; } catch (e) {}
                // Show a guidance modal after the exams-detail table has loaded
                try {
                    if (exams && exams.length) {
                        await Swal.fire({
                            icon: 'info',
                            title: 'بررسی ظرفیت‌ها',
                            html: `
                                <div style="text-align:justify;direction:rtl;line-height:1.6">
                                    جدول زیر بر اساس ظرفیت اعلام‌شدهٔ مکان‌های برگزاری آزمون تهیه شده است. در صورت نیاز به نیروی کمکی مانند <strong>منشی</strong> یا <strong>رابط</strong> که ممکن است از بین همین مراقبین انتخاب شوند (ابلاغ ثابت و جداگانه ندارند)، لطفاً تعداد افراد کمکی موردنیاز را به مجموع مراقبین هر جلسه اضافه نمایید تا تخصیص نیرو و گزارش‌ها دقیق باشند.
                                    <br><br>
                                    نکته: اعداد واردشده باید نشان‌دهندهٔ <em>تعداد نهایی</em> افراد موردنیاز برای اجرا (مراقبین به‌علاوه رابط/منشی در صورت لزوم) باشند، نه فقط تعداد مراقبین پایه.
                                </div>
                            `,
                            confirmButtonText: 'متوجه شدم',
                            customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' }
                        });
                    }
                } catch (e) { /* ignore modal errors */ }
                if (saveAll) saveAll.disabled = true;
            } catch (e) {
                console.warn('loadExamsDetail failed', e);
                container.innerHTML = '<div style="text-align:center;padding:1rem;color:crimson">خطا در بارگذاری جزئیات جلسات</div>';
                if (saveAll) saveAll.disabled = true;
            }
        }

        function renderExamsDetail(exams) {
            const container = document.getElementById('examsDetailList');
            const saveAll = document.getElementById('saveExamsDetailAllBtn');
            if (!container) return;
            if (!exams.length) {
                container.innerHTML = '<div style="text-align: right; direction: rtl">هیچ داده‌ای برای نمایش وجود ندارد.</div>';
                if (saveAll) saveAll.disabled = true;
                return;
            }

            // Group by date
            const byDate = new Map();
            exams.forEach(e => {
                const d = (e.exam_date || '').trim();
                if (!byDate.has(d)) byDate.set(d, []);
                byDate.get(d).push(e);
            });

            // Sort dates
            const dates = Array.from(byDate.keys()).sort();

            let rowNumber = 1;

            // Build table header. Determine up to 3 canonical times (columns) from all exams
            const uniqueTimes = Array.from(new Set(exams.map(e => (e.exam_time || '').trim()).filter(Boolean))).sort();
            const columnTimes = uniqueTimes.slice(0, 3);
            const colLabels = ['جلسه اول', 'جلسه دوم', 'جلسه سوم'];

            let html = `
                <table class="table" style="direction:rtl;text-align:right;margin:0;">
                    <thead>
                        <tr>
                            <th style="width:60px"></th>
                            <th style="width:220px">تاریخ</th>
            `;
            for (let ci = 0; ci < 3; ci++) {
                const time = columnTimes[ci] || '';
                const suffix = time ? ` (${escapeHtml(time)})` : '';
                html += `<th style="width:160px">${colLabels[ci]}${suffix}</th>`;
            }
            html += `<th style="width:120px">عملیات</th></tr></thead><tbody>`;

            dates.forEach(d => {
                const list = (byDate.get(d) || []).sort((a, b) => (a.exam_time || '').localeCompare(b.exam_time || ''));
                // map sessions into columns by time when possible; fall back to next empty slot
                const slots = [null, null, null];
                list.forEach(sess => {
                    const t = (sess.exam_time || '').trim();
                    let placed = false;
                    const idx = columnTimes.indexOf(t);
                    if (idx >= 0 && idx < 3 && !slots[idx]) {
                        slots[idx] = sess; placed = true;
                    }
                    if (!placed) {
                        for (let k = 0; k < 3; k++) {
                            if (!slots[k]) { slots[k] = sess; placed = true; break; }
                        }
                    }
                });

                html += `<tr data-date="${escapeHtml(d)}">`;
                html += `<td style="vertical-align:middle;text-align:center;">${rowNumber++}</td>`;
                html += `<td style="vertical-align:middle;font-weight:600">${escapeHtml(d)}</td>`;

                for (let s = 0; s < 3; s++) {
                    const session = slots[s];
                    if (!session) {
                        html += `<td style="vertical-align:middle;color:var(--text-muted);">-</td>`;
                    } else {
                        const id = Number(session.id || 0);
                        const rp = Number(session.required_proctors || 0);
                        const sc = Number(session.students_count || 0);
                        html += `
                            <td style="vertical-align:middle">
                                <div style="display:flex;align-items:center;gap:0.6rem;">
                                    <input type="text" inputmode="numeric" pattern="\\d*" class="ep-input rp-input form-control" data-id="${id}" data-time="${escapeHtml(session.exam_time || '')}" value="${toPersianDigits(rp)}" data-original="${rp}" style="max-width:120px;display:inline-block;">
                                    <span style="color:#9AA6B2;font-weight:600;font-size:0.9rem;">${toPersianDigits(sc)} نفر</span>
                                </div>
                            </td>`;
                    }
                }

                // operations: tick (save row) and cross (cancel row)
                html += `
                    <td style="vertical-align:middle">
                        <button class="ep-row-save rp-btn rp-save" title="ذخیره" style="margin-inline-end:6px">✓</button>
                        <button class="ep-row-cancel rp-btn rp-cancel" title="لغو">✕</button>
                    </td>`;

                html += `</tr>`;
            });

            html += `</tbody></table>`;
            container.innerHTML = html;

            // Attach handlers similar to locations' rp-input behavior
            const rows = container.querySelectorAll('tbody tr');

            function updateSaveAllState() {
                if (!saveAll) return;
                let anyPending = false;
                let allValid = true;
                container.querySelectorAll('.ep-input').forEach(inp => {
                    const orig = Number(inp.dataset.original || 0);
                    const vStr = toEnglishDigits((inp.value || '').trim()).replace(/[^0-9]/g, '');
                    const now = Number(vStr || 0);
                    if (now !== orig) anyPending = true;
                    if (!/^\d+$/.test(vStr) || now < 1) allValid = false;
                });
                saveAll.disabled = !(anyPending && allValid);
            }

            rows.forEach(row => {
                const inputs = Array.from(row.querySelectorAll('.ep-input'));
                const saveBtn = row.querySelector('.ep-row-save');
                const cancelBtn = row.querySelector('.ep-row-cancel');

                // row-level save state: enable save button only when this row has pending
                // changes and all pending values are valid (>=1)
                function updateRowSaveState() {
                    if (!saveBtn) return;
                    let anyPending = false;
                    let allPendingValid = true;
                    inputs.forEach(inp => {
                        const orig = Number(inp.dataset.original || 0);
                        const vStr = toEnglishDigits((inp.value || '').trim()).replace(/[^0-9]/g, '');
                        const now = Number(vStr || 0);
                        if (now !== orig) {
                            anyPending = true;
                            if (!/^\d+$/.test(vStr) || now < 1) allPendingValid = false;
                        }
                    });
                    saveBtn.disabled = !(anyPending && allPendingValid);
                }

                inputs.forEach(inp => {
                    // normalize displayed value to persian digits but keep original stored as english
                    try { inp.value = toPersianDigits(Number(inp.dataset.original || 0)); } catch (e) {}

                    function normalizeAndValidate() {
                        const before = inp.value || '';
                        const normalized = toEnglishDigits(before);
                        const cleaned = normalized.replace(/[^0-9]/g, '');
                        if (cleaned !== before) {
                            const pos = inp.selectionStart || 0;
                            inp.value = cleaned ? toPersianDigits(Number(cleaned)) : '';
                            try { inp.setSelectionRange(Math.min(pos, inp.value.length), Math.min(pos, inp.value.length)); } catch (e) {}
                        }
                        const vEng = toEnglishDigits(inp.value || '');
                        const cleanedEng = vEng.replace(/[^0-9]/g, '');
                        const ok = /^\d+$/.test(cleanedEng);
                        const numericV = Number(cleanedEng || 0);
                        // disallow zero or empty values for saving
                        if (!ok || numericV < 1) {
                            inp.classList.add('rp-invalid');
                        } else {
                            inp.classList.remove('rp-invalid');
                        }
                        updateRowSaveState();
                        updateSaveAllState();
                    }

                    inp.addEventListener('input', normalizeAndValidate);
                    inp.addEventListener('paste', () => { setTimeout(normalizeAndValidate, 0); });
                });

                if (cancelBtn) {
                    cancelBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        // restore originals for inputs in this row
                        inputs.forEach(inp => {
                            inp.value = toPersianDigits(Number(inp.dataset.original || 0));
                            inp.classList.remove('rp-invalid');
                        });
                        try { updateRowSaveState(); } catch (e) {}
                        updateSaveAllState();
                    });
                }

                if (saveBtn) {
                    saveBtn.addEventListener('click', async (e) => {
                        e.preventDefault();
                        // gather pending inputs in this row
                        const pending = [];
                        inputs.forEach(inp => {
                            const orig = Number(inp.dataset.original || 0);
                            const now = Number(toEnglishDigits((inp.value || '').trim()) || 0);
                            const id = Number(inp.dataset.id || 0);
                            if (now !== orig && id > 0) pending.push({ id, value: now, input: inp });
                        });
                        if (!pending.length) return Swal.fire({ toast: true, position: 'top-end', icon: 'info', title: 'تغییری برای ذخیره وجود ندارد', showConfirmButton: false, timer: 2000, customClass: { popup: 'swal2-rtl' } });

                        // validate pending values (no zeros allowed)
                        for (const p of pending) {
                            if (Number(p.value) < 1) {
                                saveBtn.disabled = false;
                                return Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: 'مقادیر باید عدد صحیح و بزرگتر یا مساوی ۱ باشند', showConfirmButton: false, timer: 3500, customClass: { popup: 'swal2-rtl' } });
                            }
                        }

                        saveBtn.disabled = true;
                        const csrf = getCsrfToken();
                        let failed = 0;
                        for (const p of pending) {
                            try {
                                const resp = await fetch('/API/saveExamsDetailRow.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json; charset=utf-8', 'X-CSRF-Token': csrf },
                                    body: JSON.stringify({ id: p.id, required_proctors: Number(p.value) })
                                });
                                const j = await resp.json();
                                if (resp.ok && j && j.success) {
                                    p.input.dataset.original = String(p.value);
                                    p.input.value = toPersianDigits(Number(p.value));
                                } else {
                                    failed++;
                                }
                            } catch (err) { failed++; }
                        }
                        saveBtn.disabled = false;
                        if (failed === 0) {
                            updateSaveAllState();
                            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'ذخیره شد', showConfirmButton: false, timer: 1600, customClass: { popup: 'swal2-rtl' } });
                        } else {
                            Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: `خطا در ذخیره ${failed} مورد`, showConfirmButton: false, timer: 3000, customClass: { popup: 'swal2-rtl' } });
                        }
                    });
                }
                // initialize row save button state
                try { updateRowSaveState(); } catch (e) {}
            });

            // Batch save (all rows)
            if (saveAll) {
                saveAll.disabled = true;
                saveAll.addEventListener('click', async (e) => {
                    e.preventDefault();
                    if (saveAll.disabled) return;
                    const pending = [];
                    container.querySelectorAll('.ep-input').forEach(inp => {
                        const orig = Number(inp.dataset.original || 0);
                        const vStr = toEnglishDigits((inp.value || '').trim()).replace(/[^0-9]/g, '');
                        const now = Number(vStr || 0);
                        const id = Number(inp.dataset.id || 0);
                        if (now !== orig && id > 0) pending.push({ id, value: now, input: inp });
                    });
                    if (!pending.length) return Swal.fire({ toast: true, position: 'top-end', icon: 'info', title: 'تغییری برای ذخیره وجود ندارد', showConfirmButton: false, timer: 2000, customClass: { popup: 'swal2-rtl' } });

                    // validate pending values (no zeros allowed)
                    for (const p of pending) {
                        if (Number(p.value) < 1) {
                            return Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: 'مقادیر باید بزرگتر یا مساوی ۱ باشند', showConfirmButton: false, timer: 3500, customClass: { popup: 'swal2-rtl' } });
                        }
                    }

                    const csrf = getCsrfToken();
                    let failed = 0;
                    for (const p of pending) {
                        try {
                            const resp = await fetch('/API/saveExamsDetailRow.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json; charset=utf-8', 'X-CSRF-Token': csrf },
                                body: JSON.stringify({ id: p.id, required_proctors: Number(p.value) })
                            });
                            const j = await resp.json();
                            if (resp.ok && j && j.success) {
                                p.input.dataset.original = String(p.value);
                                p.input.value = toPersianDigits(Number(p.value));
                            } else { failed++; }
                        } catch (err) { failed++; }
                    }
                    if (failed === 0) {
                        saveAll.disabled = true;
                        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'تمامی تغییرات ذخیره شد', showConfirmButton: false, timer: 2500, customClass: { popup: 'swal2-rtl' } });
                        // After successful batch save, reveal the Proctors card as requested
                        try {
                            showOnlyCard('proctorsCard');
                            await loadProctors();
                            try { const target = document.getElementById('proctorsCard'); if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
                        } catch (e) { console.warn('Failed to open proctors card after saving exams detail', e); }
                    } else {
                        Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: `خطا در ذخیره ${failed} مورد`, showConfirmButton: false, timer: 3500, customClass: { popup: 'swal2-rtl' } });
                    }
                });
            }
        }

        // Note: use the module-scoped `escapeHtml` declared near the top of this file

        // Card manager: ensures only one module card is visible at a time.
        // Pass a cardId (string) to show that card; pass null to hide all.
        //خیلی مهم خیلی مهم خیلی مهم برای نمایش فقط یک کارت
        function showOnlyCard(cardId) {
            try {
                const cards = Array.from(document.querySelectorAll('.dashboard-card.module-card'));
                let anyVisible = false;
                cards.forEach(c => {
                    try {
                        if (!cardId) {
                            // hide
                            try { c.classList.remove('show-card'); } catch (e) {}
                            c.style.display = 'none';
                        } else if (c.id === cardId) {
                            // show: set display first, then add animation class
                            c.style.display = '';
                            // force reflow then add class to trigger transition
                            try { void c.offsetHeight; } catch (e) { /* ignore */ }
                            try { c.classList.add('show-card'); } catch (e) { /* ignore */ }
                            anyVisible = true;
                        } else {
                            // hide other cards
                            try { c.classList.remove('show-card'); } catch (e) {}
                            c.style.display = 'none';
                        }
                    } catch (e) { /* ignore per-card errors */ }
                });

                // If after the operation no card is visible, show the stats card so
                // the page is never left empty. Render it asynchronously.
                if (!anyVisible) {
                    try {
                        const stats = document.getElementById('sessionStatsCard');
                        if (stats) {
                            stats.style.display = '';
                            try { void stats.offsetHeight; } catch (e) {}
                            try { stats.classList.add('show-card'); } catch (e) {}
                            try { renderSessionStatsCard().catch(() => {}); } catch (e) { /* ignore */ }
                        }
                    } catch (e) { /* ignore */ }
                }
            } catch (e) { /* ignore */ }
        }

        const backBtn = document.getElementById('backToDashboardBtn');
        if (backBtn) backBtn.addEventListener('click', () => { window.location.href = '/dashboard'; });

        // Reusable flow to reveal the locations card with confirmation, load data and scroll to it.
        async function revealLocationsFlow(e) {
            try { if (e && typeof e.preventDefault === 'function') e.preventDefault(); } catch (err) { /* ignore */ }
            const card = document.getElementById('locationsCard');
            if (!card) return;

            const result = await Swal.fire({
                title: 'توجه',
                html: '<div style="text-align:justify;line-height:1.7">با ویرایش تعداد مراقبین در این صفحه، گزارش نهایی مراقبین تغییر خواهد کرد. لطفاً قبل از ادامه از درستی مقادیر اطمینان حاصل کنید. پس از ذخیره و تائید، این صفحه دیگر نمایش نخواهد یافت و شما برای بازگشت به شرایط قبلی ملزم به به‌روز‌رسانی دیتابیس خواهید شد.</div>',
                icon: 'warning',
                showCancelButton: false,
                confirmButtonText: 'ادامه، متوجه شدم',
                // require explicit confirmation: disable outside click and Escape key
                allowOutsideClick: false,
                allowEscapeKey: false,
                customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' }
            });

            if (result.isConfirmed || result.isDismissed) {
                // Show only the locations card (this will hide any other module cards)
                showOnlyCard('locationsCard');
                try { await loadLocations(); } catch (e) { /* ignore load errors here */ }
                try { const target = document.getElementById('locationsCard'); if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) { /* ignore */ }
            }
        }

        // Attach the flow to the header button (if present)
        const showLocationsBtn = document.getElementById('showLocationsBtn');
        if (showLocationsBtn) showLocationsBtn.addEventListener('click', revealLocationsFlow);

        // Wire the stats header button: show the session stats card and render the chart when clicked
        const showStatsBtn = document.getElementById('showStatsBtn');
        if (showStatsBtn) {
            showStatsBtn.addEventListener('click', async (e) => {
                try { if (e && typeof e.preventDefault === 'function') e.preventDefault(); } catch (err) {}
                const card = document.getElementById('sessionStatsCard');
                if (!card) return;
                // Show only the stats card (this will hide any other module cards)
                showOnlyCard('sessionStatsCard');
                try { await renderSessionStatsCard(); } catch (err) { console.warn('renderSessionStatsCard failed', err); }
                try { const target = document.getElementById('sessionStatsCard'); if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) { /* ignore */ }
            });
        }
        // Header button for exams detail card
        const showExamsBtn = document.getElementById('showExamsDetailBtn');
        if (showExamsBtn) {
            showExamsBtn.addEventListener('click', async (e) => {
                try { if (e && typeof e.preventDefault === 'function') e.preventDefault(); } catch (err) { /* ignore */ }
                const card = document.getElementById('examsDetailCard');
                if (!card) return;
                showOnlyCard('examsDetailCard');
                try { await loadExamsDetail(); } catch (err) { console.warn('loadExamsDetail failed', err); }
            });
        }
        // Header button for proctors card
        const showProctorsBtn = document.getElementById('showProctorsBtn');
        if (showProctorsBtn) {
            showProctorsBtn.addEventListener('click', async (e) => {
                try { if (e && typeof e.preventDefault === 'function') e.preventDefault(); } catch (err) { /* ignore */ }
                const card = document.getElementById('proctorsCard');
                if (!card) return;
                showOnlyCard('proctorsCard');
                try { await loadProctors(); } catch (err) { console.warn('loadProctors failed', err); }
            });
        }

        // Header button for assignment card
        const showAssignmentBtn = document.getElementById('showAssignmentBtn');
        if (showAssignmentBtn) {
            showAssignmentBtn.addEventListener('click', async (e) => {
                try { if (e && typeof e.preventDefault === 'function') e.preventDefault(); } catch (err) { /* ignore */ }
                const card = document.getElementById('assignmentCard');
                if (!card) return;
                showOnlyCard('assignmentCard');
                try { await loadAssignmentSummary(); } catch (err) { console.warn('loadAssignmentSummary failed', err); }
            });
        }

        // "تغییر لازم نیست، ادامه" button: reveal Proctors card without saving
        const noChangeNeededBtn = document.getElementById('noChangeNeededBtn');
        if (noChangeNeededBtn) {
            noChangeNeededBtn.addEventListener('click', async (e) => {
                try { if (e && typeof e.preventDefault === 'function') e.preventDefault(); } catch (err) {}
                try {
                    showOnlyCard('proctorsCard');
                    await loadProctors();
                    try { const target = document.getElementById('proctorsCard'); if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
                } catch (e) { console.warn('noChangeNeeded flow failed', e); }
            });
            // Start disabled; will be enabled when exams detail is loaded
            noChangeNeededBtn.disabled = true;
        }

        // Finish introduction button: open assignment card when enough proctors
        const finishProctorsBtn = document.getElementById('finishProctorsBtn');
        if (finishProctorsBtn) {
            finishProctorsBtn.addEventListener('click', async (e) => {
                try { if (e && typeof e.preventDefault === 'function') e.preventDefault(); } catch (err) { /* ignore */ }
                // double-check counts before opening assignment card
                try {
                    const [pResp, eResp] = await Promise.all([
                        fetch('/API/getProctors.php', { cache: 'no-store' }),
                        fetch('/API/getExamsDetail.php', { cache: 'no-store' })
                    ]);
                    const pJson = (pResp && pResp.ok) ? await pResp.json() : { proctors: [] };
                    const eJson = (eResp && eResp.ok) ? await eResp.json() : { exams: [] };
                    const registered = Array.isArray(pJson.proctors) ? pJson.proctors.length : 0;
                    const exams = Array.isArray(eJson.exams) ? eJson.exams : [];
                    // Use the same metric as the proctors stats: compare against the
                    // maximum per-session required_proctors (not the sum). This makes
                    // the finish button and the warning consistent with the green/red
                    // status shown in the header stats.
                    const maxRequired = exams.length ? Math.max(...exams.map(e => Number(e.required_proctors || 0))) : 0;
                    if (!(registered >= maxRequired && maxRequired > 0)) {
                        await Swal.fire({
                            title: 'ملاحظۀ کمبود',
                            html: '<div style="text-align:justify;line-height:1.6">تعداد مراقبین ثبت‌شده کمتر از حداکثر موردنیاز در یک جلسه است. لطفاً ابتدا مراقبین لازم را معرفی کنید.</div>',
                            icon: 'warning',
                            confirmButtonText: 'باشه',
                            customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' },
                            width: '520px'
                        });
                        return;
                    }
                    // Ask for a final confirmation: proceeding will prevent returning to
                    // this proctors introduction page without performing a full
                    // database re-sync/update. Require explicit confirmation.
                    const confirmProceed = await Swal.fire({
                        title: 'تأیید نهایی',
                        html: '<div style="text-align:justify;line-height:1.6">با ادامهٔ عملیات، شما دیگر قادر به بازگشت و ویرایش اطلاعات این صفحه نخواهید بود و در صورت نیاز باید تمامی مراحل به‌روزرسانی را از ابتدا انجام دهید. آیا مطمئن هستید که می‌خواهید ادامه دهید؟</div>',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'بله، مطمئنم',
                        cancelButtonText: 'انصراف',
                        customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-danger', cancelButton: 'btn btn-cancel' },
                        width: '520px'
                    });
                    if (!confirmProceed.isConfirmed) {
                        return;
                    }
                    // open assignment card after confirmation
                    showOnlyCard('assignmentCard');
                    try { await loadAssignmentSummary(); } catch (e) { /* ignore */ }
                    try { const target = document.getElementById('assignmentCard'); if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
                } catch (err) {
                    console.warn('finishProctorsBtn flow failed', err);
                }
            });
        }
        // initial sync of finish button state
        try { if (typeof updateFinishProctorsBtn === 'function') updateFinishProctorsBtn(); } catch (e) {}

        // Assignment buttons: show confirmation dialog before proceeding
        const assignDailyBtn = document.getElementById('assignDailyBtn');
        if (assignDailyBtn) {
            assignDailyBtn.addEventListener('click', async (e) => {
                try { if (e && typeof e.preventDefault === 'function') e.preventDefault(); } catch (err) { /* ignore */ }
        const result = await Swal.fire({
            title: 'تأیید عملیات',
            html: '<div style="text-align:justify;line-height:1.6">این عملیات تمامی چینش‌های قبلی را پاک کرده و چینش جدیدی بر اساس حضور روزانه مراقب انجام خواهد داد. این روش زمانی مناسب است که ساختمان‌های برگزاری آزمون از هم دور هستند و مراقبین باید تمام روز را در یک ساختمان مشخص حضور داشته باشند. آیا مطمئن هستید که می‌خواهید ادامه دهید؟</div>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'بله، ادامه می‌دهم',
            cancelButtonText: 'انصراف',
        // colors are handled by button classes; set a fixed width to avoid wrapping
    customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-danger', cancelButton: 'btn btn-cancel' },
    width: '520px'
        });
                if (result.isConfirmed) {
                    // TODO: Implement daily assignment logic here
                    await Swal.fire({
                        title: 'اطلاع',
                        text: 'کد عملیات چینش خودکار بر اساس حضور روزانه مراقب بعداً پیاده‌سازی خواهد شد.',
                        icon: 'info',
                        confirmButtonText: 'باشه',
        customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' },
        width: '520px'
                    });
                }
            });
        }

        const assignScatteredBtn = document.getElementById('assignScatteredBtn');
        if (assignScatteredBtn) {
            assignScatteredBtn.addEventListener('click', async (e) => {
                try { if (e && typeof e.preventDefault === 'function') e.preventDefault(); } catch (err) { /* ignore */ }
        const result = await Swal.fire({
            title: 'تأیید عملیات',
            html: '<div style="text-align:justify;line-height:1.6">این عملیات تمامی چینش‌های قبلی را پاک کرده و چینش جدیدی به صورت پراکنده انجام خواهد داد. این روش زمانی مناسب است که ساختمان‌های برگزاری آزمون از هم دور نبوده و مراقبین در کنار کار مراقبت باید به امور دانشجویان نیز رسیدگی نمایند. آیا مطمئن هستید که می‌خواهید ادامه دهید؟</div>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'بله، ادامه می‌دهم',
            cancelButtonText: 'انصراف',
        // colors are handled by button classes; set a fixed width to avoid wrapping
    customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-danger', cancelButton: 'btn btn-cancel' },
    width: '520px'
        });
                if (result.isConfirmed) {
                    // TODO: Implement scattered assignment logic here
                    await Swal.fire({
                        title: 'اطلاع',
                        text: 'کد عملیات چینش خودکار مراقبین به صورت پراکنده بعداً پیاده‌سازی خواهد شد.',
                        icon: 'info',
                        confirmButtonText: 'باشه',
        customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' },
        width: '520px'
                    });
                }
            });
        }

        const assignManualBtn = document.getElementById('assignManualBtn');
        if (assignManualBtn) {
            assignManualBtn.addEventListener('click', async (e) => {
                try { if (e && typeof e.preventDefault === 'function') e.preventDefault(); } catch (err) { /* ignore */ }
        const result = await Swal.fire({
            title: 'تأیید عملیات',
            html: '<div style="text-align:justify;line-height:1.6">این عملیات تمامی چینش‌های قبلی را پاک کرده و چینش جدیدی به صورت دستی انجام خواهد داد. آیا مطمئن هستید که می‌خواهید ادامه دهید؟</div>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'بله، ادامه می‌دهم',
            cancelButtonText: 'انصراف',
        // colors are handled by button classes; set a fixed width to avoid wrapping
    customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-danger', cancelButton: 'btn btn-cancel' },
    width: '520px'
        });
                if (result.isConfirmed) {
                    // TODO: Implement manual assignment logic here
                    await Swal.fire({
                        title: 'اطلاع',
                        text: 'کد عملیات چینش دستی مراقبین بعداً پیاده‌سازی خواهد شد.',
                        icon: 'info',
                        confirmButtonText: 'باشه',
        customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' },
        width: '520px'
                    });
                }
            });
        }

        // When computeAndShowProctorSummary persists ExamsDetil it will emit
        // the 'examsDetailSaved' event; listen and reveal the exams detail card.
        document.addEventListener('examsDetailSaved', async () => {
            try {
                const card = document.getElementById('examsDetailCard');
                if (!card) return;
                showOnlyCard('examsDetailCard');
                try { await loadExamsDetail(); } catch (err) { console.warn('loadExamsDetail failed after save', err); }
                try { card.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) { /* ignore */ }
            } catch (e) { /* ignore */ }
        });

        // If the server made the locations card visible on initial page load,
        // trigger the same confirmation flow so the user sees the warning and
        // the card is loaded after confirmation.
        try {
            const card = document.getElementById('locationsCard');
            if (card && window.getComputedStyle(card).display !== 'none') {
                // call after a short timeout so the page finish rendering
                setTimeout(() => { try { revealLocationsFlow(); } catch (e) { /* ignore */ } }, 120);
            }
        } catch (e) { /* ignore */ }

        // Ensure the page is never empty: if no module-card is visible, show the stats card by default.
        try {
            setTimeout(() => {
                try {
                    const cards = Array.from(document.querySelectorAll('.dashboard-card.module-card'));
                    const anyVisible = cards.some(c => {
                        try { return window.getComputedStyle(c).display !== 'none'; } catch (e) { return false; }
                    });
                    if (!anyVisible) {
                        // show stats card to avoid an empty page
                        showOnlyCard('sessionStatsCard');
                        try { renderSessionStatsCard().catch(() => {}); } catch (e) { /* ignore */ }
                    }
                } catch (e) { /* ignore */ }
            }, 180);
        } catch (e) { /* ignore */ }

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
                    let savedFlag = false;
                    try {
                        savedFlag = await computeAndShowProctorSummary();
                    } catch (e) {
                        // ignore summary errors but notify user
                        console.warn('Proctor summary failed', e);
                        Swal.close();
                        await Swal.fire({ toast: true, position: 'top-end', icon: 'warning', title: 'محاسبهٔ خلاصهٔ مراقبین ناموفق بود', showConfirmButton: false, timer: 5000, timerProgressBar: true, customClass: { popup: 'swal2-rtl' } });
                        savedFlag = false;
                    }

                    // refresh session stats card so it reflects updated required_proctors
                    try { await renderSessionStatsCard(); } catch (e) { console.warn('refresh stats failed', e); }

                    // If the per-session details were NOT saved, hide all cards and show stats.
                    // If they were saved, the compute function already dispatched 'examsDetailSaved'
                    // and the listener will reveal the exams detail card — so do not override it.
                    if (!savedFlag) {
                        try { showOnlyCard(null); } catch (e) { /* ignore */ }
                    }
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

        

    // NOTE: Initial automatic rendering of the session stats card was removed.
    // The card is hidden by default; use the header stats button to reveal and render it.

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
        // Prefer a full list when available. Use `allExams` if provided by API,
        // otherwise concatenate past and future exams so we cover all days.
        let examsList = [];
        if (Array.isArray(stats.allExams)) {
            examsList = stats.allExams;
        } else {
            const past = Array.isArray(stats.pastExams) ? stats.pastExams : [];
            const future = Array.isArray(stats.futureExams) ? stats.futureExams : [];
            examsList = past.concat(future);
        }

        if (!examsList.length) {
            await Swal.fire({ title: 'خلاصهٔ آمار مراقبین مورد نیاز', html: '<div style="text-align: right; direction: rtl">جلسه‌ای برای محاسبه پیدا نشد.</div>', icon: 'info', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass' } });
            return;
        }

        const perSessionTotals = [];
        // Limit concurrency to avoid overwhelming server: process sequentially
        for (const fe of examsList) {
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

        // NOTE: persistence of per-session totals moved to after the user sees the
        // computed summary modal. See below where we POST to /API/saveExamsDetail.php.
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

    // After the user has seen/dismissed the summary, persist the per-session totals
    // into the ExamsDetil table and report result to the user.
    try {
        const csrf = getCsrfToken();
        const payload = { sessions: perSessionTotals.map(p => ({ exam_date: p.exam_date, exam_time: p.exam_time, proctors: Number(p.proctors || 0), students_count: Number(p.student_count || 0) })) };
        const saveResp = await fetch('/API/saveExamsDetail.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=utf-8', 'X-CSRF-Token': csrf },
            body: JSON.stringify(payload)
        });
        if (saveResp && saveResp.ok) {
            try {
                const sj = await saveResp.json();
                if (sj && sj.success) {
                    await Swal.fire({ icon: 'success', title: 'اطلاعات آزمون‌ها ذخیره شد', html: `<div style="direction:rtl;text-align:right">اطلاعات تعداد مراقبین هر جلسه با موفقیت در بانک اطلاعاتی ذخیره شد. (${sj.inserted || 0} رکورد)</div>`, confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass' } });
                    // Notify the page that ExamsDetil was updated so the UI can show the details card
                    try { document.dispatchEvent(new CustomEvent('examsDetailSaved')); } catch (e) { /* ignore */ }
                    return true;
                } else {
                    await Swal.fire({ toast: true, position: 'top-end', icon: 'warning', title: 'ذخیرهٔ جزئیات جلسات در بانک اطلاعاتی ناموفق بود', showConfirmButton: false, timer: 4000, timerProgressBar: true, customClass: { popup: 'swal2-rtl' } });
                    return false;
                }
            } catch (e) {
                // parsing error
                await Swal.fire({ toast: true, position: 'top-end', icon: 'warning', title: 'ذخیرهٔ جزئیات جلسات با خطا مواجه شد', showConfirmButton: false, timer: 4000, timerProgressBar: true, customClass: { popup: 'swal2-rtl' } });
            }
        } else {
            await Swal.fire({ toast: true, position: 'top-end', icon: 'warning', title: 'ذخیرهٔ جزئیات جلسات در بانک اطلاعاتی ناموفق بود', showConfirmButton: false, timer: 4000, timerProgressBar: true, customClass: { popup: 'swal2-rtl' } });
        }
    } catch (e) {
        console.warn('Failed to persist ExamsDetil', e);
        try { await Swal.fire({ toast: true, position: 'top-end', icon: 'warning', title: 'ذخیرهٔ جزئیات جلسات در بانک اطلاعاتی ناموفق بود', showConfirmButton: false, timer: 4000, timerProgressBar: true, customClass: { popup: 'swal2-rtl' } }); } catch (ignored) {}
        return false;
    }

    // Load and render the Assignment summary (top of the assignmentCard)
    async function loadAssignmentSummary() {
        const daysEl = document.getElementById('assignmentDays');
        const sessionsEl = document.getElementById('assignmentSessions');
        const totalEl = document.getElementById('assignmentTotalRequired');
        const registeredEl = document.getElementById('assignmentRegisteredProctors');
        const perProctorEl = document.getElementById('assignmentSessionsPerProctor');
        const assignDailyBtn = document.getElementById('assignDailyBtn');
        const assignScatteredBtn = document.getElementById('assignScatteredBtn');
        const assignManualBtn = document.getElementById('assignManualBtn');

        // set loading placeholders
        try { if (daysEl) daysEl.textContent = '...'; if (sessionsEl) sessionsEl.textContent = '...'; if (totalEl) totalEl.textContent = '...'; if (registeredEl) registeredEl.textContent = '...'; if (perProctorEl) perProctorEl.textContent = '...'; } catch (e) {}

        // fetch registered proctors
        let registered = 0;
        try {
            const r = await fetch('/API/getProctors.php', { cache: 'no-store' });
            if (r && r.ok) {
                const j = await r.json();
                const arr = Array.isArray(j.proctors) ? j.proctors : [];
                registered = arr.length;
            }
        } catch (e) { console.warn('getProctors failed', e); }

        // fetch persisted exams detail
        let exams = [];
        try {
            const r = await fetch('/API/getExamsDetail.php', { cache: 'no-store' });
            if (r && r.ok) {
                const j = await r.json();
                exams = Array.isArray(j.exams) ? j.exams : [];
            }
        } catch (e) { console.warn('getExamsDetail failed', e); }

        // calculate summary
        let days = 0, sessions = 0, totalRequired = 0;
        function toPersian(n) { try { return toPersianDigits(n); } catch (e) { return String(n); } }

        if (exams && exams.length) {
            sessions = exams.length;
            const dates = new Set();
            exams.forEach(e => {
                dates.add((e.exam_date || '').trim());
                totalRequired += Number(e.required_proctors || 0);
            });
            days = dates.size;
        } else {
            // Fallback: compute live from locations + statistics when ExamsDetil is empty
            try {
                // fetch locations map
                const locResp = await fetch('/API/getLocations.php', { cache: 'no-store' });
                const locJson = (locResp && locResp.ok) ? await locResp.json() : { locations: [] };
                const locations = Array.isArray(locJson.locations) ? locJson.locations : [];
                const locMap = new Map();
                locations.forEach(l => {
                    const key = `${(l.building||'').trim()}||${(l.class_name||'').trim()}`;
                    locMap.set(key, Number(l.required_proctors || 0));
                });

                // fetch sessions from statistics
                const statsResp = await fetch('/API/getStatistics.php', { cache: 'no-store' });
                const stats = (statsResp && statsResp.ok) ? await statsResp.json() : {};
                const sessionsList = Array.isArray(stats.allExams) ? stats.allExams : (Array.isArray(stats.futureExams) ? stats.futureExams : []);

                if (sessionsList && sessionsList.length) {
                    sessions = sessionsList.length;
                    const datesSet = new Set();
                    // limited concurrency to 4
                    const concurrency = 4;
                    for (let i = 0; i < sessionsList.length; i += concurrency) {
                        const batch = sessionsList.slice(i, i + concurrency);
                        const promises = batch.map(async fe => {
                            const d = fe.exam_date || '';
                            const t = fe.exam_time || '';
                            try {
                                const repResp = await fetch(`/API/getNextExamReport.php?exam_date=${encodeURIComponent(d)}&exam_time=${encodeURIComponent(t)}`, { cache: 'no-store' });
                                if (!repResp || !repResp.ok) return { date: d, proctors: 0 };
                                const rep = await repResp.json();
                                const students = Array.isArray(rep.students) ? rep.students : [];
                                const usedKeys = new Set();
                                students.forEach(s => usedKeys.add(`${(s.building||'').trim()}||${(s.class_name||'').trim()}`));
                                let sessionSum = 0;
                                usedKeys.forEach(k => { if (locMap.has(k)) sessionSum += Number(locMap.get(k)) || 0; });
                                return { date: d, proctors: sessionSum };
                            } catch (e) {
                                console.warn('assignment summary session fetch failed', e);
                                return { date: d, proctors: 0 };
                            }
                        });
                        const results = await Promise.all(promises);
                        results.forEach(r => {
                            if (r) {
                                totalRequired += Number(r.proctors || 0);
                                if (r.date) datesSet.add(r.date);
                            }
                        });
                    }
                    days = datesSet.size;
                }
            } catch (e) {
                console.warn('Fallback assignment summary failed', e);
            }
        }

        // populate UI values
        if (daysEl) daysEl.textContent = (days > 0) ? toPersian(days) : '-';
        if (sessionsEl) sessionsEl.textContent = (sessions > 0) ? toPersian(sessions) : '-';
        if (totalEl) totalEl.textContent = (totalRequired > 0) ? toPersian(totalRequired) : '-';
        if (registeredEl) registeredEl.textContent = toPersian(registered);

        let per = '-';
        if (registered > 0 && totalRequired > 0) {
            per = Math.ceil(totalRequired / registered);
            per = toPersian(per);
        }
        if (perProctorEl) perProctorEl.textContent = per;

        // Enable/disable buttons depending on available data
        const enableAssignment = (sessions > 0) && (registered > 0);
        if (assignDailyBtn) assignDailyBtn.disabled = !enableAssignment;
        if (assignScatteredBtn) assignScatteredBtn.disabled = !enableAssignment;
        if (assignManualBtn) assignManualBtn.disabled = false; // manual mode always allowed

    // keep finish button state in sync with latest counts
    try { if (typeof updateFinishProctorsBtn === 'function') await updateFinishProctorsBtn(); } catch (e) {}

        // wire placeholders for buttons (idempotent)
        try {
            if (assignDailyBtn && !assignDailyBtn._wired) {
                assignDailyBtn.addEventListener('click', async () => {
                    await Swal.fire({ title: 'چینش بر اساس حضور روزانه', html: '<div style="direction:rtl;text-align:right">این دکمه الگوریتم "حضور روزانه" را اجرا خواهد کرد. لطفاً روش دقیق را مشخص کنید تا پیاده‌سازی کنم.</div>', confirmButtonText: 'متوجه شدم', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
                });
                assignDailyBtn._wired = true;
            }
            if (assignScatteredBtn && !assignScatteredBtn._wired) {
                assignScatteredBtn.addEventListener('click', async () => {
                    await Swal.fire({ title: 'چینش پراکنده', html: '<div style="direction:rtl;text-align:right">این دکمه الگوریتم "پراکنده" را اجرا خواهد کرد. لطفاً قوانین را بگویید تا پیاده‌سازی کنم.</div>', confirmButtonText: 'متوجه شدم', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
                });
                assignScatteredBtn._wired = true;
            }
            if (assignManualBtn && !assignManualBtn._wired) {
                assignManualBtn.addEventListener('click', async () => {
                    await Swal.fire({ title: 'چینش دستی', html: '<div style="direction:rtl;text-align:right">در حالت دستی شما پروفایل‌ها را به‌صورت دلخواه تخصیص می‌دهید. آیا می‌خواهید وارد محیط تخصیص دستی شوم؟</div>', confirmButtonText: 'ادامه', showCancelButton: true, customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
                });
                assignManualBtn._wired = true;
            }
        } catch (e) { /* ignore wiring errors */ }
    }
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
            // Prefer persisted per-session proctor data from ExamsDetil when available
            let perSessionTotals = [];
            try {
                const edResp = await fetch('/API/getExamsDetail.php', { cache: 'no-store' });
                if (edResp && edResp.ok) {
                    const edj = await edResp.json();
                    if (Array.isArray(edj.exams) && edj.exams.length) {
                        // Map table rows into the shape expected by the renderer
                        perSessionTotals = edj.exams.map(e => ({
                            exam_date: e.exam_date || '',
                            exam_time: e.exam_time || '',
                            proctors: Number(e.required_proctors || 0),
                            missingLocations: 0,
                            student_count: 0,
                            student_ids: []
                        }));

                        // Enrich persisted sessions with student counts from getStatistics.php
                        try {
                            const statsResp = await fetch('/API/getStatistics.php', { cache: 'no-store' });
                            if (statsResp && statsResp.ok) {
                                const stats = await statsResp.json();
                                const allExams = Array.isArray(stats.allExams) ? stats.allExams : [];
                                // Build a map of exam_date + exam_time to student_count
                                const statsMap = new Map();
                                allExams.forEach(ex => {
                                    const key = `${ex.exam_date || ''}||${ex.exam_time || ''}`;
                                    statsMap.set(key, Number(ex.student_count || 0));
                                });
                                // Enrich perSessionTotals with student_count from statsMap
                                perSessionTotals = perSessionTotals.map(p => {
                                    const key = `${p.exam_date || ''}||${p.exam_time || ''}`;
                                    return Object.assign({}, p, { student_count: statsMap.get(key) || 0 });
                                });
                            }
                        } catch (e) {
                            console.warn('Failed to enrich persisted ExamsDetil rows with student counts from getStatistics', e);
                        }
                    }
                }
            } catch (e) {
                console.warn('Failed to load ExamsDetil, falling back to live computation', e);
            }

            // If no persisted rows, fall back to live computation (existing behavior)
            if (!perSessionTotals.length) {
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

                perSessionTotals = [];
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
                    // rotate x-axis labels vertically
                    sessionStatsChart.options.scales.x.ticks = sessionStatsChart.options.scales.x.ticks || {};
                    sessionStatsChart.options.scales.x.ticks.maxRotation = 90;
                    sessionStatsChart.options.scales.x.ticks.minRotation = 90;
                    // ensure x-axis tick callback returns the exam_date label from the labels array
                    sessionStatsChart.options.scales.x.ticks.callback = function(value, index) { return labels && labels[index] ? labels[index] : value; };
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
                            x: { offset: true, ticks: { maxRotation: 90, minRotation: 90, autoSkip: false, callback: function(value, index) { return labels && labels[index] ? labels[index] : value; } }, grid: { display: false } },
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

    // Proctors management
    async function loadProctors() {
        const container = document.getElementById('proctorsList');
        if (!container) return;
        try {
            container.innerHTML = '<div style="text-align:center;padding:1rem;color:var(--text-muted)">در حال بارگذاری...</div>';
            const resp = await fetch('/API/getProctors.php', { cache: 'no-store' });
            if (!resp.ok) throw new Error('failed');
            const j = await resp.json();
            const proctors = Array.isArray(j.proctors) ? j.proctors : [];
            renderProctors(proctors);
            updateProctorsStats();
        } catch (e) {
            console.warn('loadProctors failed', e);
            container.innerHTML = '<div style="text-align:center;padding:1rem;color:crimson">خطا در بارگذاری مشخصات مراقبین</div>';
        }
    }

    function renderProctors(proctors) {
        const container = document.getElementById('proctorsList');
        if (!container) return;
        if (!proctors.length) {
            container.innerHTML = '<div style="text-align: right; direction: rtl">هیچ مراقبی ثبت نشده است.</div>';
            return;
        }

        function getGenderTitle(gender) {
            if (gender === 'مرد') return 'جناب آقای';
            if (gender === 'زن') return 'سرکار خانم';
            return '';
        }

        let html = `
            <table class="table" style="direction:rtl;text-align:right;margin:0;">
                <thead>
                    <tr>
                        <th style="width:50px">ردیف</th>
                        <th>نام</th>
                        <th>نام خانوادگی</th>
                        <th>شماره همراه</th>
                        <th style="width:100px">عملیات</th>
                    </tr>
                </thead><tbody>`;

        proctors.forEach((p, idx) => {
            const id = Number(p.id || 0);
            const genderTitle = getGenderTitle(p.gender);
            const first = genderTitle + ' ' + escapeHtml(p.first_name || '');
            const last = escapeHtml(p.last_name || '');
            const phone = toPersianDigits(escapeHtml(p.phone || ''));
            html += `<tr data-id="${id}" style="cursor:pointer;">
                <td style="vertical-align:middle;text-align:center;">${idx + 1}</td>
                <td style="vertical-align:middle">${first}</td>
                <td style="vertical-align:middle">${last}</td>
                <td style="vertical-align:middle">${phone}</td>
                <td style="vertical-align:middle">
                    <button class="btn btn-sm btn-danger delete-proctor" data-id="${id}">حذف</button>
                </td>
            </tr>`;
        });

        html += `</tbody></table>`;
        container.innerHTML = html;

        // Attach click to edit
        container.querySelectorAll('tbody tr').forEach(row => {
            row.addEventListener('click', (e) => {
                if (e.target.classList.contains('delete-proctor')) return; // don't edit on delete click
                const id = row.dataset.id;
                const p = proctors.find(pr => String(pr.id) === String(id));
                if (p) {
                    document.getElementById('proctorGender').value = p.gender || '';
                    document.getElementById('proctorFirstName').value = p.first_name || '';
                    document.getElementById('proctorLastName').value = p.last_name || '';
                    document.getElementById('proctorPhone').value = toPersianDigits(p.phone || '');
                    // Store editing id
                    document.getElementById('saveProctorBtn').dataset.editingId = id;
                    // Ensure save button state reflects current values (including gender)
                    try { updateProctorSaveState(); } catch (e) {}
                }
            });
        });

        // Attach delete
        container.querySelectorAll('.delete-proctor').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const id = btn.dataset.id;
                const confirm = await Swal.fire({
                    title: 'حذف مراقب',
                    text: 'آیا مطمئن هستید؟',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'بله، حذف',
                    cancelButtonText: 'لغو',
                    customClass: { popup: 'swal2-rtl swal2-glass' }
                });
                if (confirm.isConfirmed) {
                    try {
                        const resp = await fetch('/API/deleteProctor.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
                            body: JSON.stringify({ id: id })
                        });
                        if (resp.ok) {
                            await loadProctors();
                        } else {
                            Swal.fire({ title: 'خطا', text: 'حذف ناموفق', icon: 'error', customClass: { popup: 'swal2-rtl swal2-glass' } });
                        }
                    } catch (err) {
                        Swal.fire({ title: 'خطا', text: 'حذف ناموفق', icon: 'error', customClass: { popup: 'swal2-rtl swal2-glass' } });
                    }
                }
            });
        });
    }

    async function updateProctorsStats() {
        const statsEl = document.getElementById('proctorsStats');
        if (!statsEl) return;
        try {
            // Get current proctors count
            const resp = await fetch('/API/getProctors.php', { cache: 'no-store' });
            const j = await resp.json();
            const current = Array.isArray(j.proctors) ? j.proctors.length : 0;

            // Get max required from ExamsDetil
            const edResp = await fetch('/API/getExamsDetail.php', { cache: 'no-store' });
            const edj = await edResp.json();
            const exams = Array.isArray(edj.exams) ? edj.exams : [];
            const maxRequired = exams.length ? Math.max(...exams.map(e => Number(e.required_proctors || 0))) : 0;

            // If current meets or exceeds required, show a simple green summary without 'مانده'
            if (!maxRequired || current >= maxRequired) {
                statsEl.innerHTML = `<span style="color:green;">مراقبین ثبت شده: ${current} نفر</span>`;
            } else {
                const remaining = maxRequired - current;
                statsEl.innerHTML = `<span style="color:red;">مراقبین: ${current} از حداقل  ${maxRequired} نفر مراقب لازم (مانده: ${remaining})</span>`;
            }
            // update finish button enablement when proctors stats change
            try { if (typeof updateFinishProctorsBtn === 'function') updateFinishProctorsBtn(); } catch (e) {}
        } catch (e) {
            statsEl.innerHTML = 'آمار ناموفق';
        }
    }

    // Enable/disable the "اتمام معرفی مراقبین" button based on total required vs registered
    async function updateFinishProctorsBtn() {
        const btn = document.getElementById('finishProctorsBtn');
        if (!btn) return;
        try {
            const [pResp, eResp] = await Promise.all([
                fetch('/API/getProctors.php', { cache: 'no-store' }),
                fetch('/API/getExamsDetail.php', { cache: 'no-store' })
            ]);
            const pJson = pResp && pResp.ok ? await pResp.json() : { proctors: [] };
            const eJson = eResp && eResp.ok ? await eResp.json() : { exams: [] };
            const registered = Array.isArray(pJson.proctors) ? pJson.proctors.length : 0;
            const exams = Array.isArray(eJson.exams) ? eJson.exams : [];
            // follow the same rule as updateProctorsStats: compare against the maximum
            // required_proctors among sessions (not the sum). If there are no exams,
            // maxRequired becomes 0 and the button will be enabled (registered >= 0).
            const maxRequired = exams.length ? Math.max(...exams.map(e => Number(e.required_proctors || 0))) : 0;
            // disable if registered proctors are fewer than the max required
            btn.disabled = !(registered >= maxRequired);
        } catch (e) {
            btn.disabled = true;
        }
    }

    // Attach proctor save and clear
    const saveProctorBtn = document.getElementById('saveProctorBtn');
    const firstInput = document.getElementById('proctorFirstName');
    const lastInput = document.getElementById('proctorLastName');
    const phoneInput = document.getElementById('proctorPhone');
    const genderSelect = document.getElementById('proctorGender');

    // Validation helpers
    function isPersianName(value) {
        if (!value) return false;
        // Allow Persian/Arabic letters, spaces and zero-width non-joiner
        const v = value.trim();
        return /^[\u0600-\u06FF\u0750-\u077F\uFB50-\uFDFF\uFE70-\uFEFF\s\u200C]+$/.test(v);
    }

    function isValidPhone(value) {
        if (!value) return false;
        const eng = toEnglishDigits(String(value)).replace(/[^0-9]/g, '');
        return /^\d{11}$/.test(eng);
    }

    function updateProctorSaveState() {
        try {
            const first = firstInput ? firstInput.value.trim() : '';
            const last = lastInput ? lastInput.value.trim() : '';
            const phone = phoneInput ? phoneInput.value.trim() : '';

            const firstOk = isPersianName(first) && first.length <= 40;
            const lastOk = isPersianName(last) && last.length <= 40;
            const phoneOk = isValidPhone(phone);
            const genderVal = genderSelect ? (genderSelect.value || '').trim() : '';
            const genderOk = genderVal === 'زن' || genderVal === 'مرد';

            // set bootstrap validation classes
            if (firstInput) {
                firstInput.classList.toggle('is-valid', firstOk);
                firstInput.classList.toggle('is-invalid', !firstOk && first.length > 0);
            }
            if (lastInput) {
                lastInput.classList.toggle('is-valid', lastOk);
                lastInput.classList.toggle('is-invalid', !lastOk && last.length > 0);
            }
            if (phoneInput) {
                phoneInput.classList.toggle('is-valid', phoneOk);
                phoneInput.classList.toggle('is-invalid', !phoneOk && phone.length > 0);
            }

            if (saveProctorBtn) saveProctorBtn.disabled = !(firstOk && lastOk && phoneOk && genderOk);
        } catch (e) { /* ignore */ }
    }

    if (firstInput) firstInput.addEventListener('input', () => { updateProctorSaveState(); });
    if (lastInput) lastInput.addEventListener('input', () => { updateProctorSaveState(); });
    if (phoneInput) phoneInput.addEventListener('input', () => { updateProctorSaveState(); });
    if (genderSelect) genderSelect.addEventListener('change', () => { updateProctorSaveState(); });

    // initialize state
    updateProctorSaveState();

    if (saveProctorBtn) {
        saveProctorBtn.addEventListener('click', async () => {
            const gender = document.getElementById('proctorGender').value.trim();
            const first = firstInput ? firstInput.value.trim() : '';
            const last = lastInput ? lastInput.value.trim() : '';
            let phone = phoneInput ? phoneInput.value.trim() : '';
            const editingId = saveProctorBtn.dataset.editingId || '';

            // Clean phone: convert to English digits, keep only numbers, ensure 11
            phone = toEnglishDigits(phone).replace(/[^0-9]/g, '').substring(0, 11);

            // Final validation before submit
            if (!isPersianName(first) || !isPersianName(last)) {
                Swal.fire({ title: 'خطا', text: 'نام و نام خانوادگی باید با حروف فارسی وارد شوند و خالی نباشند', icon: 'error', customClass: { popup: 'swal2-rtl swal2-glass' } });
                return;
            }
            if (!/^\d{11}$/.test(phone)) {
                Swal.fire({ title: 'خطا', text: 'شماره همراه باید دقیقا ۱۱ رقم باشد', icon: 'error', customClass: { popup: 'swal2-rtl swal2-glass' } });
                return;
            }
            const genderVal = genderSelect ? (genderSelect.value || '').trim() : '';
            if (!(genderVal === 'زن' || genderVal === 'مرد')) {
                Swal.fire({ title: 'خطا', text: 'جنسیت باید انتخاب شود', icon: 'error', customClass: { popup: 'swal2-rtl swal2-glass' } });
                return;
            }

            try {
                const resp = await fetch('/API/saveProctor.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
                    body: JSON.stringify({
                        id: editingId ? Number(editingId) : 0,
                        gender: gender,
                        first_name: first,
                        last_name: last,
                        phone: phone
                    })
                });
                if (resp.ok) {
                    await loadProctors();
                    // Clear form
                    document.getElementById('proctorGender').value = '';
                    if (firstInput) firstInput.value = '';
                    if (lastInput) lastInput.value = '';
                    if (phoneInput) phoneInput.value = '';
                    delete saveProctorBtn.dataset.editingId;
                    updateProctorSaveState();
                } else {
                    const j = await resp.json();
                    Swal.fire({ title: 'خطا', text: j.error || 'ذخیره ناموفق', icon: 'error', customClass: { popup: 'swal2-rtl swal2-glass' } });
                }
            } catch (err) {
                Swal.fire({ title: 'خطا', text: 'ذخیره ناموفق', icon: 'error', customClass: { popup: 'swal2-rtl swal2-glass' } });
            }
        });
    }

    // ensure gender change updates save button state as well
    if (genderSelect) genderSelect.addEventListener('change', () => { try { updateProctorSaveState(); } catch (e) {} });

    const clearProctorBtn = document.getElementById('clearProctorBtn');
    if (clearProctorBtn) {
        clearProctorBtn.addEventListener('click', () => {
            // Clear values
            try { document.getElementById('proctorGender').value = ''; } catch (e) {}
            const inputs = [firstInput, lastInput, phoneInput];
            inputs.forEach(inp => {
                try {
                    if (!inp) return;
                    inp.value = '';
                    // remove validation states (tick/cross)
                    inp.classList.remove('is-valid');
                    inp.classList.remove('is-invalid');
                } catch (e) {}
            });
            // Remove editing id and disable save button
            try {
                if (saveProctorBtn) {
                    delete saveProctorBtn.dataset.editingId;
                    saveProctorBtn.disabled = true;
                }
            } catch (e) {}
            // Update validation UI
            try { updateProctorSaveState(); } catch (e) {}
        });
    }

})();

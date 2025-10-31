function isDesktopDevice() {
    const isTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 1;
    const width = window.innerWidth || document.documentElement.clientWidth;
    const userAgent = navigator.userAgent.toLowerCase();
    const isMobileUA = /android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini/.test(userAgent);
    return !isTouch && width > 900 && !isMobileUA;
}

// Build and open a printable "صورتجلسه آزمون" (session report).
// Uses the same exam date/time displayed in #nextExamDateTime and calls API/getNextExamReport.php
async function printSessionReport() {
    try {
        const nextExamDateTimeText = document.getElementById('nextExamDateTime')?.textContent || '';
        if (!nextExamDateTimeText || nextExamDateTimeText === 'بارگذاری...' || nextExamDateTimeText === 'آزمونی یافت نشد') {
            return Swal.fire({ icon: 'info', title: 'اطلاعات', text: 'آزمون بعدی یافت نشد', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
        }

        const parts = nextExamDateTimeText.split('|').map(s => s.trim());
        if (parts.length !== 2) {
            return Swal.fire({ icon: 'error', title: 'خطا', text: 'فرمت تاریخ و ساعت نامعتبر است', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
        }
        const examTime = parts[0];
        const examDate = parts[1];

        const resp = await guardedFetch(`../API/getNextExamReport.php?exam_date=${encodeURIComponent(examDate)}&exam_time=${encodeURIComponent(examTime)}`, { cache: 'no-store' });
        const data = await resp.json();
        if (data.error) {
            return Swal.fire({ icon: 'error', title: 'خطا', text: data.error || 'خطا در دریافت اطلاعات', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
        }

        const courses = Array.isArray(data.courses) ? data.courses.slice() : [];
        if (!courses.length) {
            return Swal.fire({ icon: 'info', title: 'اطلاعات', text: 'هیچ درسی برای این جلسه وجود ندارد', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
        }

        // Show loading modal while preparing the session report
        Swal.fire({
            title: 'در حال ساخت صورتجلسه',
            html: 'لطفاً منتظر بمانید...',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); },
            customClass: { popup: 'swal2-rtl swal2-glass' },
            showConfirmButton: false
        });

        // Sort: electronic (الکترونیکی) first, then others; then by numeric course_code
        courses.sort((a, b) => {
            if ((a.exam_type || '') === (b.exam_type || '')) return (Number(a.course_code) || 0) - (Number(b.course_code) || 0);
            if ((a.exam_type || '') === 'الکترونیکی') return -1;
            if ((b.exam_type || '') === 'الکترونیکی') return 1;
            return (a.exam_type || '').localeCompare(b.exam_type || '');
        });

        const toPersianDigits = (s) => String(s).replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
        const esc = (txt) => { const d = document.createElement('div'); d.textContent = txt || ''; return d.innerHTML; };

        // Compute semester & academic year based on examDate (expecting YYYY/MM/DD)
        let semesterLabel = 'نامشخص';
        try {
            // Normalize Persian digits to ASCII before parsing (handles inputs like "۱۴۰۴/۱۰/۰۲")
            const persianToLatin = (s) => String(s || '').replace(/[۰-۹]/g, d => '0123456789'['۰۱۲۳۴۵۶۷۸۹'.indexOf(d)]);
            const partsDate = (examDate || '').split('/').map(s => persianToLatin(s).replace(/[^0-9]/g, ''));
            if (partsDate.length >= 2) {
                const year = parseInt(partsDate[0], 10);
                const month = parseInt(partsDate[1], 10);
                if ([9, 10].includes(month)) semesterLabel = 'نیمسال اول';
                else if ([2, 3].includes(month)) semesterLabel = 'نیمسال دوم';
                else if ([5, 6].includes(month)) semesterLabel = 'دوره تابستان';
                else { if (month >= 7 && month <= 12) semesterLabel = 'نیمسال اول'; else if (month >= 1 && month <= 4) semesterLabel = 'نیمسال دوم'; }
            }
        } catch (e) { /* ignore */ }

        // Academic year display: if نیمسال اول => year - year+1, else previousYear - year
        let acadStart = 0, acadEnd = 0;
        try {
            // reuse Persian->Latin helper from above
            const persianToLatin = (s) => String(s || '').replace(/[۰-۹]/g, d => '0123456789'['۰۱۲۳۴۵۶۷۸۹'.indexOf(d)]);
            const partsDate = (examDate || '').split('/').map(s => persianToLatin(s).replace(/[^0-9]/g, ''));
            const year = parseInt(partsDate[0], 10) || new Date().getFullYear();
            if (semesterLabel === 'نیمسال اول') { acadStart = year; acadEnd = year + 1; }
            else { acadStart = year - 1; acadEnd = year; }
        } catch (e) { acadStart = 0; acadEnd = 0; }

        // Ensure the smaller year is always on the left (e.g. ۱۴۰۴-۱۴۰۵ not ۱۴۰۵-۱۴۰۴)
        const acadLeft = Math.min(Number(acadStart) || 0, Number(acadEnd) || 0);
        const acadRight = Math.max(Number(acadStart) || 0, Number(acadEnd) || 0);
        // Wrap academic-year in an LTR embedding to avoid bidi reordering in RTL documents
        const persAcad = `<span dir="ltr" style="unicode-bidi: embed; direction: ltr;">${toPersianDigits(acadLeft)}-${toPersianDigits(acadRight)}</span>`;

        // Prepare printable HTML (portrait A4) with 20 courses per page
        const fontHref = (window.location && window.location.origin ? window.location.origin : '') + '/assets/fonts/vazir/vazir.css';
        // compact page margins to match seat-numbers report
        let html = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>صورتجلسه آزمون</title><link rel="stylesheet" href="${fontHref}">`;
        html += `<style>
            /* compact A4 portrait margins (aligned with seat-number report) */
            @page { size: A4 portrait; margin: 4mm; }
            body { font-family: Vazir, Tahoma, Arial, sans-serif; color: #111; margin: 0; padding: 0; }
            /* Each sheet is an A4 page with relative positioning so footer can be pinned */
            /* reduced padding so printed content uses more of the page (helps some browsers avoid adding headers/footers) */
            .sheet { width: 210mm; box-sizing: border-box; padding: 6mm; position: relative; min-height: calc(297mm - 12mm); page-break-after: always; }
            .sheet:last-child { page-break-after: auto; }
            .header { display:flex; gap:8px; align-items:center; justify-content:space-between; }
            .logo { width: 110px; height: 110px; object-fit: contain; display:block; margin:0 auto; }
            .header-right { flex: 1; text-align: center; }
            .title { font-size: 16pt; font-weight: 800; margin: 4px 0; }
            .university { font-size: 11pt; color: #222; margin-bottom: 6px; }
            .divider { height: 1px; background: #111; margin: 6px 0 8px 0; opacity: 0.85; }
            .meta { font-size: 10pt; text-align: right; color: #222; margin-bottom: 8px; }
            .courses { width: 100%; border-collapse: collapse; font-size: 10pt; margin-bottom: 36mm; }
            .courses th, .courses td { padding: 5px 6px; border-bottom: 1px solid #e0e0e0; }
            .courses thead th { background: #efefef; font-weight: 800; text-align: center; }
            .courses tbody tr:nth-child(odd) { background: #fafafa; }
            .courses td.name { text-align: right; }
            .courses td.center { text-align: center; }
            /* Footer/signatures pinned above the page-footer-bar so signatures aren't flush to the page edge */
            .footer-signs { position: absolute; left: 18mm; right: 6mm; bottom: 12mm; }
            /* Full-width paragraph note with bottom border */
            .footer-signs .footer-note { display:block; font-size: 10pt; text-align: center; margin-bottom: 8px; border-bottom:1px solid #111; padding-bottom:6px; }
            /* Space between each signer (use margin, not literal newlines) */
            /* Increased spacing per user request and layout for signature block */
            .footer-signs .sign { margin: 24px 0; font-size: 10pt; display:flex; justify-content:space-between; align-items:center; }
            .footer-signs .sign .main { text-align: right; flex: 1 1 auto; }
            .footer-signs .sign .sig { flex: 0 0 120px; text-align: left; margin-left: 8px; }
            /* Full-width page footer bar with centered bold page numbering */
            .page-footer-bar { position: absolute; left: 0; right: 0; bottom: 0; height: 8mm; background: #444; color: #fff; display:flex; align-items:center; justify-content:center; font-weight: 700; font-size: 9pt; line-height:1; }
            @media print { .no-print { display: none !important; } }
        </style>`;
        html += `</head><body>`;


        // headerHtml is built after loading configuration so we can prefer cfg.University

        const perPage = 15;
        const pages = Math.ceil(courses.length / perPage);

        // Load config so we can use configured signatory names (Boss, HeadOfEDU, Chairman)
        let cfg = {};
        try {
            const cfgResp = await guardedFetch('../API/getConfig.php', { cache: 'no-store' });
            if (cfgResp && cfgResp.ok) cfg = await cfgResp.json();
        } catch (e) { /* ignore */ }
        const bossName = esc(cfg.BossNickName || '');
        const headName = esc(cfg.HeadOfEDU || '');
        const chairName = esc(cfg.Chairman || '');

        // Prefer the configured University name; fall back to footer text. Remove any leading "نسار -" prefix.
        let university = esc(cfg.University || (document.getElementById('footerText')?.textContent || ''));
        university = university.replace(/^نسار\s*-\s*/i, '').trim();
        const headerHtml = `
                                                <div class="header">
                                                        <div style="flex:0 0 140px; text-align:center;">
                                                                <img src="/assets/app/Pnulogo.png" alt="PnuLogo" class="logo">
                                                                <div style="width:110px;margin:6px auto 0 auto;font-size:10pt;text-align:center;font-weight:900;">مرکز سنجش و آزمون</div>
                                                        </div>
                            <div class="header-right">
                                <div class="title">صورتجلسه آزمون</div>
                                <div class="university">${university || 'دانشگاه پیام نور'}</div>
                            </div>
                            <div style="width:120px;"></div>
                        </div>
                        <div class="divider"></div>
                        <div class="meta">آزمون دروس زیر در ${semesterLabel} سالتحصیلی ${persAcad} با حضور امضاء کنندگان زیر در ساعت ${toPersianDigits(examTime)} مورخ ${toPersianDigits(examDate)} شروع گردید. (نمونه سوال ضمیمه می باشد)</div>`;

        function buildTable(slice, startIndex) {
            // table header
            let t = '<table class="courses"><thead><tr>' +
                '<th style="width:4%">ردیف</th>' +
                '<th style="width:8%">کد درس</th>' +
                '<th style="width:60%">نام درس</th>' +
                '<th style="width:13%">تعداد</th>' +
                '<th style="width:15%">حاضر / غایب</th>' +
                '</tr></thead><tbody>';
            slice.forEach((c, idx) => {
                const code = esc(c.course_code || '');
                const name = esc(c.course_name || '');
                const count = Number(c.student_count || c.count || 0) || 0;
                // Placeholder text for manual filling: حاضرین ____ نفر / غایبین ____ نفر
                const statsPlaceholder = ' ___  /  ___ ';
                t += `<tr>` +
                    `<td class="center">${toPersianDigits(startIndex + idx + 1)}</td>` +
                    `<td class="center">${code}</td>` +
                    `<td class="name">${name}</td>` +
                    `<td class="center">${toPersianDigits(count)}</td>` +
                    `<td class="center">${statsPlaceholder}</td>` +
                    `</tr>`;
            });
            t += '</tbody></table>';
            return t;
        }

        for (let p = 0; p < pages; p++) {
            const start = p * perPage;
            const slice = courses.slice(start, start + perPage);
            html += `<div class="sheet">`;
            html += headerHtml;
            html += buildTable(slice, start);

            // footer/signatures: use configured signatory names (Boss, HeadOfEDU, Chairman)
            html += `<div class="footer-signs">`;
            // paragraph note (keeps previous informative sentence)
            html += `<div class="footer-note">پس از انقضای مهلت آزمون، پاسخنامه‌ها جمع‌آوری و بعد از شمارش و کنترل با لیست حضور و غیاب و تایید، تحویل ستاد امتحانات گردید.</div>`;

            // Row 1: رئیس مرکز / معاون / سرپرست واحد
            const bossLabel = `نام و نام خانوادگی رئیس مرکز/ معاون مرکز/ سرپرست واحد: ${bossName || '________________'}`;
            html += `<div class="sign"><div class="main">${bossLabel}</div><div class="sig">امضاء</div></div>`;

            // Row 2: مسئول آموزش
            const headLabel = `نام و نام خانوادگی رئیس اداره آموزش: ${headName || '________________'}`;
            html += `<div class="sign"><div class="main">${headLabel}</div><div class="sig">امضاء</div></div>`;

            // Row 3: مسئول جلسه
            const chairLabel = `نام و نام خانوادگی مسئول جلسه: ${chairName || '________________'}`;
            html += `<div class="sign"><div class="main">${chairLabel}</div><div class="sig">امضاء</div></div>`;

            // Additional signers requested by admin: supervisors and dispatched inspector
            html += `<div class="sign"><div class="main">نام و نام خانوادگی ناظران/مراقبان جلسه:</div><div class="sig">امضاء</div></div>`;
            html += `<div class="sign"><div class="main">نام و نام خانوادگی بازرس اعزامی از استان/سازمان مرکزی:</div><div class="sig">امضاء</div></div>`;

            html += `</div>`;

            // page footer bar with page numbering (Persian digits)
            html += `<div class="page-footer-bar">صفحه ${toPersianDigits(p + 1)} از ${toPersianDigits(pages)}</div>`;

            html += `</div>`; // .sheet
        }

        html += `</body></html>`;


        const iframe = document.createElement('iframe');
        iframe.style.position = 'fixed';
        iframe.style.left = '-10000px';
        iframe.style.top = '0';
        iframe.style.width = '210mm';
        iframe.style.height = '297mm';
        iframe.style.border = '0';
        iframe.style.visibility = 'hidden';
        document.body.appendChild(iframe);

        try {
            const idoc = iframe.contentDocument || iframe.contentWindow.document;
            idoc.open();
            idoc.write(html);
            idoc.close();
            // close loading modal before opening print dialog
            try { Swal.close(); } catch (e) { }
            setTimeout(() => {
                try { iframe.contentWindow.focus(); iframe.contentWindow.print(); } catch (e) { console.error('Print error:', e); }
                setTimeout(() => { try { document.body.removeChild(iframe); } catch (e) { } }, 500);
            }, 400);
        } catch (e) {
            try { document.body.removeChild(iframe); } catch (er) { }
            try { Swal.close(); } catch (er) { }
            Swal.fire({ icon: 'error', title: 'خطا', text: 'خطا در چاپ صورتجلسه', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
        }

    } catch (err) {
        console.error('Error building session report:', err);
        Swal.fire({ icon: 'error', title: 'خطا', text: 'خطا در آماده‌سازی صورتجلسه', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
    }
}

function toPersianDigits(num) {
    return String(num).replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
}

// Return Bootstrap badge class for exam type labels across the dashboard
function getExamBadgeClass(type) {
    if (!type) return 'bg-secondary';
    const t = String(type).trim();
    if (t === 'الکترونیکی') return 'bg-warning';
    if (t === 'کتبی') return 'bg-dark';
    return 'bg-info';
}

document.addEventListener('DOMContentLoaded', function () {
    if (!isDesktopDevice()) {
        let countdownInterval;
        Swal.fire({
            icon: 'warning',
            title: 'دسترسی فقط از دسکتاپ',
            html: '<div style="text-align:justify;line-height:2">برای استفاده کامل از امکانات داشبورد مدیریتی نسار، لطفاً از کامپیوتر یا لپ‌تاپ استفاده کنید.<br>نمایش و مدیریت دقیق اطلاعات فقط در نسخه دسکتاپ پشتیبانی می‌شود.</div>' +
                '<div class="swal2-countdown" style="margin-top:1.2em;text-align:center;font-size:1.2em;font-weight:bold;"><span class="swal2-countdown-value">' + toPersianDigits(15) + '</span></div>',
            timer: 15000,
            timerProgressBar: true,
            showConfirmButton: false,
            allowOutsideClick: true,
            customClass: {
                popup: 'swal2-rtl swal2-glass',
            },
            didOpen: () => {
                const valueEl = Swal.getHtmlContainer()?.querySelector('.swal2-countdown-value');
                if (!valueEl) return;
                const updateCountdown = () => {
                    const remaining = Swal.getTimerLeft();
                    if (typeof remaining !== 'number') return;
                    const seconds = Math.max(0, Math.ceil(remaining / 1000));
                    valueEl.textContent = toPersianDigits(seconds);
                };
                updateCountdown();
                countdownInterval = window.setInterval(updateCountdown, 250);
            },
            willClose: () => {
                if (countdownInterval) {
                    window.clearInterval(countdownInterval);
                }
            }
        });
    }
});

// Check admin authentication
function getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(';').shift();
    return null;
}

function showLicenseForbidden(message) {
    Swal.fire({
        icon: 'error',
        title: 'خطای لایسنس',
        html: `<div style="text-align:right;line-height:1.9">${message}</div>`,
        confirmButtonText: 'باشه',
        allowOutsideClick: false,
        customClass: {
            popup: 'swal2-rtl swal2-glass',
            confirmButton: 'btn btn-primary'
        }
    });
}


async function handleLicenseGuardResponse(response) {
    if (response.status !== 403) return;
    let message = 'دسترسی به داشبورد به علت مشکل لایسنس امکان‌پذیر نیست.';
    try {
        const payload = await response.clone().json();
        if (payload && payload.message) {
            message = payload.message;
        }
    } catch (error) {
        // Ignore JSON parsing errors and use fallback text
    }
    showLicenseForbidden(message);
    const err = new Error('license_forbidden');
    err.isLicenseError = true;
    throw err;
}

async function guardedFetch(resource, options) {
    const response = await fetch(resource, options);
    await handleLicenseGuardResponse(response);
    return response;
}

function checkAuth() {
    const adminSession = getCookie('adminSession');
    if (!adminSession) {
        window.location.href = '../';
        return false;
    }
    try {
        const session = JSON.parse(decodeURIComponent(adminSession));
        if (session.type !== 'admin') {
            window.location.href = '../';
            return false;
        }
        document.getElementById('adminUsername').textContent = session.username || 'مدیر سیستم';
        return true;
    } catch (e) {
        window.location.href = '../';
        return false;
    }
}

// Logout
document.getElementById('logoutBtn').addEventListener('click', () => {
    document.cookie = 'adminSession=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
    window.location.href = '../';
});

// Edit Roles (placeholder) — opens a modal to manage roles (implementation can be added later)
// Edit Roles — edit four config names: AdminNickName, BossNickName, HeadOfEDU, Chairman
// Flow: 1) confirm warning -> 2) show turquoise-styled form prefilled from getConfig.php -> 3) on Save, show confirmation -> 4) POST to API/saveConfig.php
try {
    const editBtn = document.getElementById('editRolesBtn');
    if (editBtn) {
        editBtn.addEventListener('click', async () => {
            // First confirmation (warn about changing session-report info)
            const first = await Swal.fire({
                title: 'تأیید تغییر اطلاعات صورتجلسه',
                text: 'این عمل باعث تغییر اطلاعات صورتجلسه‌های آزمون خواهد شد. آیا مطمئن هستید که می‌خواهید ادامه دهید؟',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'بله، ادامه',
                cancelButtonText: 'لغو',
                reverseButtons: true,
                customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-danger', cancelButton: 'btn btn-secondary' }
            });
            if (!first.isConfirmed) return;

            // Load current config
            let cfg = {};
            try {
                const cfgResp = await guardedFetch('../API/getConfig.php', { cache: 'no-store' });
                if (cfgResp && cfgResp.ok) cfg = await cfgResp.json();
            } catch (e) {
                console.warn('Could not load config for edit roles modal', e);
            }

            const adminVal = cfg.AdminNickName || '';
            const bossVal = cfg.BossNickName || '';
            const headVal = cfg.HeadOfEDU || '';
            const chairVal = cfg.Chairman || '';

            // local escape helper (avoid relying on functions defined elsewhere)
            const escapeHtml = (text) => { const div = document.createElement('div'); div.textContent = text || ''; return div.innerHTML; };

            // Form HTML: use SweetAlert's glass popup background (don't add a bright inner background)
            // Inputs use transparent background and subtle borders so the modal looks like the existing glass theme
            const formHtml = `
                <div style="text-align: right; direction: rtl;">
                    <div style="padding:8px; border-radius:6px;">
                        <div style="margin-bottom:8px; font-weight:700; color:inherit;">ویرایش نقش‌ها و نام‌های امضا‌کننده</div>

                        <label style="display:block;font-size:0.92rem;margin-top:6px;color:inherit;">نام نمایشی کاربر (نمایش در هدر)</label>
                        <input id="er_admin" class="swal2-input" placeholder="نام نمایشی کاربر" style="margin-bottom:6px;border:1px solid rgba(255,255,255,0.08);background:transparent;color:inherit;box-shadow:none;" value="${escapeHtml(adminVal)}">

                        <label style="display:block;font-size:0.92rem;margin-top:6px;color:inherit;">نام و نام خانوادگی رئیس مرکز</label>
                        <input id="er_boss" class="swal2-input" placeholder="رئیس مرکز" style="margin-bottom:6px;border:1px solid rgba(255,255,255,0.08);background:transparent;color:inherit;box-shadow:none;" value="${escapeHtml(bossVal)}">

                        <label style="display:block;font-size:0.92rem;margin-top:6px;color:inherit;">نام و نام خانوادگی رئیس اداره آموزش</label>
                        <input id="er_head" class="swal2-input" placeholder="رئیس اداره آموزش" style="margin-bottom:6px;border:1px solid rgba(255,255,255,0.08);background:transparent;color:inherit;box-shadow:none;" value="${escapeHtml(headVal)}">

                        <label style="display:block;font-size:0.92rem;margin-top:6px;color:inherit;">نام و نام خانوادگی مسئول جلسه</label>
                        <input id="er_chair" class="swal2-input" placeholder="مسئول جلسه" style="margin-bottom:6px;border:1px solid rgba(255,255,255,0.08);background:transparent;color:inherit;box-shadow:none;" value="${escapeHtml(chairVal)}">
                    </div>
                </div>`;

            const modalResult = await Swal.fire({
                title: 'ویرایش نقش‌ها',
                html: formHtml,
                showCancelButton: true,
                confirmButtonText: 'ذخیره',
                cancelButtonText: 'انصراف',
                focusConfirm: false,
                customClass: { popup: 'swal2-rtl swal2-glass' },
                preConfirm: () => {
                    const admin = document.getElementById('er_admin')?.value || '';
                    const boss = document.getElementById('er_boss')?.value || '';
                    const head = document.getElementById('er_head')?.value || '';
                    const chair = document.getElementById('er_chair')?.value || '';
                    // return values to then handle save confirmation
                    return { AdminNickName: admin.trim(), BossNickName: boss.trim(), HeadOfEDU: head.trim(), Chairman: chair.trim() };
                }
            });

            if (!modalResult.isConfirmed) return;

            const values = modalResult.value || {};

            // Second confirmation before saving
            const second = await Swal.fire({
                title: 'تأیید نهایی',
                text: 'ذخیره تغییرات باعث به‌روز‌رسانی اطلاعات صورتجلسه‌ها خواهد شد. آیا مطمئن به ذخیره هستید؟',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'بله، ذخیره کن',
                cancelButtonText: 'لغو',
                reverseButtons: true,
                customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary', cancelButton: 'btn btn-secondary' }
            });
            if (!second.isConfirmed) return;

            // Perform save
            try {
                const saveResp = await guardedFetch('../API/saveConfig.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json; charset=utf-8' },
                    body: JSON.stringify(values)
                });
                const saveJson = await saveResp.json();
                if (saveJson && saveJson.success) {
                    // Update displayed admin name if changed
                    if (values.AdminNickName) {
                        try { document.getElementById('adminUsername').textContent = values.AdminNickName; } catch (e) { }
                    }
                    await Swal.fire({ icon: 'success', title: 'ذخیره شد', text: 'اطلاعات با موفقیت ذخیره شد', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
                } else {
                    throw new Error((saveJson && saveJson.error) ? saveJson.error : 'خطا در ذخیره تنظیمات');
                }
            } catch (err) {
                console.error('Save config failed:', err);
                await Swal.fire({ icon: 'error', title: 'خطا', text: (err && err.message) ? err.message : 'خطا در ذخیره تنظیمات', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
            }
        });
    }
} catch (e) {
    console.warn('Edit roles handler attach failed', e);
}

// Load dashboard data
async function loadDashboardData() {
    try {
        // Get config
        const configResponse = await guardedFetch('../API/getConfig.php', { cache: 'no-store' });
        const config = await configResponse.json();

        // Update admin nickname if available
        if (config.AdminNickName) {
            document.getElementById('adminUsername').textContent = config.AdminNickName;
        }

        // Get statistics
        const statsResponse = await guardedFetch('../API/getStatistics.php', { cache: 'no-store' });
        const stats = await statsResponse.json();

        if (!stats.error) {
            document.getElementById('totalStudents').textContent = stats.totalStudents || 0;
            document.getElementById('totalCourses').textContent = stats.totalCourses || 0;
            document.getElementById('nextExamStudents').textContent = stats.nextExamStudents || 0;
            document.getElementById('nextExamDateTime').textContent = stats.nextExamDateTime || 'آزمونی یافت نشد';
            // Remaining future exam sessions (from now onwards)
            if (typeof stats.remainingSessions !== 'undefined') {
                const el = document.getElementById('remainingSessions');
                const card = el ? el.closest('.dashboard-card') : null;
                if (el) {
                    // If there are no remaining sessions, show the text and disable interaction
                    if (!stats.remainingSessions || stats.remainingSessions === 0) {
                        el.textContent = 'آزمونی وجود ندارد';
                        if (card) {
                            card.classList.add('stat-card-disabled');
                            // remove pointer cursor
                            card.style.cursor = 'default';
                        }
                    } else {
                        el.textContent = stats.remainingSessions;
                        if (card) {
                            card.classList.remove('stat-card-disabled');
                            card.style.cursor = 'pointer';
                        }
                    }
                }
            }
            // no breakdown in the top stat card; breakdown will be shown in the course list header
        }
        // Always (re)render the reports chart after loading statistics
        try { renderReportsChart(); } catch (e) { console.error('Chart render failed:', e); }
    } catch (error) {
        console.error('Error loading dashboard data:', error);
        if (!error?.isLicenseError) {
            Swal.fire({
                icon: 'error',
                title: 'خطا',
                text: 'خطا در بارگذاری اطلاعات'
            });
        }
    }
}

// Placeholder action for the "جلسه باقیمانده" card. User will specify the exact action later.
async function showRemainingSessions() {
    try {
        // Ensure Chart.js is available before attempting to render
        try { await loadChartJsIfNeeded(); } catch (loadErr) {
            console.warn('Chart.js not available:', loadErr);
            const card = document.getElementById('reportsChartCard');
            if (card) {
                let ph = card.querySelector('.reports-chart-placeholder');
                if (!ph) {
                    ph = document.createElement('div');
                    ph.className = 'reports-chart-placeholder';
                    ph.style.cssText = 'padding:1.5rem;color:var(--text-muted);text-align:center;font-size:1.05rem;';
                    ph.textContent = 'خطا در بارگذاری نمودار (Chart.js در دسترس نیست)';
                    card.appendChild(ph);
                } else {
                    ph.textContent = 'خطا در بارگذاری نمودار (Chart.js در دسترس نیست)';
                    ph.style.display = 'block';
                }
            }
            return;
        }

        const resp = await guardedFetch('../API/getStatistics.php', { cache: 'no-store' });
        const stats = await resp.json();
        const future = stats.futureExams || [];

        if (!future.length) {
            return Swal.fire({
                icon: 'info',
                title: 'اطلاعات',
                text: 'جلسه آینده‌ای یافت نشد',
                confirmButtonText: 'باشه',
                customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' }
            });
        }

        // Build HTML grid of mini-cards: first line = total (bigger), second line = date | time
        let cardsHtml = '<div class="session-mini-grid">';
        future.forEach(f => {
            const time = f.exam_time;
            const date = f.exam_date;
            const total = f.student_count || 0;
            // Determine morning vs afternoon: hour < 12 => morning
            const hour = parseInt((time || '00:00').split(':')[0], 10) || 0;
            const whenClass = hour < 12 ? 'morning' : 'afternoon';
            const label = `${time} | ${date}`;
            cardsHtml += `
                            <div class="session-mini-card ${whenClass}" data-exam-time="${time}" data-exam-date="${date}">
                                <div class="line1">${toPersianDigits(total)}</div>
                                <div class="line2">${label}</div>
                            </div>`;
        });
        cardsHtml += '</div>';

        await Swal.fire({
            html: cardsHtml,
            width: '110rem',
            showCloseButton: false,
            showConfirmButton: false,
            customClass: { popup: 'swal2-rtl swal2-glass' },
            didOpen: () => {
                const container = Swal.getHtmlContainer();
                if (!container) return;
                const cards = container.querySelectorAll('.session-mini-card');
                cards.forEach(card => {
                    card.addEventListener('click', () => {
                        const t = card.getAttribute('data-exam-time');
                        const d = card.getAttribute('data-exam-date');
                        // Set a custom title so showNextExamReport will render with a specific label
                        window.customExamReportTitle = `آزمون تاریخ ${d} ساعت ${t}`;
                        // Set the nextExamDateTime label so showNextExamReport can parse it
                        const nextEl = document.getElementById('nextExamDateTime');
                        if (nextEl) nextEl.textContent = `${t} | ${d}`;
                        Swal.close();
                        // Small delay to ensure modal closed
                        setTimeout(() => showNextExamReport(), 120);
                    });
                });
            }
        });
    } catch (err) {
        console.error('Error loading future exams:', err);
        Swal.fire({ icon: 'error', title: 'خطا', text: 'خطا در دریافت جلسات آینده', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
    }
}

// Update footer university name
async function updateFooterUniversity() {
    try {
        const response = await guardedFetch('../API/getConfig.php', { cache: 'no-store' });
        const config = await response.json();
        if (config.University) {
            document.getElementById('footerText').textContent = `نسار - ${config.University}`;
        }
    } catch (error) {
        if (!error?.isLicenseError) {
            console.error('Error updating footer:', error);
        }
    }
}
updateFooterUniversity();

// Release notice is handled by the service worker; inline notice removed to avoid duplication.

// Footer click event
const copyrightFooter = document.getElementById('copyrightFooter');
if (copyrightFooter) {
    copyrightFooter.addEventListener('click', async () => {
        const VERSION = window.APP_VERSION;
        function toPersianDigits(num) {
            const persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
            return String(num).replace(/\d/g, d => persianDigits[d]);
        }
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        let countdownInterval;
        let university = 'دانشگاه پیام نور مرکز بیجار';
        try {
            const configResponse = await guardedFetch('../API/getConfig.php', { cache: 'no-store' });
            const config = await configResponse.json();
            if (config.University) {
                university = config.University;
            }
        } catch (error) {
            if (error?.isLicenseError) {
                return;
            }
            console.error('Error loading config for about modal:', error);
        }

        Swal.fire({
            title: 'درباره نِسار',
            html: `
        <div style="line-height:1.9;font-size:1.05rem;text-align:justify;">
        داشبورد نِسار (نسخه ${VERSION}) یک اپلیکیشن تحت‌وب پیشرفته و مدرن است که با استفاده از خروجی‌های نرم‌افزار ساد، به همکاران دانشگاه پیام نور امکان می‌دهد برنامه‌ریزی و مدیریت آزمون‌ها، از جمله زمان‌بندی، تخصیص صندلی و ملزومات اجرایی را به‌صورت یکپارچه و متمرکز انجام داده و در عین حفظ ساختار رسمی در برگزاری، به صرفه‌جویی در زمان و منابع مورد نیاز برای آزمون کمک کند.
            <br>
      این برنامه به سفارش <span style="color: lime; font-weight: bold;">${escapeHtml(university)}</span> و توسط <a href="https://t.me/RealDream" target="_blank" style="color: gold; font-weight: bold; text-decoration: none; border: none; outline: none;">مهدی حسنی</a> توسعه یافته است
    </div>
    <div class="swal2-countdown">
      <span class="swal2-countdown-value">${toPersianDigits(30)}</span>
    </div>
  `,
            timer: 30000,
            showConfirmButton: false,
            allowOutsideClick: true,
            allowEscapeKey: true,
            customClass: {
                popup: 'swal2-rtl swal2-glass'
            },
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
            willClose: () => {
                if (countdownInterval) clearInterval(countdownInterval);
            }
        });
    });
}

// Initialize
if (checkAuth()) {
    loadDashboardData();
}

// Chart instance holder for reports chart
let reportsChartInstance = null;
// Ensure we only configure Chart.js defaults once
let chartDefaultsConfigured = false;
// Resize handling for reports chart (debounced)
let reportsResizeRegistered = false;
let reportsResizeTimer = null;
// Small pie instances for overview pies
let smallPieExamTypeInstance = null;
let smallPieCourseTypeInstance = null;

function destroySmallOverviewPies() {
    try { if (smallPieExamTypeInstance) { smallPieExamTypeInstance.destroy(); smallPieExamTypeInstance = null; } } catch (e) { /* ignore */ }
    try { if (smallPieCourseTypeInstance) { smallPieCourseTypeInstance.destroy(); smallPieCourseTypeInstance = null; } } catch (e) { /* ignore */ }
}

function renderSmallOverviewPies(stats) {
    if (!stats) return;
    try {
        // Ensure Chart.js loaded
        if (typeof Chart === 'undefined') return;

        const examTypeTotals = stats.futureExamTypeTotals || {};
        const courseTypeTotals = stats.futureCourseTypeTotals || {};

        // Prepare exam-type pie
        const examLabels = Object.keys(examTypeTotals);
        const examValues = examLabels.map(l => Number(examTypeTotals[l]) || 0);

        const examCtx = document.getElementById('smallPieExamType');
        const courseCtx = document.getElementById('smallPieCourseType');

        // Colors palette
        const palette = ['#1a6fa6', '#ff8a65', '#7bd5ff', '#9ccc65', '#ffca28', '#7e57c2', '#26a69a', '#ef5350'];

        destroySmallOverviewPies();

        if (examCtx && examLabels.length) {
            const examData = {
                labels: examLabels.map(l => (typeof toPersianDigits === 'function') ? toPersianDigits(l) : l),
                datasets: [{
                    data: examValues,
                    backgroundColor: examLabels.map((_, i) => palette[i % palette.length])
                }]
            };

            const examOptions = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: true,
                        displayColors: false,
                        callbacks: {
                            // remove tooltip title to avoid duplicate lines; return single-line label
                            title: function () { return ''; },
                            label: function (ctx) {
                                const val = ctx.raw || 0;
                                const label = ctx.label || '';
                                return `${label}: ${(typeof toPersianDigits === 'function') ? toPersianDigits(val) : val}`;
                            }
                        }
                    }
                },
                elements: { arc: { borderWidth: 1 } }
            };

            smallPieExamTypeInstance = new Chart(examCtx.getContext('2d'), {
                type: 'doughnut',
                data: examData,
                options: examOptions
            });
            // make the small canvas clickable to show a large modal preview
            try {
                const el = document.getElementById('smallPieExamType');
                if (el) {
                    el.style.cursor = 'pointer';
                    el.onclick = function () { try { showLargePie('نوع آزمون', examLabels, examValues, palette); } catch (e) { console.error(e); } };
                }
            } catch (e) { /* ignore */ }
        }

        // Prepare course-type pie
        const courseLabels = Object.keys(courseTypeTotals);
        const courseValues = courseLabels.map(l => Number(courseTypeTotals[l]) || 0);
        if (courseCtx && courseLabels.length) {
            const courseData = {
                labels: courseLabels.map(l => (typeof toPersianDigits === 'function') ? toPersianDigits(l) : l),
                datasets: [{
                    data: courseValues,
                    backgroundColor: courseLabels.map((_, i) => palette[(i + 2) % palette.length])
                }]
            };

            const courseOptions = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: true,
                        displayColors: false,
                        callbacks: {
                            title: function () { return ''; },
                            label: function (ctx) {
                                const val = ctx.raw || 0;
                                const label = ctx.label || '';
                                return `${label}: ${(typeof toPersianDigits === 'function') ? toPersianDigits(val) : val}`;
                            }
                        }
                    }
                },
                elements: { arc: { borderWidth: 1 } }
            };

            smallPieCourseTypeInstance = new Chart(courseCtx.getContext('2d'), {
                type: 'doughnut',
                data: courseData,
                options: courseOptions
            });
            try {
                const el2 = document.getElementById('smallPieCourseType');
                if (el2) {
                    el2.style.cursor = 'pointer';
                    el2.onclick = function () { try { showLargePie('نوع درس', courseLabels, courseValues, palette); } catch (e) { console.error(e); } };
                }
            } catch (e) { /* ignore */ }
        }
    } catch (e) {
        console.warn('Could not render small overview pies:', e);
    }
}

function configureChartDefaults() {
    if (chartDefaultsConfigured) return;
    try {
        if (typeof Chart === 'undefined') return;
        // Font family and global color
        Chart.defaults.font.family = 'Vazir, sans-serif';
        // Keep default font size unchanged by not setting Chart.defaults.font.size
        // Set default color for axes and labels
        Chart.defaults.color = '#0b2a44';
        // Disable tooltips globally per user's preference
        if (!Chart.defaults.plugins) Chart.defaults.plugins = {};
        Chart.defaults.plugins.tooltip = Chart.defaults.plugins.tooltip || {};
        Chart.defaults.plugins.tooltip.enabled = false;
        // Legend labels styling
        Chart.defaults.plugins.legend = Chart.defaults.plugins.legend || {};
        Chart.defaults.plugins.legend.labels = Chart.defaults.plugins.legend.labels || {};
        Chart.defaults.plugins.legend.labels.family = 'Vazir, sans-serif';
        Chart.defaults.plugins.legend.labels.color = '#0b2a44';
        // Set a sensible global aspect ratio
        Chart.defaults.maintainAspectRatio = true;
        Chart.defaults.aspectRatio = 16 / 9;
        chartDefaultsConfigured = true;
    } catch (e) {
        console.warn('Could not configure Chart defaults:', e);
    }
}

// Ensure Chart.js is loaded and available as a global. If it's not, dynamically load the vendor file.
function loadChartJsIfNeeded() {
    // Prefer dynamic import for ESM bundle and expose Chart as a global for legacy code
    return new Promise(async (resolve, reject) => {
        if (typeof Chart !== 'undefined') return resolve();
        try {
            // Dynamic import of ESM Chart.js
            const mod = await import('../assets/vendor/chartjs/chart.min.js');
            // Chart may be named export or default
            const exported = mod.Chart || mod.default || mod;
            if (!exported) return reject(new Error('Chart module loaded but no export found'));
            // Expose as global for existing code
            window.Chart = exported;
            // Configure global defaults now that Chart is available
            try { configureChartDefaults(); } catch (e) { /* ignore */ }
            return resolve();
        } catch (err) {
            // Attempt fallback: try to load as a script tag (older browsers)
            try {
                const existing = document.querySelector('script[data-chart-loader]');
                if (existing) {
                    existing.addEventListener('load', () => {
                        if (typeof Chart !== 'undefined') return resolve();
                        return reject(new Error('Chart.js loaded but Chart is undefined'));
                    });
                    existing.addEventListener('error', () => reject(new Error('Failed to load Chart.js')));
                    return;
                }
                const script = document.createElement('script');
                script.src = '../assets/vendor/chartjs/chart.min.js';
                script.async = true;
                script.setAttribute('data-chart-loader', '1');
                script.onload = () => {
                    if (typeof Chart !== 'undefined') {
                        try { configureChartDefaults(); } catch (e) { /* ignore */ }
                        return resolve();
                    }
                    return reject(new Error('Chart.js loaded but Chart is undefined'));
                };
                script.onerror = () => reject(new Error('Failed to load Chart.js'));
                document.head.appendChild(script);
            } catch (err2) {
                return reject(err);
            }
        }
    });
}

// Render a simple daily grouped bar chart of future exam sessions
async function renderReportsChart() {
    try {
        // Ensure Chart.js is available (dynamic import for ESM build)
        try {
            await loadChartJsIfNeeded();
            // ensure defaults configured before rendering any chart
            try { configureChartDefaults(); } catch (e) { /* ignore */ }
        } catch (loadErr) {
            console.warn('Chart.js not available for reports chart:', loadErr);
            const card = document.getElementById('reportsChartCard');
            if (card) {
                let ph = card.querySelector('.reports-chart-placeholder');
                if (!ph) {
                    ph = document.createElement('div');
                    ph.className = 'reports-chart-placeholder';
                    ph.style.cssText = 'padding:1.5rem;color:var(--text-muted);text-align:center;font-size:1.05rem;';
                    ph.textContent = 'خطا در بارگذاری نمودار (Chart.js در دسترس نیست)';
                    card.appendChild(ph);
                } else {
                    ph.textContent = 'خطا در بارگذاری نمودار (Chart.js در دسترس نیست)';
                    ph.style.display = 'block';
                }
            }
            return;
        }
        const resp = await guardedFetch('../API/getStatistics.php', { cache: 'no-store' });
        const stats = await resp.json();
        const future = stats.futureExams || [];

        const card = document.getElementById('reportsChartCard');
        const canvas = document.getElementById('reportsChart');
        if (!card || !canvas) return;

        // Build mapping date -> time -> count, and collect ordered dates & times
        const dateMap = {}; // { date: { time: count } }
        const dateTs = {}; // earliest timestamp per date for sorting
        const timesSet = new Set();

        future.forEach(e => {
            const d = e.exam_date || '';
            const t = e.exam_time || '';
            const cnt = Number(e.student_count) || 0;
            if (!dateMap[d]) dateMap[d] = {};
            if (!dateMap[d][t]) dateMap[d][t] = 0;
            dateMap[d][t] += cnt;
            timesSet.add(t);
            if (!dateTs[d] || e.timestamp < dateTs[d]) dateTs[d] = e.timestamp;
        });

        // Sort dates by earliest timestamp
        const labels = Object.keys(dateMap).map(d => ({ date: d, ts: dateTs[d] || 0 })).sort((a, b) => a.ts - b.ts).map(x => x.date);
        // Sort times naturally (by hour and minute)
        const times = Array.from(timesSet).sort((a, b) => {
            const pa = (a || '00:00').split(':').map(Number);
            const pb = (b || '00:00').split(':').map(Number);
            return (pa[0] - pb[0]) || (pa[1] - pb[1]);
        });

        // Build datasets: one dataset per session time across all dates
        const palette = [
            '#1a6fa6', '#ff8a65', '#7bd5ff', '#9ccc65', '#ffca28', '#7e57c2', '#26a69a', '#ef5350'
        ];
        const datasets = times.map((time, idx) => {
            const dataArr = labels.map(date => (dateMap[date] && dateMap[date][time]) ? dateMap[date][time] : 0);
            return {
                // keep original (latin) label if needed, but display Persian digits in legend
                _rawLabel: time,
                label: (typeof toPersianDigits === 'function') ? toPersianDigits(time) : time,
                data: dataArr,
                backgroundColor: palette[idx % palette.length]
            };
        });

        // If the viewport is narrow, aggregate by date (sum across session times) to save horizontal space
        const viewportWidth = (window.innerWidth || document.documentElement.clientWidth || 0);
        const isNarrow = viewportWidth < 900 || (canvas && canvas.clientWidth && canvas.clientWidth < 520);
        let chartDatasets = datasets;
        if (isNarrow) {
            const aggregated = labels.map((_, idx) => {
                return datasets.reduce((sum, ds) => sum + (Number(ds.data[idx]) || 0), 0);
            });
            chartDatasets = [{
                label: (typeof toPersianDigits === 'function') ? toPersianDigits('مجموع') : 'مجموع',
                data: aggregated,
                backgroundColor: '#1a6fa6'
            }];
        }

        // If no data, show placeholder text and destroy any existing chart
        if (!labels.length) {
            canvas.style.display = 'none';
            if (reportsChartInstance) {
                try { reportsChartInstance.destroy(); } catch (er) { /* ignore */ }
                reportsChartInstance = null;
            }
            // destroy small overview pies as there's no data
            try { destroySmallOverviewPies(); } catch (e) { /* ignore */ }
            // ensure placeholder exists
            let ph = card.querySelector('.reports-chart-placeholder');
            if (!ph) {
                ph = document.createElement('div');
                ph.className = 'reports-chart-placeholder';
                ph.style.cssText = 'padding:1.5rem;color:var(--text-muted);text-align:center;font-size:1.05rem;';
                ph.textContent = 'جلسه‌ای برای نمایش وجود ندارد';
                card.appendChild(ph);
            } else {
                ph.style.display = 'block';
            }
            return;
        }

        // Remove any placeholder
        const existingPh = card.querySelector('.reports-chart-placeholder');
        if (existingPh) existingPh.remove();
        canvas.style.display = 'block';

        // Destroy previous instance if present
        if (reportsChartInstance) {
            try { reportsChartInstance.destroy(); } catch (er) { /* ignore */ }
            reportsChartInstance = null;
        }

        const ctx = canvas.getContext('2d');

        // (Removed) labels-on-bars plugin — per user request values above bars are not rendered.

        // convert x-axis labels (dates) to Persian digits for display
        const displayLabels = (typeof toPersianDigits === 'function') ? labels.map(l => toPersianDigits(l)) : labels;

        reportsChartInstance = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: displayLabels,
                datasets: chartDatasets
            },
            options: {
                responsive: true,
                // prefer a 16:9 viewing frame on wide screens; allow flexible height on narrow
                maintainAspectRatio: !isNarrow,
                aspectRatio: 16 / 9,
                scales: {
                    x: {
                        ticks: { color: '#0b2a44', font: { family: 'Vazir, sans-serif' }, maxRotation: 90, minRotation: 90 },
                        grid: { display: false },
                        stacked: false
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: '#0b2a44', precision: 0, font: { family: 'Vazir, sans-serif' },
                            callback: function (value) { return (typeof toPersianDigits === 'function') ? toPersianDigits(value) : value; }
                        },
                        grid: { color: 'rgba(11,42,68,0.06)' }
                    }
                },
                plugins: {
                    legend: { display: !isNarrow, position: 'top', labels: { color: '#0b2a44', font: { family: 'Vazir, sans-serif' } } },
                    // enable a minimal tooltip that shows only the Persian-formatted value (no title, no color box)
                    tooltip: {
                        enabled: true,
                        displayColors: false,
                        bodyFont: { family: 'Vazir, sans-serif' },
                        callbacks: {
                            title: function () { return ''; },
                            label: function (context) {
                                // Prefer raw value, fallback to parsed y value
                                const raw = (typeof context.raw !== 'undefined') ? context.raw : (context.parsed && context.parsed.y ? context.parsed.y : 0);
                                const v = Number(raw) || 0;
                                return (typeof toPersianDigits === 'function') ? toPersianDigits(v) : String(v);
                            }
                        }
                    }
                }
            }
        });

        // Register a debounced resize handler once so chart re-renders and switches aggregation mode when window size changes
        try {
            if (!reportsResizeRegistered) {
                window.addEventListener('resize', () => {
                    if (reportsResizeTimer) clearTimeout(reportsResizeTimer);
                    reportsResizeTimer = setTimeout(() => {
                        try {
                            // Re-render reports chart which will pick aggregation based on current width
                            renderReportsChart();
                        } catch (e) {
                            console.error('Error re-rendering reports chart on resize:', e);
                        }
                    }, 260);
                });
                reportsResizeRegistered = true;
            }
        } catch (e) {
            console.warn('Could not register resize handler for reports chart:', e);
        }

        // Render the two small overview pies beside the main chart
        try { renderSmallOverviewPies(stats); } catch (e) { console.warn('Could not render overview pies:', e); }

    } catch (error) {
        console.error('Error rendering reports chart:', error);
    }
}
// Get max upload size from server
let MAX_UPLOAD_SIZE = 128 * 1024 * 1024; // Default 128MB
let MAX_UPLOAD_SIZE_FORMATTED = '۱۲۸ مگابایت';

async function loadUploadLimit() {
    try {
        const response = await guardedFetch('../API/getUploadLimit.php', { cache: 'no-store' });
        const data = await response.json();
        if (data.maxSize) {
            MAX_UPLOAD_SIZE = data.maxSize;
            MAX_UPLOAD_SIZE_FORMATTED = data.maxSizeFormatted;
        }
    } catch (error) {
        console.error('Error loading upload limit:', error);
    }
}
loadUploadLimit();

// Database upload functionality
async function showUploadModal(examType) {
    const examTypeName = examType === 'K' ? 'کتبی' : 'الکترونیکی';

    const { value: file } = await Swal.fire({
        title: `آپلود فایل آزمون‌های ${examTypeName}`,
        html: `
			<style>
				.upload-area {
					border: 3px dashed #28a745;
					border-radius: 15px;
					padding: 3rem 2rem;
					text-align: center;
					background: linear-gradient(135deg, rgba(40, 167, 69, 0.05) 0%, rgba(40, 167, 69, 0.1) 100%);
					cursor: pointer;
					transition: all 0.3s ease;
					margin-bottom: 1rem;
				}
				.upload-area:hover {
					border-color: #218838;
					background: linear-gradient(135deg, rgba(40, 167, 69, 0.1) 0%, rgba(40, 167, 69, 0.15) 100%);
					transform: translateY(-2px);
				}
				.upload-area.dragover {
					border-color: #1e7e34;
					background: linear-gradient(135deg, rgba(40, 167, 69, 0.15) 0%, rgba(40, 167, 69, 0.2) 100%);
					transform: scale(1.02);
				}
				.upload-icon {
					font-size: 4rem;
					color: #28a745;
					margin-bottom: 1rem;
				}
				.upload-text {
					font-size: 1.2rem;
					color: #28a745;
					font-weight: 600;
					margin-bottom: 0.5rem;
				}
				.upload-hint {
					font-size: 0.95rem;
					color: #6c757d;
					margin-bottom: 1rem;
				}
				.file-name-display {
					background: #e8f5e9;
					padding: 0.75rem;
					border-radius: 8px;
					margin-top: 1rem;
					color: #28a745;
					font-weight: 600;
					display: none;
				}
				.browse-btn {
					background: #28a745;
					color: white;
					border: none;
					padding: 0.75rem 2rem;
					border-radius: 8px;
					font-family: 'Vazir', sans-serif;
					font-size: 1rem;
					cursor: pointer;
					transition: all 0.3s ease;
					font-weight: 600;
				}
				.browse-btn:hover {
					background: #218838;
					transform: translateY(-2px);
					box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
				}
				#databaseFile {
					display: none;
				}
			</style>
			<div style="text-align: center;">
				<div class="upload-area" id="uploadArea">
					<div class="upload-icon">📄</div>
					<div class="upload-text">فایل اکسل را اینجا بکشید</div>
					<div class="upload-hint">یا روی دکمه زیر کلیک کنید</div>
					<button type="button" class="browse-btn" id="browseBtn">انتخاب فایل</button>
					<div class="file-name-display" id="fileNameDisplay"></div>
				</div>
				<p style="font-size: 0.9rem; color: #6c757d; margin: 0;">
					فرمت‌های مجاز: XLS, XLSX | حداکثر حجم: ${MAX_UPLOAD_SIZE_FORMATTED}
				</p>
				<input type="file" id="databaseFile" accept=".xls,.xlsx">
			</div>
		`,
        showCancelButton: true,
        confirmButtonText: 'آپلود فایل',
        cancelButtonText: 'انصراف',
        customClass: {
            popup: 'swal2-rtl swal2-glass',
            confirmButton: 'btn btn-primary',
            cancelButton: 'btn btn-secondary'
        },
        preConfirm: () => {
            const fileInput = document.getElementById('databaseFile');
            if (!fileInput.files || fileInput.files.length === 0) {
                Swal.showValidationMessage('لطفاً یک فایل انتخاب کنید');
                return false;
            }

            const file = fileInput.files[0];
            const fileName = file.name.toLowerCase();

            if (!fileName.endsWith('.xls') && !fileName.endsWith('.xlsx')) {
                Swal.showValidationMessage('فقط فایل‌های با پسوند XLS و XLSX مجاز هستند');
                return false;
            }

            // Check file size (use server's max upload size)
            if (file.size > MAX_UPLOAD_SIZE) {
                Swal.showValidationMessage(`حجم فایل نباید بیشتر از ${MAX_UPLOAD_SIZE_FORMATTED} باشد`);
                return false;
            }

            return file;
        },
        didOpen: () => {
            const fileInput = document.getElementById('databaseFile');
            const uploadArea = document.getElementById('uploadArea');
            const browseBtn = document.getElementById('browseBtn');
            const fileNameDisplay = document.getElementById('fileNameDisplay');

            // Browse button click
            if (browseBtn) {
                browseBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    fileInput.click();
                });
            }

            // Upload area click
            if (uploadArea) {
                uploadArea.addEventListener('click', (e) => {
                    if (e.target !== browseBtn) {
                        fileInput.click();
                    }
                });
            }

            // File input change
            if (fileInput) {
                fileInput.addEventListener('change', (e) => {
                    if (e.target.files && e.target.files[0]) {
                        const file = e.target.files[0];
                        fileNameDisplay.textContent = `✓ فایل انتخاب شده: ${file.name}`;
                        fileNameDisplay.style.display = 'block';
                    }
                });
            }

            // Drag and drop events
            if (uploadArea) {
                ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                    uploadArea.addEventListener(eventName, (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                    });
                });

                ['dragenter', 'dragover'].forEach(eventName => {
                    uploadArea.addEventListener(eventName, () => {
                        uploadArea.classList.add('dragover');
                    });
                });

                ['dragleave', 'drop'].forEach(eventName => {
                    uploadArea.addEventListener(eventName, () => {
                        uploadArea.classList.remove('dragover');
                    });
                });

                uploadArea.addEventListener('drop', (e) => {
                    const files = e.dataTransfer.files;
                    if (files.length > 0) {
                        fileInput.files = files;
                        const file = files[0];
                        fileNameDisplay.textContent = `✓ فایل انتخاب شده: ${file.name}`;
                        fileNameDisplay.style.display = 'block';
                    }
                });
            }
        }
    });

    if (file) {
        await uploadDatabaseFile(file, examType, examTypeName);
    }
}

async function uploadDatabaseFile(file, examType, examTypeName) {
    // Show progress modal
    Swal.fire({
        title: 'در حال آپلود',
        html: `
			<div style="text-align: center; padding: 1rem;">
				<div style="background: #e0e0e0; border-radius: 10px; overflow: hidden; height: 35px; margin-bottom: 1rem;">
					<div id="uploadProgressBar" style="background: linear-gradient(90deg, #1a6fa6, #127ead); height: 100%; width: 0%; transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 1.1rem;"></div>
				</div>
				<p id="uploadProgressText" style="color: #1a6fa6; font-size: 1.1rem;">در حال آپلود فایل...</p>
			</div>
		`,
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        customClass: {
            popup: 'swal2-rtl swal2-glass'
        }
    });

    const formData = new FormData();
    formData.append('file', file);
    formData.append('examType', examType);

    try {
        const xhr = new XMLHttpRequest();

        // Track upload progress
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const percentComplete = Math.round((e.loaded / e.total) * 100);
                const progressBar = document.getElementById('uploadProgressBar');
                const progressText = document.getElementById('uploadProgressText');

                if (progressBar) {
                    progressBar.style.width = percentComplete + '%';
                    progressBar.textContent = percentComplete + '%';
                }

                if (progressText) {
                    progressText.textContent = `در حال آپلود... ${percentComplete}%`;
                }
            }
        });

        // Handle completion
        xhr.addEventListener('load', async () => {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        await Swal.fire({
                            icon: 'success',
                            title: 'موفق',
                            text: `فایل آزمون‌های ${examTypeName} با موفقیت آپلود شد`,
                            confirmButtonText: 'باشه',
                            customClass: {
                                popup: 'swal2-rtl swal2-glass',
                                confirmButton: 'btn btn-primary'
                            }
                        });

                        // Now process the uploaded Excel file
                        await processUploadedExcel(examType, examTypeName, response.filename);
                    } else {
                        throw new Error(response.error || 'خطای نامشخص');
                    }
                } catch (parseError) {
                    throw new Error('خطا در پردازش پاسخ سرور');
                }
            } else if (xhr.status === 403) {
                let errorMessage = 'دسترسی به این عملیات ممکن نیست.';
                try {
                    const errorResponse = JSON.parse(xhr.responseText);
                    if (errorResponse && errorResponse.message) {
                        errorMessage = errorResponse.message;
                    }
                } catch (e) {
                    // Use default message
                }
                showLicenseForbidden(errorMessage);
            } else {
                let errorMessage = 'خطا در آپلود فایل';
                try {
                    const errorResponse = JSON.parse(xhr.responseText);
                    if (errorResponse && errorResponse.error) {
                        errorMessage = errorResponse.error;
                    }
                } catch (e) {
                    // Use default message
                }

                await Swal.fire({
                    icon: 'error',
                    title: 'خطا',
                    text: errorMessage,
                    confirmButtonText: 'باشه',
                    customClass: {
                        popup: 'swal2-rtl swal2-glass',
                        confirmButton: 'btn btn-primary'
                    }
                });
            }
        });

        // Handle errors
        xhr.addEventListener('error', async () => {
            await Swal.fire({
                icon: 'error',
                title: 'خطا',
                text: 'خطا در برقراری ارتباط با سرور',
                confirmButtonText: 'باشه',
                customClass: {
                    popup: 'swal2-rtl swal2-glass',
                    confirmButton: 'btn btn-primary'
                }
            });
        });

        // Get CSRF token
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        // Send request
        xhr.open('POST', '../API/uploadDatabase.php', true);
        if (csrfToken) {
            xhr.setRequestHeader('X-CSRF-Token', csrfToken);
        }
        xhr.send(formData);

    } catch (error) {
        console.error('Upload error:', error);
        await Swal.fire({
            icon: 'error',
            title: 'خطا',
            text: error.message || 'خطا در آپلود فایل',
            confirmButtonText: 'باشه',
            customClass: {
                popup: 'swal2-rtl swal2-glass',
                confirmButton: 'btn btn-primary'
            }
        });
    }
}

// Process uploaded Excel file to temp table
async function processUploadedExcel(examType, examTypeName, filename) {
    // Show processing modal
    Swal.fire({
        title: 'در حال پردازش',
        html: `
            <div style="text-align: center; padding: 1rem;">
                <div id="processProgressDisplay" style="font-size: 3rem; font-weight: bold; color: white; margin-bottom: 1rem;">1%</div>
                <p id="processProgressText" style="color: white; font-size: 1.1rem;">در حال خواندن فایل اکسل...</p>
            </div>
        `,
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        customClass: {
            popup: 'swal2-rtl swal2-glass'
        }
    });

    // Before starting heavy processing, validate header mapping quickly on server
    try {
        const validateForm = new FormData();
        validateForm.append('filename', filename);
        validateForm.append('examType', examType);
        const validateResp = await guardedFetch('../API/validateExcelHeader.php', {
            method: 'POST',
            body: validateForm
        });
        if (!validateResp.ok) {
            // Read error message if possible
            let msg = 'فایل منطبق با ساختار فایل نرم افزار ساد نیست';
            try {
                const payload = await validateResp.json();
                if (payload && payload.error) msg = payload.error;
            } catch (e) { }
            Swal.close();
            await Swal.fire({ icon: 'error', title: 'خطا', text: msg, confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
            return;
        }
        const validateData = await validateResp.json();
        // If validate returned totalRows, we can show it in UI later
        const serverTotalRows = validateData.totalRows || 0;

    } catch (e) {
        // Validation failed unexpectedly; continue to processing which will handle it server-side
        console.warn('Header validation failed:', e);
    }

    // Animate progress bar and poll server for real progress
    let progress = 1;
    let serverProgressAvailable = false;
    const progressDisplay = document.getElementById('processProgressDisplay');
    const progressText = document.getElementById('processProgressText');

    const animInterval = setInterval(() => {
        if (serverProgressAvailable) return; // server will drive progress
        progress += Math.random() * 3 + 0.5; // Slower increase
        if (progress > 95) progress = 95; // Stay longer at high % until server finishes
        if (progressDisplay) {
            progressDisplay.textContent = progress >= 10 ? Math.round(progress) + '%' : 'شروع...';
        }
        if (progressText) {
            if (progress < 30) {
                progressText.textContent = 'در حال خواندن فایل اکسل...';
            } else if (progress < 60) {
                progressText.textContent = 'در حال پردازش داده‌ها...';
            } else {
                progressText.textContent = 'در حال ذخیره در دیتابیس...';
            }
        }
    }, 300); // Slower interval

    // Polling server-side progress file
    const pollProgress = async () => {
        try {
            const resp = await guardedFetch(`../API/getProcessProgress.php?filename=${encodeURIComponent(filename)}`);
            if (!resp.ok) return;
            const payload = await resp.json();
            if (!payload) return;
            // If server provides totalRows, use it to compute real percent
            if (payload.totalRows && payload.totalRows > 0) {
                serverProgressAvailable = true;
                const percent = Math.min(99, Math.round((payload.processedRows / payload.totalRows) * 100));
                if (progressDisplay) progressDisplay.textContent = percent + '%';
                if (progressText) progressText.textContent = payload.message || 'در حال پردازش...';
            } else if (payload.stage === 'error') {
                // show server-side validation error
                serverProgressAvailable = true;
                if (progressDisplay) progressDisplay.textContent = '0%';
                if (progressText) progressText.textContent = payload.message || 'خطا در پردازش';
            }
        } catch (e) {
            // ignore polling errors
        }
    };

    const pollInterval = setInterval(pollProgress, 500);
    try {
        const formData = new FormData();
        formData.append('examType', examType);
        formData.append('filename', filename);

        const response = await guardedFetch('../API/processExcelToTemp.php', {
            method: 'POST',
            body: formData
        });

        // Stop polling and animation (server will have final status)
        clearInterval(animInterval);
        clearInterval(pollInterval);

        if (!response.ok) {
            Swal.close();
            if (response.status === 403) {
                let message = 'دسترسی به این عملیات ممکن نیست.';
                try {
                    const payload = await response.json();
                    if (payload && payload.message) {
                        message = payload.message;
                    }
                } catch (error) {
                    // Ignore JSON parsing errors
                }
                showLicenseForbidden(message);
                return;
            } else {
                let errorMessage = 'خطا در پردازش فایل';
                try {
                    const errorResponse = await response.json();
                    if (errorResponse && errorResponse.error) {
                        errorMessage = errorResponse.error;
                    }
                } catch (e) {
                    // Use default message
                }
                throw new Error(errorMessage);
            }
        }

        const result = await response.json();
        if (result.success) {
            // Set to 100%
            if (progressDisplay) {
                progressDisplay.textContent = '100%';
            }
            if (progressText) {
                progressText.textContent = 'پردازش کامل شد!';
            }
            // Wait a bit then show success
            setTimeout(() => {
                Swal.close();
                Swal.fire({
                    icon: 'success',
                    title: 'موفق',
                    html: `فایل اکسل با موفقیت پردازش شد<br>تعداد ردیف‌ها: ${result.rows}<br>تعداد ستون‌ها: ${result.columns}`,
                    confirmButtonText: 'باشه',
                    customClass: {
                        popup: 'swal2-rtl swal2-glass',
                        confirmButton: 'btn btn-primary'
                    }
                });
            }, 500);
        } else {
            Swal.close();
            throw new Error(result.error || 'خطای نامشخص');
        }
    } catch (error) {
        if (typeof animInterval !== 'undefined') try { clearInterval(animInterval); } catch (e) { }
        if (typeof pollInterval !== 'undefined') try { clearInterval(pollInterval); } catch (e) { }
        Swal.close();
        console.error('Process error:', error);
        await Swal.fire({
            icon: 'error',
            title: 'خطا',
            text: error.message || 'خطا در پردازش فایل',
            confirmButtonText: 'باشه',
            customClass: {
                popup: 'swal2-rtl swal2-glass',
                confirmButton: 'btn btn-primary'
            }
        });
    }
}

async function filterStudentsByCourse(courseCode) {
    // Remove active class from all course items
    document.querySelectorAll('.course-item').forEach(item => item.classList.remove('active'));
    // Add active to clicked item
    event.currentTarget.classList.add('active');

    try {
        const response = await guardedFetch(`../API/getCourseReport.php?course_code=${encodeURIComponent(courseCode)}`, { cache: 'no-store' });
        const data = await response.json();

        if (data.error) {
            throw new Error(data.error);
        }

        const students = data.students;

        // Update tbody
        let tbodyHtml = '';
        students.forEach((student, index) => {
            tbodyHtml += `
                <tr>
                    <td>${index + 1}</td>
                    <td>${student.student_id}</td>
                    <td><span class="text-secondary">${student.last_name}</span></td>
                    <td>${student.first_name}</td>
                    <td>${courseCode}</td>
                    <td>${data.course.course_name}</td>
                    <td><span class="text-secondary">${student.seat_number}</span></td>
                    <td>${student.class_name}</td>
                    <td><span class="badge ${getExamBadgeClass(student.exam_type)}">${student.exam_type}</span></td>
                </tr>
            `;
        });

        document.querySelector('#studentsTableBody').innerHTML = tbodyHtml;

    } catch (error) {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'خطا',
            text: 'خطا در فیلتر دانشجویان',
            confirmButtonText: 'باشه',
            customClass: {
                popup: 'swal2-rtl swal2-glass',
                confirmButton: 'btn btn-primary'
            }
        });
    }
}

function showAllStudents() {
    // Remove active class from all course items
    document.querySelectorAll('.course-item').forEach(item => item.classList.remove('active'));
    // Add active to clicked item
    event.currentTarget.classList.add('active');

    // Update tbody with all students
    let tbodyHtml = '';
    window.allStudents.forEach((student, index) => {
        tbodyHtml += `
            <tr>
                <td>${index + 1}</td>
                <td>${student.student_id}</td>
                <td><span class="text-secondary">${student.last_name}</span></td>
                <td>${student.first_name}</td>
                <td>${student.course_code}</td>
                <td>${student.course_name}</td>
                <td><span class="text-secondary">${student.seat_number}</span></td>
                <td>${student.class_name}</td>
                <td><span class="badge ${getExamBadgeClass(student.exam_type)}">${student.exam_type}</span></td>
            </tr>
        `;
    });

    document.querySelector('#studentsTableBody').innerHTML = tbodyHtml;
}

// Add event listeners to upload buttons
document.getElementById('uploadWrittenBtn').addEventListener('click', () => {
    showUploadModal('K');
});

document.getElementById('uploadElectronicBtn').addEventListener('click', () => {
    showUploadModal('E');
});

function scrollReportCardIntoView() {
    const reportCard = document.getElementById('reportCard');
    if (!reportCard) return;

    // Use requestAnimationFrame to ensure calculations happen after the element is rendered.
    requestAnimationFrame(() => {
        const header = document.querySelector('.dashboard-header');
        const headerHeight = header ? Math.ceil(header.getBoundingClientRect().height) : 0;
        const extraGap = 12; // reduce gap to minimum for tight alignment

        const rect = reportCard.getBoundingClientRect();
        const docTop = window.pageYOffset || document.documentElement.scrollTop || 0;
        const targetTop = Math.max(0, rect.top + docTop - headerHeight - extraGap);

        window.scrollTo({ top: targetTop, behavior: 'smooth' });
    });
}

// Report functions
function clearReport() {
    document.getElementById('reportCard').style.display = 'none';
    document.getElementById('reportContent').innerHTML = '';
    // Smooth scroll to top of page
    const container = document.querySelector('.dashboard-container');
    if (!container) return;
    const targetTop = container.getBoundingClientRect().top + window.pageYOffset;
    window.scrollTo({ top: Math.max(0, targetTop), behavior: 'smooth' });
    // Rebuild the reports chart when the report is cleared
    try { renderReportsChart(); } catch (e) { /* ignore */ }
}

async function showStudentReport() {
    const { value: studentId } = await Swal.fire({
        title: 'جستجوی دانشجو',
        html: '<input id="studentIdInput" type="tel" inputmode="numeric" class="swal2-input" placeholder="شماره دانشجویی" style="font-family: Vazir, sans-serif; direction: ltr; text-align: center; overflow: hidden; resize: none; outline: none;">',
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'جستجو',
        cancelButtonText: 'انصراف',
        customClass: {
            popup: 'swal2-rtl swal2-glass',
            confirmButton: 'btn btn-primary',
            cancelButton: 'btn btn-secondary'
        },
        preConfirm: () => {
            const input = document.getElementById('studentIdInput');
            if (!input.value) {
                Swal.showValidationMessage('لطفاً شماره دانشجویی را وارد کنید');
                return false;
            }
            return input.value;
        }
    });

    if (studentId) {
        try {
            // حذف Swal.fire بارگذاری
            const response = await guardedFetch(`../API/getStudentReport.php?student_id=${encodeURIComponent(studentId)}`, { cache: 'no-store' });
            const data = await response.json();

            if (data.error) {
                await Swal.fire({
                    icon: 'error',
                    title: 'خطا',
                    text: data.error,
                    confirmButtonText: 'باشه',
                    customClass: {
                        popup: 'swal2-rtl swal2-glass',
                        confirmButton: 'btn btn-primary'
                    }
                });
                return;
            }

            // Display student info and exams
            const student = data.student;
            const exams = data.exams;

            let html = `
				<div class="mb-4">
					<h5 class="text-primary mb-3">مشخصات دانشجو</h5>
					<div class="table-responsive">
						<table class="table table-bordered">
							<tr>
								<th style="width: 30%;">شماره دانشجویی</th>
								<td>${student.student_id}</td>
							</tr>
							<tr>
								<th>نام و نام خانوادگی</th>
								<td>${student.first_name} ${student.last_name}</td>
							</tr>
							<tr>
								<th>کد ملی</th>
								<td>${student.national_id}</td>
							</tr>
							<tr>
								<th>مقطع تحصیلی</th>
								<td>${student.degree}</td>
							</tr>
						</table>
					</div>
				</div>
			`;

            if (exams && exams.length > 0) {
                html += `
					<div>
						<h5 class="text-primary mb-3">آزمون‌های دانشجو</h5>
						<div class="table-responsive">
							<table class="table table-striped table-hover">
								<thead class="table-light">
									<tr>
										<th>ردیف</th>
										<th>کد درس</th>
										<th>نام درس</th>
										<th>تاریخ</th>
										<th>ساعت</th>
										<th>شماره صندلی</th>
										<th>کلاس</th>
										<th>نوع درس</th>
										<th>نوع آزمون</th>
									</tr>
								</thead>
								<tbody>
					`;

                exams.forEach((exam, index) => {
                    html += `
						<tr>
							<td>${index + 1}</td>
							<td>${exam.course_code}</td>
							<td>${exam.course_name}</td>
							<td>${exam.exam_date}</td>
							<td>${exam.exam_time}</td>
							<td><span class="text-secondary">${exam.seat_number}</span></td>
							<td>${exam.class_name}</td>
							<td><span class="badge bg-${exam.course_type === 'کتبی' ? 'success' : 'info'}">${exam.course_type}</span></td>
                            <td><span class="badge ${getExamBadgeClass(exam.exam_type)}">${exam.exam_type}</span></td>
						</tr>
					`;
                });

                html += `
								</tbody>
							</table>
						</div>
					</div>
				`;
            } else {
                html += '<p class="text-muted text-center mt-3">این دانشجو در هیچ آزمونی ثبت‌نام نکرده است.</p>';
            }

            document.getElementById('reportContent').innerHTML = html;
            document.getElementById('reportCard').style.display = 'block';
            setTimeout(scrollReportCardIntoView, 100);

        } catch (error) {
            console.error('Error:', error);
            if (!error?.isLicenseError) {
                Swal.fire({
                    icon: 'error',
                    title: 'خطا',
                    text: 'خطا در دریافت اطلاعات',
                    confirmButtonText: 'باشه',
                    customClass: {
                        popup: 'swal2-rtl swal2-glass',
                        confirmButton: 'btn btn-primary'
                    }
                });
            }
        }
    }
}

async function showCourseReport() {
    const { value: courseCode } = await Swal.fire({
        title: 'جستجوی درس',
        html: '<input id="courseCodeInput" type="tel" inputmode="numeric" class="swal2-input" placeholder="کد درس" style="font-family: Vazir, sans-serif; direction: ltr; text-align: center; overflow: hidden; resize: none; outline: none;">',
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'جستجو',
        cancelButtonText: 'انصراف',
        customClass: {
            popup: 'swal2-rtl swal2-glass',
            confirmButton: 'btn btn-primary',
            cancelButton: 'btn btn-secondary'
        },
        preConfirm: () => {
            const input = document.getElementById('courseCodeInput');
            if (!input.value) {
                Swal.showValidationMessage('لطفاً کد درس را وارد کنید');
                return false;
            }
            return input.value;
        }
    });

    if (courseCode) {
        try {
            // حذف Swal.fire بارگذاری
            const response = await guardedFetch(`../API/getCourseReport.php?course_code=${encodeURIComponent(courseCode)}`, { cache: 'no-store' });
            const data = await response.json();

            if (data.error) {
                await Swal.fire({
                    icon: 'error',
                    title: 'خطا',
                    text: data.error,
                    confirmButtonText: 'باشه',
                    customClass: {
                        popup: 'swal2-rtl swal2-glass',
                        confirmButton: 'btn btn-primary'
                    }
                });
                return;
            }

            // Display course info and students
            const course = data.course;
            const students = data.students;

            let html = `
				<div class="mb-4">
					<h5 class="text-primary mb-3">مشخصات درس</h5>
					<div class="table-responsive">
						<table class="table table-bordered">
							<tr>
								<th style="width: 30%;">کد درس</th>
								<td>${course.course_code}</td>
							</tr>
							<tr>
								<th>نام درس</th>
								<td>${course.course_name}</td>
							</tr>
							<tr>
								<th>تاریخ آزمون</th>
								<td>${course.exam_date}</td>
							</tr>
							<tr>
								<th>ساعت آزمون</th>
								<td>${course.exam_time}</td>
							</tr>
							<tr>
								<th>نوع درس</th>
								<td><span class="badge bg-${course.course_type === 'کتبی' ? 'success' : 'info'}">${course.course_type}</span></td>
							</tr>
							<tr>
								<th>تعداد دانشجویان</th>
								<td><span class="text-secondary">${students.length}</span> نفر</td>
							</tr>
						</table>
					</div>
				</div>
			`;

            if (students && students.length > 0) {
                html += `
					<div>
						<h5 class="text-primary mb-3">لیست دانشجویان</h5>
						<div class="table-responsive">
							<table class="table table-striped table-hover">
								<thead class="table-light">
									<tr>
										<th>ردیف</th>
										<th>شماره دانشجویی</th>
										<th>نام خانوادگی</th>
										<th>نام</th>
										<th>مقطع</th>
										<th>شماره صندلی</th>
										<th>کلاس</th>
										<th>نوع آزمون</th>
									</tr>
								</thead>
								<tbody>
			`;

                students.forEach((student, index) => {
                    html += `
						<tr>
							<td>${index + 1}</td>
							<td>${student.student_id}</td>
							<td><span class="text-secondary">${student.last_name}</span></td>
							<td>${student.first_name}</td>
							<td>${student.degree}</td>
							<td><span class="text-secondary">${student.seat_number}</span></td>
							<td>${student.class_name}</td>
                            <td><span class="badge ${getExamBadgeClass(student.exam_type)}">${student.exam_type}</span></td>
						</tr>
					`;
                });

                html += `
							</tbody>
						</table>
					</div>
				</div>
			`;
            } else {
                html += '<p class="text-muted text-center mt-3">هیچ دانشجویی در این درس ثبت‌نام نکرده است.</p>';
            }

            document.getElementById('reportContent').innerHTML = html;
            document.getElementById('reportCard').style.display = 'block';
            setTimeout(scrollReportCardIntoView, 100);

        } catch (error) {
            console.error('Error:', error);
            if (!error?.isLicenseError) {
                Swal.fire({
                    icon: 'error',
                    title: 'خطا',
                    text: 'خطا در دریافت اطلاعات',
                    confirmButtonText: 'باشه',
                    customClass: {
                        popup: 'swal2-rtl swal2-glass',
                        confirmButton: 'btn btn-primary'
                    }
                });
            }
        }
    }
}

async function showNextExamReport() {
    try {
        // Get exam date and time from the label
        const nextExamDateTimeText = document.getElementById('nextExamDateTime').textContent;

        // Check if there's no exam
        if (nextExamDateTimeText === 'بارگذاری...' || nextExamDateTimeText === 'آزمونی یافت نشد') {
            await Swal.fire({
                icon: 'info',
                title: 'اطلاعات',
                text: 'آزمون بعدی یافت نشد',
                confirmButtonText: 'باشه',
                customClass: {
                    popup: 'swal2-rtl swal2-glass',
                    confirmButton: 'btn btn-primary'
                }
            });
            return;
        }

        // Parse the format: "HH:MM | YYYY/MM/DD"
        const parts = nextExamDateTimeText.split('|').map(s => s.trim());
        if (parts.length !== 2) {
            await Swal.fire({
                icon: 'error',
                title: 'خطا',
                text: 'فرمت تاریخ و ساعت نامعتبر است',
                confirmButtonText: 'باشه',
                customClass: {
                    popup: 'swal2-rtl swal2-glass',
                    confirmButton: 'btn btn-primary'
                }
            });
            return;
        }

        const examTime = parts[0];
        const examDate = parts[1];

        // حذف Swal.fire بارگذاری
        const response = await guardedFetch(`../API/getNextExamReport.php?exam_date=${encodeURIComponent(examDate)}&exam_time=${encodeURIComponent(examTime)}`, { cache: 'no-store' });
        const data = await response.json();

        if (data.error) {
            await Swal.fire({
                icon: 'error',
                title: 'خطا',
                text: data.error,
                confirmButtonText: 'باشه',
                customClass: {
                    popup: 'swal2-rtl swal2-glass',
                    confirmButton: 'btn btn-primary'
                }
            });
            return;
        }

        // Display exam info and students
        const courses = data.courses;
        const students = data.students;

        // Store students globally for filtering
        window.allStudents = students;

        const headerTitle = window.customExamReportTitle || 'جزئیات جلسه آزمون';
        // Build a 3-column details table: the last column is a rowspan cell that
        // aggregates the key info and hosts action buttons (print seat numbers / session report)
        let html = `
            <div class="mb-4">
                <h5 class="text-primary mb-3">${headerTitle}</h5>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <tr>
                            <th style="width: 28%;">تاریخ آزمون</th>
                            <td style="width: 36%;">${data.exam_date}</td>
                            <td rowspan="4" style="width: 36%; vertical-align: middle; text-align: center;">
                                <div style="display:flex;flex-direction:column;align-items:center;gap:8px;padding:8px;">
                                    <!-- Session report icon moved here -->
                                    <div style="display:flex;gap:10px;align-items:center;justify-content:center;">
                                        <button id="printSeatNumbersBtn" class="btn btn-outline-primary btn-sm p-0" type="button" title="چاپ شماره صندلی" onclick="try{ printSeatNumbersReport(); }catch(e){ console.error(e); }" style="display:inline-block;">
                                            <img src="/assets/app/Seat.png" alt="شماره صندلی" style="width:140px;height:140px;object-fit:contain;display:block;pointer-events:none;">
                                        </button>
                                        <button id="printSessionReportBtn" class="btn btn-outline-primary btn-sm p-0" type="button" title="چاپ صورتجلسه" onclick="try{ printSessionReport(); }catch(e){ console.error(e); }" style="display:inline-block;">
                                            <img src="/assets/app/report.png" alt="صورتجلسه" style="width:140px;height:140px;object-fit:contain;display:block;pointer-events:none;">
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th>ساعت آزمون</th>
                            <td>${data.exam_time}</td>
                        </tr>
                        <tr>
                            <th>تعداد دروس</th>
                            <td><span class="text-secondary">${courses.length}</span> درس</td>
                        </tr>
                        <tr>
                            <th>تعداد دانشجویان</th>
                            <td><span class="text-secondary">${students.length}</span> نفر</td>
                        </tr>
                    </table>
                </div>
        `;

        // Show course list for any number of courses (including one)
        if (courses) {
            // Prepare header breakdown badges from API (examTypeCounts and courseTypeCounts)
            // Render number badge (gray) first, then a label badge using the same color mapping as in the course rows
            const et = data.examTypeCounts || {};
            const ct = data.courseTypeCounts || {};
            const badgeParts = [];

            function labelColorFor(type) {
                // Keep the same simple mapping used elsewhere: 'کتبی' -> success (green), others -> info (turquoise)
                return type === 'کتبی' ? 'success' : 'info';
            }

            // Exam-type counts (e.g., کتبی / الکترونیکی)
            for (const [type, cnt] of Object.entries(et)) {
                const numBadge = `<span class="badge bg-secondary">${toPersianDigits(cnt)}</span>`;
                // use exam-type color mapping (electronic -> warning, written -> dark)
                const labelBadge = `<span class="badge ${getExamBadgeClass(type)}">${type}</span>`;
                // show label first then number so they read as "label number" and appear as a single unit
                badgeParts.push(`<span class="badge-pair me-2">${labelBadge}${numBadge}</span>`);
            }

            // Course-type counts (e.g., تستی / تشریحی)
            for (const [type, cnt] of Object.entries(ct)) {
                const numBadge = `<span class="badge bg-secondary">${toPersianDigits(cnt)}</span>`;
                const labelBadge = `<span class="badge bg-${labelColorFor(type)}">${type}</span>`;
                badgeParts.push(`<span class="badge-pair me-2">${labelBadge}${numBadge}</span>`);
            }

            const badgesHtml = badgeParts.join('');

            html += `
                <h6 class="text-secondary mb-2 mt-3">لیست دروس این جلسه آزمون:</h6>
                <ul class="list-group mb-3">
                    <li class="list-group-item d-flex justify-content-between align-items-center course-item active" style="cursor: pointer;" onclick="showAllStudents()">
                        <span><strong>همه دروس</strong></span>
                        <div>
                            ${badgesHtml}
                        </div>
                    </li>
            `;

            // Insert mini pie charts area (course / exam-type / course-type)
            html += `
                    <li class="list-group-item">
                        <div id="miniPieSection" class="d-flex flex-column gap-2">
                            <h6 class="mb-2">آمار سریع جلسه (پیش‌نمایش)</h6>
                            <div class="d-flex flex-row justify-content-center align-items-center gap-3">
                                <div class="text-center">
                                    <canvas id="miniPieCourse" class="mini-pie" aria-label="نمایش فراوانی دروس" role="img" title="نمایش فراوانی دروس"></canvas>
                                </div>
                                <div class="text-center">
                                    <canvas id="miniPieExamType" class="mini-pie" aria-label="نمایش نوع آزمون" role="img" title="نمایش نوع آزمون"></canvas>
                                </div>
                                <div class="text-center">
                                    <canvas id="miniPieCourseType" class="mini-pie" aria-label="نمایش نوع درس" role="img" title="نمایش نوع درس"></canvas>
                                </div>
                            </div>
                        </div>
                    </li>
                `;
            courses.forEach(course => {
                html += `
					<li class="list-group-item d-flex justify-content-between align-items-center course-item" style="cursor: pointer;" onclick="filterStudentsByCourse('${course.course_code}')">
						<span><span class="text-secondary">${course.course_code}</span> - ${course.course_name}</span>
                        <div>
                            <span class="badge bg-secondary me-2">${course.student_count}</span>
                            <span class="badge bg-${course.course_type === 'کتبی' ? 'success' : 'info'}">${course.course_type}</span>
                        </div>
					</li>
				`;
            });
            html += `
				</ul>
			`;
        }

        html += `</div>`;

        if (students && students.length > 0) {
            html += `
				<div>
					<h5 class="text-primary mb-3">لیست دانشجویان</h5>
					<div class="table-responsive">
						<table class="table table-striped table-hover">
							<thead class="table-light">
								<tr>
									<th>ردیف</th>
									<th>شماره دانشجویی</th>
									<th>نام خانوادگی</th>
									<th>نام</th>
									<th>کد درس</th>
									<th>نام درس</th>
									<th>شماره صندلی</th>
									<th>کلاس</th>
									<th>نوع آزمون</th>
								</tr>
							</thead>
							<tbody id="studentsTableBody">
			`;

            students.forEach((student, index) => {
                html += `
					<tr>
						<td>${index + 1}</td>
						<td>${student.student_id}</td>
						<td><span class="text-secondary">${student.last_name}</span></td>
						<td>${student.first_name}</td>
						<td>${student.course_code}</td>
						<td>${student.course_name}</td>
						<td><span class="text-secondary">${student.seat_number}</span></td>
						<td>${student.class_name}</td>
                        <td><span class="badge ${getExamBadgeClass(student.exam_type)}">${student.exam_type}</span></td>
					</tr>
				`;
            });

            html += `
							</tbody>
						</table>
					</div>
				</div>
			`;
        } else {
            html += '<p class="text-muted text-center mt-3">هیچ دانشجویی در این آزمون ثبت‌نام نکرده است.</p>';
        }

        document.getElementById('reportContent').innerHTML = html;
        document.getElementById('reportCard').style.display = 'block';
        setTimeout(scrollReportCardIntoView, 100);
        // Render mini pies for this report
        try { renderMiniPiesFromReport(data); } catch (e) { console.error('Could not render mini pies:', e); }
        // clear any custom title after rendering
        try { delete window.customExamReportTitle; } catch (e) { window.customExamReportTitle = undefined; }

    } catch (error) {
        console.error('Error:', error);
        if (!error?.isLicenseError) {
            Swal.fire({
                icon: 'error',
                title: 'خطا',
                text: 'خطا در دریافت اطلاعات',
                confirmButtonText: 'باشه',
                customClass: {
                    popup: 'swal2-rtl swal2-glass',
                    confirmButton: 'btn btn-primary'
                }
            });
        }
    }
}

// Mini pie chart instances for the report card
let miniPieInstances = { course: null, examType: null, courseType: null };
let largePieInstance = null;

function destroyMiniPies() {
    try {
        Object.values(miniPieInstances).forEach(inst => { if (inst && typeof inst.destroy === 'function') inst.destroy(); });
    } catch (e) { /* ignore */ }
    miniPieInstances = { course: null, examType: null, courseType: null };
}

function renderMiniPiesFromReport(data) {
    // data: response from getNextExamReport.php
    try {
        const courses = data.courses || [];
        const examTypeCounts = data.examTypeCounts || {};
        const courseTypeCounts = data.courseTypeCounts || {};

        // Prepare course pie data (limit to top 10 to avoid clutter)
        const courseLabels = courses.map(c => `${c.course_name}`);
        const courseValues = courses.map(c => Number(c.student_count) || 0);

        // Exam type
        const examLabels = Object.keys(examTypeCounts);
        const examValues = examLabels.map(k => Number(examTypeCounts[k]) || 0);

        // Course type
        const ctLabels = Object.keys(courseTypeCounts);
        const ctValues = ctLabels.map(k => Number(courseTypeCounts[k]) || 0);

        // Colors
        const palette = ['#1a6fa6', '#ff8a65', '#7bd5ff', '#9ccc65', '#ffca28', '#7e57c2', '#26a69a', '#ef5350', '#90a4ae', '#5c6bc0'];

        destroyMiniPies();

        // Course pie (DPR-aware)
        (function () {
            const miniCourseEl = document.getElementById('miniPieCourse');
            if (!miniCourseEl) return;
            const ratio = window.devicePixelRatio || 1;
            const cssW = miniCourseEl.clientWidth || 140;
            const cssH = miniCourseEl.clientHeight || 140;
            // set backing store size to CSS pixels * DPR
            miniCourseEl.width = Math.max(1, Math.floor(cssW * ratio));
            miniCourseEl.height = Math.max(1, Math.floor(cssH * ratio));
            // keep CSS size unchanged
            miniCourseEl.style.width = cssW + 'px';
            miniCourseEl.style.height = cssH + 'px';
            const cCtx = miniCourseEl.getContext('2d');
            try { cCtx.setTransform(ratio, 0, 0, ratio, 0, 0); } catch (e) { /* ignore if not supported */ }
            miniPieInstances.course = new Chart(cCtx, {
                type: 'pie',
                data: { labels: courseLabels, datasets: [{ data: courseValues, backgroundColor: courseLabels.map((_, i) => palette[i % palette.length]), borderColor: 'rgba(255,255,255,0.6)', borderWidth: 4 }] },
                options: {
                    responsive: false,
                    devicePixelRatio: ratio,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            enabled: true,
                            displayColors: false,
                            title: { display: false },
                            bodyFont: { family: 'Vazir, sans-serif' },
                            callbacks: {
                                label: function (context) {
                                    const v = context.raw || 0;
                                    try { return toPersianDigits(v); } catch (e) { return String(v); }
                                }
                            }
                        }
                    }
                }
            });
        })();

        // Exam type pie (DPR-aware)
        (function () {
            const miniExamEl = document.getElementById('miniPieExamType');
            if (!miniExamEl) return;
            const ratio = window.devicePixelRatio || 1;
            const cssW = miniExamEl.clientWidth || 140;
            const cssH = miniExamEl.clientHeight || 140;
            miniExamEl.width = Math.max(1, Math.floor(cssW * ratio));
            miniExamEl.height = Math.max(1, Math.floor(cssH * ratio));
            miniExamEl.style.width = cssW + 'px';
            miniExamEl.style.height = cssH + 'px';
            const eCtx = miniExamEl.getContext('2d');
            try { eCtx.setTransform(ratio, 0, 0, ratio, 0, 0); } catch (e) { }
            miniPieInstances.examType = new Chart(eCtx, {
                type: 'pie',
                data: { labels: examLabels, datasets: [{ data: examValues, backgroundColor: examLabels.map((_, i) => palette[i % palette.length]), borderColor: 'rgba(255,255,255,0.6)', borderWidth: 4 }] },
                options: {
                    responsive: false,
                    devicePixelRatio: ratio,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            enabled: true,
                            displayColors: false,
                            title: { display: false },
                            bodyFont: { family: 'Vazir, sans-serif' },
                            callbacks: {
                                label: function (context) {
                                    const v = context.raw || 0;
                                    try { return toPersianDigits(v); } catch (e) { return String(v); }
                                }
                            }
                        }
                    }
                }
            });
        })();

        // Course type pie (DPR-aware)
        (function () {
            const miniCtEl = document.getElementById('miniPieCourseType');
            if (!miniCtEl) return;
            const ratio = window.devicePixelRatio || 1;
            const cssW = miniCtEl.clientWidth || 140;
            const cssH = miniCtEl.clientHeight || 140;
            miniCtEl.width = Math.max(1, Math.floor(cssW * ratio));
            miniCtEl.height = Math.max(1, Math.floor(cssH * ratio));
            miniCtEl.style.width = cssW + 'px';
            miniCtEl.style.height = cssH + 'px';
            const ctCtx = miniCtEl.getContext('2d');
            try { ctCtx.setTransform(ratio, 0, 0, ratio, 0, 0); } catch (e) { }
            miniPieInstances.courseType = new Chart(ctCtx, {
                type: 'pie',
                data: { labels: ctLabels, datasets: [{ data: ctValues, backgroundColor: ctLabels.map((_, i) => palette[i % palette.length]), borderColor: 'rgba(255,255,255,0.6)', borderWidth: 4 }] },
                options: {
                    responsive: false,
                    devicePixelRatio: ratio,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            enabled: true,
                            displayColors: false,
                            title: { display: false },
                            bodyFont: { family: 'Vazir, sans-serif' },
                            callbacks: {
                                label: function (context) {
                                    const v = context.raw || 0;
                                    try { return toPersianDigits(v); } catch (e) { return String(v); }
                                }
                            }
                        }
                    }
                }
            });
        })();

        // Make canvases clickable to open large view (buttons removed)
        try {
            const miniCourseEl = document.getElementById('miniPieCourse');
            if (miniCourseEl) miniCourseEl.onclick = () => showLargePie('فراوانی دروس', courseLabels, courseValues, palette);
        } catch (e) { /* ignore */ }
        try {
            const miniExamEl = document.getElementById('miniPieExamType');
            if (miniExamEl) miniExamEl.onclick = () => showLargePie('نوع آزمون', examLabels, examValues, palette);
        } catch (e) { /* ignore */ }
        try {
            const miniCourseTypeEl = document.getElementById('miniPieCourseType');
            if (miniCourseTypeEl) miniCourseTypeEl.onclick = () => showLargePie('نوع درس', ctLabels, ctValues, palette);
        } catch (e) { /* ignore */ }

        // Add a "چاپ شماره صندلی" button next to the mini pies for printable seat list
        try {
            const miniSection = document.getElementById('miniPieSection');
            if (miniSection) {
                // Rewrap the existing mini-pie canvases so layout stays consistent.
                const container = miniSection.querySelector('.d-flex.flex-row');
                if (container) {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'd-flex align-items-center gap-2';
                    while (container.firstChild) wrapper.appendChild(container.firstChild);
                    container.appendChild(wrapper);
                }
            }
        } catch (e) { /* ignore */ }

    } catch (err) {
        console.error('Error rendering mini pies:', err);
    }
}

function showLargePie(title, labels, values, palette) {
    try {
        Swal.fire({
            title: title,
            html: `<div style="width:100%;height:380px"><canvas id="largePieCanvas" style="width:100%;height:100%"></canvas></div>`,
            width: '50rem',
            showCancelButton: false,
            confirmButtonText: 'باشه',
            customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' },
            willOpen: () => {
                // placeholder
            },
            didOpen: () => {
                try { if (largePieInstance) largePieInstance.destroy(); } catch (e) { }
                const ctx = document.getElementById('largePieCanvas').getContext('2d');
                largePieInstance = new Chart(ctx, {
                    type: 'doughnut',
                    data: { labels: labels, datasets: [{ data: values, backgroundColor: labels.map((_, i) => palette[i % palette.length]), borderColor: 'rgba(255,255,255,0.85)', borderWidth: 6 }] },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '30%',
                        plugins: {
                            // legend removed per request (we'll use tooltips instead)
                            legend: { display: false },
                            tooltip: {
                                enabled: true,
                                displayColors: false,
                                title: { display: false },
                                bodyFont: { family: 'Vazir, sans-serif' },
                                callbacks: {
                                    // remove title duplication and show a single label line with Persian numbers
                                    title: function () { return ''; },
                                    label: function (context) {
                                        const v = context.raw || 0;
                                        const label = context.label || context.dataset && context.dataset._rawLabel || '';
                                        const valText = (typeof toPersianDigits === 'function') ? toPersianDigits(v) : String(v);
                                        return `${label}: ${valText}`;
                                    }
                                }
                            }
                        }
                    }
                });
            },
            willClose: () => {
                try { if (largePieInstance) { largePieInstance.destroy(); largePieInstance = null; } } catch (e) { }
            }
        });
    } catch (err) {
        console.error('Error showing large pie:', err);
    }
}

// Build and open a printable seat numbers report. Uses window.allStudents (set by showNextExamReport)
function printSeatNumbersReport() {
    try {
        const students = Array.isArray(window.allStudents) ? window.allStudents.slice() : [];
        if (!students.length) {
            return Swal.fire({ icon: 'info', title: 'اطلاعات', text: 'هیچ دانشجویی برای چاپ یافت نشد', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
        }

        // Show loading modal while preparing the printable document
        Swal.fire({
            title: 'در حال ساخت گزارش شماره صندلی',
            html: 'لطفاً منتظر بمانید...',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); },
            customClass: { popup: 'swal2-rtl swal2-glass' },
            showConfirmButton: false
        });

        // Normalize fields and sort by numeric seat_number when possible
        const normalize = (s) => ({
            first_name: s.first_name || s.firstname || s.name || '',
            last_name: s.last_name || s.lastname || s.lastName || '',
            course_name: s.course_name || s.courseName || s.course || '',
            seat_number: (typeof s.seat_number !== 'undefined') ? String(s.seat_number) : ''
        });

        const entries = students.map(normalize);

        // Sort by last name then first name (Persian-aware locale where possible)
        entries.sort((a, b) => {
            const lnA = (a.last_name || '').trim();
            const lnB = (b.last_name || '').trim();
            if (lnA !== lnB) return lnA.localeCompare(lnB, 'fa') || lnA.localeCompare(lnB);
            const fnA = (a.first_name || '').trim();
            const fnB = (b.first_name || '').trim();
            return fnA.localeCompare(fnB, 'fa') || fnA.localeCompare(fnB);
        });

        // Pagination: FORCE 50 entries per printed page as requested. Use two columns (25+25) for density.
        const twoColumn = true; // force two-column to maximize rows per page for printed layout
        const perPageTotal = 50; // fixed to 50 per page
        const perColumn = twoColumn ? Math.floor(perPageTotal / 2) : perPageTotal; // 25 when two-column
        const perPage = perPageTotal;
        const totalPages = Math.max(1, Math.ceil(entries.length / perPage));

        // Build printable HTML
        const title = document.querySelector('#nextExamDateTime')?.textContent || '';
        const university = (document.getElementById('footerText')?.textContent) || '';

        function esc(txt) { const d = document.createElement('div'); d.textContent = txt || ''; return d.innerHTML; }

        const fontHref = (window.location && window.location.origin ? window.location.origin : '') + '/assets/fonts/vazir/vazir.css';
        let docHtml = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>گزارش شماره صندلی</title><link rel="stylesheet" href="${fontHref}">`;
        docHtml += `<style>
            /* Use A4 landscape for printing (افقی). Compact margins for denser layout. */
            @page { size: A4 landscape; margin: 4mm; }
                /* Increased font for readability while still attempting 50 rows per page. */
            body { font-family: Vazir, Tahoma, Arial, sans-serif; color: #111; font-size: 8.5pt; }
            .report-header { text-align: center; margin-bottom: 4px; }
            .report-title { font-size: 12pt; font-weight: 700; margin-bottom:2px }
            /* Show only date/time here (no "نسار - university"). Make it more prominent. */
            .report-meta { font-size: 20pt; color: #111; margin-top:2px; font-weight: 900; }
            .page { page-break-after: always; margin-bottom: 0; }
            table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 8.5pt; line-height: 1.06; }
            th, td { padding: 4px 6px; text-align: right; border-bottom: 0.5px solid #e0e0e0; font-size: 8.5pt; }
            thead th { background: #efefef; font-weight: 700; font-size: 9pt; padding: 5px 6px; text-align: center; }
            /* Center seat-number column specifically */
            .seat-col { text-align: center; }
            tbody tr:nth-child(odd) { background: #fafafa; }
            td { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .col-wrap { display: flex; gap: 6px; position: relative; }
            .col { width: 50%; }
            .col-wrap::before { content: ''; position: absolute; left: 50%; top: 0; bottom: 0; width: 0.6px; background: #bdbdbd; transform: translateX(-0.3px); }
            .col { padding-right: 6px; }
            .small-muted { color: #666; font-size: 8pt; }
            @media print {
                .no-print { display: none !important; }
                .page { page-break-after: always; }
            }
        </style>`;
        docHtml += `</head><body>`;

        for (let p = 0; p < totalPages; p++) {
            docHtml += `<div class="page">`;
            docHtml += `<div class="report-header"><div class="report-title">گزارش شماره صندلی</div>`;
            docHtml += `<div class="report-meta">${esc(title)}</div></div>`;

            if (twoColumn) {
                // left and right columns: take the page slice (up to perPage) and split it evenly
                const start = p * perPage;
                const pageSlice = entries.slice(start, start + perPage);
                const half = Math.ceil(pageSlice.length / 2);
                const left = pageSlice.slice(0, half);
                const right = pageSlice.slice(half);
                docHtml += `<div class="col-wrap">`;
                [left, right].forEach((colArr, colIndex) => {
                    docHtml += `<div class="col"><table><thead><tr><th style="width:6%">ردیف</th><th style="width:28%">نام و نام خانوادگی</th><th style="width:54%">نام درس</th><th class="seat-col" style="width:12%">صندلی</th></tr></thead><tbody>`;
                    colArr.forEach((row, idx) => {
                        // compute global index relative to the page slice
                        const globalIndex = start + (colIndex === 0 ? idx : half + idx);
                        docHtml += `<tr>`;
                        // Rowno
                        docHtml += `<td>${esc(globalIndex + 1)}</td>`;
                        // Display as "LastName FirstName"
                        const fullName = esc((row.last_name || '') + ' ' + (row.first_name || ''));
                        const courseName = esc(row.course_name || '');
                        docHtml += `<td title="${fullName}">${fullName}</td>`;
                        docHtml += `<td title="${courseName}">${courseName}</td>`;
                        docHtml += `<td class="seat-col">${esc(row.seat_number || '')}</td>`;
                        docHtml += `</tr>`;
                    });
                    docHtml += `</tbody></table></div>`;
                });
                docHtml += `</div>`;
            } else {
                const start = p * perPage;
                const slice = entries.slice(start, start + perPage);
                docHtml += `<div><table><thead><tr><th style="width:6%">ردیف</th><th style="width:28%">نام و نام خانوادگی</th><th style="width:54%">نام درس</th><th class="seat-col" style="width:12%">صندلی</th></tr></thead><tbody>`;
                slice.forEach((row, idx) => {
                    const globalIndex = start + idx;
                    docHtml += `<tr>`;
                    docHtml += `<td>${esc(globalIndex + 1)}</td>`;
                    const fullName2 = esc((row.last_name || '') + ' ' + (row.first_name || ''));
                    const courseName2 = esc(row.course_name || '');
                    docHtml += `<td title="${fullName2}">${fullName2}</td>`;
                    docHtml += `<td title="${courseName2}">${courseName2}</td>`;
                    docHtml += `<td class="seat-col">${esc(row.seat_number || '')}</td>`;
                    docHtml += `</tr>`;
                });
                docHtml += `</tbody></table></div>`;
            }

            docHtml += `</div>`; // .page
        }

        docHtml += `</body></html>`;

        // Dynamic fitting: try to render the first page inside a hidden iframe using decreasing font sizes
        // until 50 rows fit (or until minFont reached). If fit, print from the iframe; otherwise fall back.
        const desiredPerPage = perPage; // 50
        const firstPageEntries = entries.slice(0, desiredPerPage);

        // Increase the starting font to be more readable per user's request.
        const minFontPt = 6.5;
        let testFontPt = 9.5; // starting point (increased for readability)
        let fits = false;

        // helper to build a single-page HTML used for measurement
        function buildSinglePageHtml(fontPt) {
            const fh = (window.location && window.location.origin ? window.location.origin : '') + '/assets/fonts/vazir/vazir.css';
            let h = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>پیش‌نمایش چاپ</title><link rel="stylesheet" href="${fh}">`;
            h += `<style>
                @page { size: A4 landscape; margin: 4mm; }
                html,body { margin:0; padding:0; }
                body { font-family: Vazir, Tahoma, Arial, sans-serif; color:#111; font-size: ${fontPt}pt; }
                .page { width: 297mm; height: 210mm; box-sizing: border-box; overflow: hidden; padding: 6mm; }
                table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: ${fontPt}pt; }
                th, td { padding: 3px 5px; text-align: right; border-bottom: 0.5px solid #e0e0e0; }
                thead th { background:#efefef; font-weight:700; text-align: center; }
                .seat-col { text-align: center; }
                .col-wrap { display:flex; gap:6px; position:relative; }
                .col { width:50%; }
            </style></head><body>`;
            h += `<div class="page">`;
            // create balanced two-column layout for firstPageEntries
            const half = Math.ceil(firstPageEntries.length / 2);
            const left = firstPageEntries.slice(0, half);
            const right = firstPageEntries.slice(half);
            h += `<div class="col-wrap">`;
            [left, right].forEach((colArr, ci) => {
                h += `<div class="col"><table><thead><tr><th style="width:6%">ردیف</th><th style="width:28%">نام و نام خانوادگی</th><th style="width:54%">نام درس</th><th style="width:12%">صندلی</th></tr></thead><tbody>`;
                colArr.forEach((r, idx) => {
                    const globalIndex = (ci === 0 ? idx : half + idx);
                    const fullName = (r.last_name || '') + ' ' + (r.first_name || '');
                    const courseName = r.course_name || '';
                    h += `<tr><td>${globalIndex + 1}</td><td title="${fullName}">${fullName}</td><td title="${courseName}">${courseName}</td><td class="seat-col">${r.seat_number || ''}</td></tr>`;
                });
                h += `</tbody></table></div>`;
            });
            h += `</div></div></body></html>`;
            return h;
        }

        // create hidden iframe for testing
        const iframe = document.createElement('iframe');
        iframe.style.position = 'fixed';
        iframe.style.left = '-10000px';
        iframe.style.top = '0';
        iframe.style.width = '297mm';
        iframe.style.height = '210mm';
        iframe.style.border = '0';
        iframe.style.visibility = 'hidden';
        document.body.appendChild(iframe);

        const tryFit = () => new Promise(resolve => {
            try {
                const doc = iframe.contentDocument || iframe.contentWindow.document;
                doc.open();
                doc.write(buildSinglePageHtml(testFontPt));
                doc.close();
                // allow layout to settle
                setTimeout(() => {
                    const pageEl = doc.querySelector('.page');
                    if (!pageEl) return resolve(false);
                    // scrollHeight vs clientHeight to detect overflow
                    const fitsNow = pageEl.scrollHeight <= pageEl.clientHeight + 1; // allow 1px leeway
                    resolve(fitsNow);
                }, 180);
            } catch (e) { resolve(false); }
        });

        (async () => {
            while (testFontPt >= minFontPt) {
                // try fit with current font
                // eslint-disable-next-line no-await-in-loop
                fits = await tryFit();
                if (fits) break;
                testFontPt = Math.round((testFontPt - 0.25) * 100) / 100; // step down
            }

            if (fits) {
                // print using the fitting iframe (full document with same font)
                try {
                    const fullDoc = iframe.contentDocument || iframe.contentWindow.document;
                    // replace body with full docHtml but adjust only general font sizes to testFontPt
                    // Use a targeted regex for numeric pt values to avoid touching .report-meta specifically,
                    // then inject a high-specificity rule to ensure the date/time stays large and bold.
                    let finalHtml = docHtml.replace(/font-size:\s*[\d.]+pt;/g, `font-size: ${testFontPt}pt;`);
                    // Ensure report-meta remains prominent regardless of global replacements
                    finalHtml = finalHtml.replace('</head>', `<style>.report-meta{font-size:20pt !important; font-weight:900 !important;}</style></head>`);
                    fullDoc.open();
                    fullDoc.write(finalHtml);
                    fullDoc.close();
                    // close loading modal before opening print dialog
                    try { Swal.close(); } catch (e) { }
                    setTimeout(() => {
                        try { iframe.contentWindow.focus(); iframe.contentWindow.print(); } catch (e) { console.error('Print error:', e); }
                        // remove iframe after a short delay
                        setTimeout(() => { try { document.body.removeChild(iframe); } catch (e) { } }, 500);
                    }, 300);
                } catch (e) {
                    document.body.removeChild(iframe);
                    try { Swal.close(); } catch (er) { }
                    Swal.fire({ icon: 'error', title: 'خطا', text: 'خطا در چاپ از iframe', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
                }
            } else {
                // cleanup test iframe
                try { document.body.removeChild(iframe); } catch (e) { }
                // fallback: reduce perPage to 44 and print in a new window
                const fallbackPerPage = 44;
                const pages = Math.max(1, Math.ceil(entries.length / fallbackPerPage));
                let fallbackHtml = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>گزارش شماره صندلی</title><link rel="stylesheet" href="${fontHref}">`;
                fallbackHtml += `<style>@page{size:A4 landscape;margin:6mm;} body{font-family:Vazir, Tahoma, Arial, sans-serif;font-size:8.5pt;} table{width:100%;border-collapse:collapse;table-layout:fixed;} th,td{padding:4px 6px;text-align:right;border-bottom:0.5px solid #e0e0e0;}</style></head><body>`;
                for (let p = 0; p < pages; p++) {
                    const startIdx = p * fallbackPerPage;
                    const slice = entries.slice(startIdx, startIdx + fallbackPerPage);
                    fallbackHtml += `<div class="page"><table><thead><tr><th>ردیف</th><th>نام</th><th>درس</th><th class="seat-col">صندلی</th></tr></thead><tbody>`;
                    slice.forEach((r, i) => { fallbackHtml += `<tr><td>${startIdx + i + 1}</td><td>${esc((r.last_name || '') + ' ' + (r.first_name || ''))}</td><td>${esc(r.course_name || '')}</td><td class="seat-col">${esc(r.seat_number || '')}</td></tr>`; });
                    fallbackHtml += `</tbody></table></div>`;
                }
                fallbackHtml += `</body></html>`;
                const w = window.open('', '_blank');
                if (!w) {
                    return Swal.fire({ icon: 'error', title: 'خطا', text: 'لطفاً باز شدن پنجره جدید را در مرورگر فعال کنید', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
                }
                w.document.open(); w.document.write(fallbackHtml); w.document.close();
                try { Swal.close(); } catch (er) { }
                setTimeout(() => { try { w.focus(); w.print(); } catch (e) { console.error('Print error:', e); } }, 450);
            }
        })();

    } catch (err) {
        console.error('Error building printable report:', err);
        Swal.fire({ icon: 'error', title: 'خطا', text: 'خطا در آماده‌سازی گزارش چاپ', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
    }
}

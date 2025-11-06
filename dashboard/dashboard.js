function isDesktopDevice() {
    const isTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 1;
    const width = window.innerWidth || document.documentElement.clientWidth;
    const userAgent = navigator.userAgent.toLowerCase();
    const isMobileUA = /android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini/.test(userAgent);
    return !isTouch && width > 900 && !isMobileUA;
}


try {
    window.addEventListener('afterprint', () => {
        try { closeSwalLoadingHard(); } catch (e) { }
        try { reopenEssentialsMenuIfRequested(); } catch (e) { }
    }, false);
} catch (e) { }


function closeSwalLoadingHard() {
    try {
        if (window.Swal && typeof Swal.close === 'function') {
            try { Swal.close(); } catch (e) { }
        }
    } catch (e) { }
    try {
        const nodes = document.querySelectorAll('.swal2-container, .swal2-popup, .swal2-backdrop-show, .swal2-loading, .swal2-actions.swal2-loading');
        nodes.forEach(el => {
            try {
                const isLoading = el.getAttribute && el.getAttribute('data-loading') === 'true';
                if (isLoading || el.classList.contains('swal2-loading')) {
                    el.remove();
                }
            } catch (e) { }
        });
    } catch (e) { }
}


function safePrintIframe(iframe, cw) {
    try {
        const idoc = iframe.contentDocument || iframe.contentWindow.document;
        const fontsReady = idoc && idoc.fonts && idoc.fonts.ready ? idoc.fonts.ready : Promise.resolve();

        Promise.race([fontsReady, new Promise(r => setTimeout(r, 1500))]).then(() => {
            try { cw.focus(); cw.print(); } catch (e) { console.error('Print error:', e); }
        }).catch(() => { try { cw.focus(); cw.print(); } catch (e) { console.error('Print error:', e); } });
    } catch (e) {
        try { cw.focus(); cw.print(); } catch (err) { console.error('Print error:', err); }
    }
}


function safePrintWindow(win) {
    try {
        const idoc = (win && win.document) ? win.document : null;
        const fontsReady = idoc && idoc.fonts && idoc.fonts.ready ? idoc.fonts.ready : Promise.resolve();
        Promise.race([fontsReady, new Promise(r => setTimeout(r, 1500))]).then(() => {
            try { win.focus(); win.print(); } catch (e) { console.error('Print error (window):', e); }
        }).catch(() => { try { win.focus(); win.print(); } catch (e) { console.error('Print error (window):', e); } });
    } catch (e) {
        try { win.focus(); win.print(); } catch (err) { console.error('Print error (window):', err); }
    }
}


function reopenEssentialsMenuIfRequested() {
    try {
        if (window._reopenEssentialsMenu) {
            window._reopenEssentialsMenu = false;
            setTimeout(() => { try { examEssentialsHandler(); } catch (e) { console.error('Reopen menu failed:', e); } }, 200);
        }
    } catch (e) { }
}



async function printSessionReport() {
    try {
        let context = window._overrideExamContext && window._overrideExamContext.active ? window._overrideExamContext : window._lastExamContext;
        let examDate = context?.exam_date;
        let examTime = context?.exam_time;

        if (!examDate || !examTime) {
            const nextExamDateTimeText = document.getElementById('nextExamDateTime')?.textContent || '';
            if (!nextExamDateTimeText || nextExamDateTimeText === 'بارگذاری...' || nextExamDateTimeText === 'آزمونی یافت نشد') {
                return Swal.fire({ icon: 'info', title: 'اطلاعات', text: 'آزمون بعدی یافت نشد', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
            }

            const parts = nextExamDateTimeText.split('|').map(s => s.trim());
            if (parts.length !== 2) {
                return Swal.fire({ icon: 'error', title: 'خطا', text: 'فرمت تاریخ و ساعت نامعتبر است', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
            }
            examTime = toEnglishDigits(parts[0]);
            examDate = toEnglishDigits(parts[1]).replace(/-/g, '/');
            setLastExamContext(examDate, examTime);
        } else {
            examTime = toEnglishDigits(examTime);
            examDate = toEnglishDigits(examDate).replace(/-/g, '/');
            setLastExamContext(examDate, examTime);
        }

        const resp = await guardedFetch(`../API/getNextExamReport.php?exam_date=${encodeURIComponent(examDate)}&exam_time=${encodeURIComponent(examTime)}`, { cache: 'no-store' });
        const data = await resp.json();
        if (data.error) {
            return Swal.fire({ icon: 'error', title: 'خطا', text: data.error || 'خطا در دریافت اطلاعات', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
        }

        window.currentExamReport = data;
        setLastExamContext(data.exam_date, data.exam_time);

        const courses = Array.isArray(data.courses) ? data.courses.slice() : [];
        if (!courses.length) {
            return Swal.fire({ icon: 'info', title: 'اطلاعات', text: 'هیچ درسی برای این جلسه وجود ندارد', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
        }


        Swal.fire({
            title: 'در حال ساخت صورتجلسه',
            html: 'لطفاً منتظر بمانید...',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); },
            customClass: { popup: 'swal2-rtl swal2-glass' },
            showConfirmButton: false
        });


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




        const perPage = 15;
        const pages = Math.ceil(courses.length / perPage);


        let cfg = {};
        try {
            const cfgResp = await guardedFetch('../API/getConfig.php', { cache: 'no-store' });
            if (cfgResp && cfgResp.ok) cfg = await cfgResp.json();
        } catch (e) { }
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
                '<th style="width:12%">نوع درس</th>' +
                '<th style="width:8%">کد درس</th>' +
                '<th style="width:54%">نام درس</th>' +
                '<th style="width:10%">تعداد</th>' +
                '<th style="width:12%">حاضر / غایب</th>' +
                '</tr></thead><tbody>';
            slice.forEach((c, idx) => {
                const code = esc(c.course_code || '');
                const name = esc(c.course_name || '');
                const typeofCRS = esc(c.course_type || '');
                const count = Number(c.student_count || c.count || 0) || 0;
                // Placeholder text for manual filling: حاضرین ____ نفر / غایبین ____ نفر
                const statsPlaceholder = ' ___  /  ___ ';
                t += `<tr>` +
                    `<td class="center">${toPersianDigits(startIndex + idx + 1)}</td>` +
                    `<td class="center">${typeofCRS}</td>` +
                    `<td class="center">${code}</td>` +
                    `<td class="name" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 150px;">${name}</td>` +
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
            const cw = iframe.contentWindow;
            let cleaned = false;
            const cleanup = () => {
                if (cleaned) return;
                cleaned = true;
                try { closeSwalLoadingHard(); } catch (e) { }
                try { document.body.removeChild(iframe); } catch (e) { }
                try { window.removeEventListener('focus', onFocusOnce, true); } catch (e) { }
                try { reopenEssentialsMenuIfRequested(); } catch (e) { }
            };
            const onFocusOnce = () => { setTimeout(cleanup, 150); };
            try {
                if (cw) {
                    cw.onafterprint = cleanup;
                    window.addEventListener('focus', onFocusOnce, true);
                    try { safePrintIframe(iframe, cw); } catch (e) { console.error('Print error:', e); }
                    setTimeout(cleanup, 5000);
                } else {
                    setTimeout(cleanup, 300);
                }
            } catch (e) {
                console.error('Print error:', e);
                setTimeout(cleanup, 300);
            }
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

function setLastExamContext(examDate, examTime) {
    if (!examDate || !examTime) return;
    const normalizedDate = toEnglishDigits(String(examDate)).replace(/-/g, '/').trim();
    const normalizedTime = toEnglishDigits(String(examTime)).trim();
    window._lastExamContext = {
        exam_date: normalizedDate,
        exam_time: normalizedTime
    };
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
document.getElementById('logoutBtn').addEventListener('click', async () => {
    const result = await Swal.fire({
        title: 'تأیید خروج',
        text: 'آیا مطمئن هستید که می‌خواهید از داشبورد خارج شوید؟',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'بله',
        cancelButtonText: 'لغو',
        reverseButtons: true,
        customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-danger', cancelButton: 'btn btn-cancel' }
    });

    if (result.isConfirmed) {
        document.cookie = 'adminSession=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
        window.location.href = '../';
    }
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
                text: 'این عمل باعث تغییر اطلاعات صورتجلسه‌ها و گزارشات آزمون خواهد شد. آیا مطمئن هستید که می‌خواهید ادامه دهید؟',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'بله، ادامه',
                cancelButtonText: 'لغو',
                reverseButtons: true,
                customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-danger', cancelButton: 'btn btn-cancel' }
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
            const groupByCourseChecked = String(cfg.GroupByCourse || '').toUpperCase() === 'YES';

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

                        <div style="margin-top:10px; display:flex; align-items:center; gap:8px;">
                            <input id="er_groupByCourse" type="checkbox" ${groupByCourseChecked ? 'checked' : ''} style="width:1.15rem;height:1.15rem;">
                            <label for="er_groupByCourse" style="margin:0;cursor:pointer;">مرتب‌سازی صندلی‌ها براساس درس</label>
                        </div>
                    </div>
                </div>`;

            const modalResult = await Swal.fire({
                title: 'ویرایش نقش‌ها و تنظیمات',
                html: formHtml,
                showCancelButton: true,
                confirmButtonText: 'ذخیره',
                cancelButtonText: 'انصراف',
                focusConfirm: false,
                // Ensure buttons match the modal's styling
                customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary', cancelButton: 'btn btn-cancel' },
                preConfirm: () => {
                    const admin = document.getElementById('er_admin')?.value || '';
                    const boss = document.getElementById('er_boss')?.value || '';
                    const head = document.getElementById('er_head')?.value || '';
                    const chair = document.getElementById('er_chair')?.value || '';
                    const groupByCourse = (document.getElementById('er_groupByCourse')?.checked) ? 'YES' : 'NO';
                    // return values to then handle save confirmation
                    return { AdminNickName: admin.trim(), BossNickName: boss.trim(), HeadOfEDU: head.trim(), Chairman: chair.trim(), GroupByCourse: groupByCourse };
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
                customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary', cancelButton: 'btn btn-cancel' }
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
                    // Update global config for immediate effect
                    window.appConfig.GroupByCourse = values.GroupByCourse;
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

// Info & statistics modal populated from quickInsights payload
try {
    const infoBtn = document.getElementById('infoStatsBtn');
    if (infoBtn) {
        infoBtn.addEventListener('click', async () => {
            const escapeAttr = (value) => String(value ?? '').replace(/["'&<>]/g, ch => ({ '"': '&quot;', "'": '&#39;', '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ch]));
            const insightDefinitions = [
                { key: 'busiestSession', label: 'شلوغ‌ترین جلسه آزمون', category: 'session', variant: 'insight-busy', valueKey: 'student_count', unit: 'نفر' },
                { key: 'quietestSession', label: 'خلوت‌ترین جلسه آزمون', category: 'session', variant: 'insight-quiet', valueKey: 'student_count', unit: 'نفر' },
                { key: 'maxCourseFrequency', label: 'بیشترین تعداد درس در جلسه', category: 'session', variant: 'insight-course', valueKey: 'course_count', unit: 'درس' },
                { key: 'maxWritten', label: 'بیشترین تعداد کتبی', category: 'session', variant: 'insight-written', valueKey: 'student_count', unit: 'نفر' },
                { key: 'maxElectronic', label: 'بیشترین تعداد الکترونیکی', category: 'session', variant: 'insight-electronic', valueKey: 'student_count', unit: 'نفر' }
            ];

            try {
                const response = await guardedFetch('../API/getStatistics.php', { cache: 'no-store' });
                const stats = await response.json();

                if (stats.error) {
                    await Swal.fire({
                        icon: 'error',
                        title: 'خطا',
                        text: stats.error,
                        confirmButtonText: 'باشه',
                        customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' }
                    });
                    return;
                }

                const insights = stats.quickInsights || {};
                const cards = [];

                insightDefinitions.forEach(def => {
                    const entry = insights[def.key];
                    if (!entry) return;

                    const rawValue = def.valueKey ? entry[def.valueKey] : (entry.student_count ?? entry.count ?? entry.value ?? 0);
                    const count = Number(rawValue ?? 0);
                    if (!Number.isFinite(count) || count < 0) return;

                    const unitText = def.unit || (def.category === 'session' ? 'نفر' : '');
                    const displayCount = unitText ? `${toPersianDigits(count)} ${unitText}` : toPersianDigits(count);

                    const rawTime = entry.exam_time || '';
                    const rawDate = entry.exam_date || '';
                    const line2Parts = [];
                    if (rawTime) line2Parts.push(rawTime);
                    if (rawDate) line2Parts.push(rawDate);
                    const displayLine2 = line2Parts.length ? toPersianDigits(line2Parts.join(' | ')) : 'بدون تاریخ';
                    const displayCourseCode = entry.course_code ? toPersianDigits(entry.course_code) : '';
                    let displayLabelText = def.label;
                    if (def.category === 'course' && displayCourseCode) {
                        displayLabelText = `${displayLabelText} - ${displayCourseCode}`;
                    }
                    const tieCount = Number(entry.tie_count || 0);
                    if (tieCount > 1) {
                        displayLabelText = `${displayLabelText} (x${tieCount})`;
                    }

                    const classes = ['session-mini-card', 'insight-card', def.variant].filter(Boolean).join(' ');
                    const attributes = [
                        `data-insight-type="${escapeAttr(def.category)}"`,
                        `data-label="${escapeAttr(displayLabelText)}"`,
                        `data-label-base="${escapeAttr(def.label)}"`,
                        `data-count="${escapeAttr(count)}"`,
                        `data-display-line2="${escapeAttr(displayLine2)}"`,
                        `data-tie-count="${escapeAttr(tieCount)}"`
                    ];

                    if (rawDate) attributes.push(`data-exam-date="${escapeAttr(rawDate)}"`);
                    if (rawTime) attributes.push(`data-exam-time="${escapeAttr(rawTime)}"`);
                    if (entry.course_code) attributes.push(`data-course-code="${escapeAttr(entry.course_code)}"`);
                    if (entry.course_name) attributes.push(`data-course-name="${escapeAttr(entry.course_name)}"`);
                    if (entry.course_type) attributes.push(`data-course-type="${escapeAttr(entry.course_type)}"`);
                    if (Array.isArray(entry.matches) && entry.matches.length) {
                        try {
                            attributes.push(`data-matches="${escapeAttr(JSON.stringify(entry.matches))}"`);
                        } catch (jsonErr) {
                            console.warn('Failed to encode matches for insight', def.key, jsonErr);
                        }
                    }

                    cards.push(`
                        <div class="${classes}" ${attributes.join(' ')}>
                            <div class="line1">${escapeAttr(displayCount)}</div>
                            <div class="line2">${escapeAttr(displayLine2)}</div>
                            <div class="line3">${escapeAttr(displayLabelText)}</div>
                        </div>
                    `);
                });

                if (!cards.length) {
                    await Swal.fire({
                        icon: 'info',
                        title: 'اطلاعات',
                        text: 'داده‌ای برای نمایش وجود ندارد',
                        confirmButtonText: 'باشه',
                        customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' }
                    });
                    return;
                }

                const cardsHtml = `<div class="session-mini-grid insight-grid">${cards.join('')}</div>`;

                function openTieModal(labelText, labelBase, matches) {
                    let reopenMain = true;
                    const parseDateParts = (rawDate) => {
                        if (!rawDate) return { y: 0, m: 0, d: 0 };
                        const normalized = toEnglishDigits(String(rawDate || '')).replace(/-/g, '/');
                        const parts = normalized.split('/').map(p => parseInt(p, 10)).filter(n => Number.isFinite(n));
                        return {
                            y: parts[0] || 0,
                            m: parts[1] || 0,
                            d: parts[2] || 0
                        };
                    };
                    const parseTimeParts = (rawTime) => {
                        if (!rawTime) return { h: 0, min: 0, s: 0 };
                        const normalized = toEnglishDigits(String(rawTime || '')).replace(/[^0-9:]/g, '');
                        const parts = normalized.split(':').map(p => parseInt(p, 10)).filter(n => Number.isFinite(n));
                        return {
                            h: parts[0] || 0,
                            min: parts[1] || 0,
                            s: parts[2] || 0
                        };
                    };
                    const sortedMatches = Array.isArray(matches) ? matches.slice().sort((a, b) => {
                        const aDate = parseDateParts(a && a.exam_date);
                        const bDate = parseDateParts(b && b.exam_date);
                        if (aDate.y !== bDate.y) return aDate.y - bDate.y;
                        if (aDate.m !== bDate.m) return aDate.m - bDate.m;
                        if (aDate.d !== bDate.d) return aDate.d - bDate.d;
                        const aTime = parseTimeParts(a && a.exam_time);
                        const bTime = parseTimeParts(b && b.exam_time);
                        if (aTime.h !== bTime.h) return aTime.h - bTime.h;
                        if (aTime.min !== bTime.min) return aTime.min - bTime.min;
                        if (aTime.s !== bTime.s) return aTime.s - bTime.s;
                        return 0;
                    }) : [];
                    const cardsMarkup = sortedMatches.map(match => {
                        const mTime = match.exam_time || '';
                        const mDate = match.exam_date || '';
                        const timeDisplay = mTime ? toPersianDigits(mTime) : 'بدون ساعت';
                        const dateDisplay = mDate ? toPersianDigits(mDate) : 'بدون تاریخ';
                        const combined = `${timeDisplay} | ${dateDisplay}`;
                        return `
                            <div class="session-mini-card insight-card insight-subcard" data-exam-time="${escapeAttr(mTime)}" data-exam-date="${escapeAttr(mDate)}">
                                <div class="line1">${escapeAttr(combined)}</div>
                            </div>
                        `;
                    }).join('');

                    return Swal.fire({
                        title: labelText,
                        html: `<div class="session-mini-grid insight-grid">${cardsMarkup}</div>`,
                        width: '80rem',
                        showConfirmButton: false,
                        showCloseButton: true,
                        allowOutsideClick: true,
                        allowEscapeKey: true,
                        customClass: { popup: 'swal2-rtl swal2-glass' },
                        didOpen: () => {
                            const wrap = Swal.getHtmlContainer();
                            if (!wrap) return;
                            wrap.querySelectorAll('.insight-subcard').forEach(subEl => {
                                subEl.addEventListener('click', () => {
                                    const subDate = subEl.getAttribute('data-exam-date');
                                    const subTime = subEl.getAttribute('data-exam-time');
                                    reopenMain = false;
                                    Swal.close();
                                    setTimeout(() => {
                                        const persTime = subTime ? toPersianDigits(subTime) : '';
                                        const persDate = subDate ? toPersianDigits(subDate) : '';
                                        const customTitle = persTime && persDate ? `${labelBase} (${persTime} | ${persDate})` : labelBase;
                                        applyNextExamOverride(subDate, subTime, { customTitle });
                                        showNextExamReport();
                                    }, 150);
                                }, { once: true });
                            });
                        },
                        willClose: () => {
                            if (reopenMain) {
                                setTimeout(() => {
                                    openInsightsModal();
                                }, 120);
                            }
                        }
                    });
                }

                function openInsightsModal() {
                    return Swal.fire({
                        title: 'اطلاعات و آمار',
                        html: cardsHtml,
                        width: '110rem',
                        showConfirmButton: false,
                        showCloseButton: true,
                        allowOutsideClick: true,
                        allowEscapeKey: true,
                        customClass: { popup: 'swal2-rtl swal2-glass' },
                        didOpen: () => {
                            const container = Swal.getHtmlContainer();
                            if (!container) return;
                            const cardNodes = container.querySelectorAll('.session-mini-card.insight-card');
                            cardNodes.forEach(cardEl => {
                                const type = cardEl.getAttribute('data-insight-type');
                                const label = cardEl.getAttribute('data-label') || '';
                                const labelBase = cardEl.getAttribute('data-label-base') || label;
                                const displayLine2 = cardEl.getAttribute('data-display-line2') || '';
                                const examDate = cardEl.getAttribute('data-exam-date');
                                const examTime = cardEl.getAttribute('data-exam-time');
                                const courseCode = cardEl.getAttribute('data-course-code');
                                const courseName = cardEl.getAttribute('data-course-name');
                                const tieCount = Number(cardEl.getAttribute('data-tie-count') || 0);
                                const matchesJson = cardEl.getAttribute('data-matches');
                                const matches = (() => {
                                    if (!matchesJson) return [];
                                    try { return JSON.parse(matchesJson); } catch (e) { return []; }
                                })();

                                if (courseName) {
                                    const tooltip = courseCode ? `درس ${courseName} (کد ${courseCode})` : `درس ${courseName}`;
                                    cardEl.setAttribute('title', tooltip);
                                } else if (courseCode) {
                                    cardEl.setAttribute('title', `کد درس ${courseCode}`);
                                }

                                cardEl.addEventListener('click', () => {
                                    if (type === 'session' && tieCount > 1 && matches.length) {
                                        Swal.close();
                                        setTimeout(() => {
                                            openTieModal(label, labelBase, matches);
                                        }, 100);
                                    } else if (type === 'session' && examDate && examTime) {
                                        Swal.close();
                                        setTimeout(() => {
                                            applyNextExamOverride(examDate, examTime, { customTitle: `${label} (${displayLine2})` });
                                            showNextExamReport();
                                        }, 150);
                                    } else if (type === 'course' && courseCode) {
                                        Swal.close();
                                        setTimeout(() => {
                                            loadCourseReportByCode(courseCode, { showErrors: true });
                                        }, 150);
                                    }
                                }, { once: true });
                            });
                        }
                    });
                }

                await openInsightsModal();
            } catch (err) {
                console.error('Failed to load insights:', err);
                if (!err?.isLicenseError) {
                    Swal.fire({
                        icon: 'error',
                        title: 'خطا',
                        text: 'خطا در بارگیری آمار',
                        confirmButtonText: 'باشه',
                        customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' }
                    });
                }
            }
        });
    }
} catch (e) {
    console.warn('Failed to init info stats button', e);
}

// Proctor module button
try {
    const proctorBtn = document.getElementById('proctorBtn');
    if (proctorBtn) {
        proctorBtn.addEventListener('click', async () => {
            await Swal.fire({
                icon: 'info',
                title: 'ماژول عوامل اجرائی',
                text: 'این بخش برای مدیریت و استقرار عوامل اجرائی آزمون در دست توسعه است.',
                confirmButtonText: 'باشه',
                customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' }
            });
        });
    }
} catch (e) {
    console.warn('Failed to init proctor button', e);
}

// Update Database button: show temp table counts, warn and block in demo
try {
    const updateBtn = document.getElementById('updateDBBtn');
    if (updateBtn) {
        updateBtn.addEventListener('click', async () => {
            // Fetch current temp tables counts (e-exams / k-exams)
            let eCount = null, kCount = null;
            try {
                const resp = await guardedFetch('../API/getTempTablesCount.php', { cache: 'no-store' });
                if (resp && resp.ok) {
                    const data = await resp.json();
                    eCount = Number(data.e_exams ?? 0);
                    kCount = Number(data.k_exams ?? 0);
                }
            } catch (err) {
                // ignore, keep nulls
            }

            const toPd = (v) => (typeof toPersianDigits === 'function') ? toPersianDigits(v) : String(v);
            const eTxt = (eCount === null) ? 'نامشخص' : toPd(eCount);
            const kTxt = (kCount === null) ? 'نامشخص' : toPd(kCount);

            const warningHtml = `
                <div style="text-align:justify;line-height:2">
                با انجام این عملیات، تمامی داده‌های فعلی پایگاه داده حذف می‌شود و <b>${eTxt}</b> رکورد از آزمون‌های الکترونیکی و <b>${kTxt}</b> رکورد از آزمون‌های کتبی برای جایگزینی داده‌های حذف‌شده استفاده خواهد شد. دقت کنید که این عمل غیر قابل بازگشت است.<br>آیا اطمینان دارید که برای این تغییر آماده هستید ؟
                </div>
            `;

            const res = await Swal.fire({
                icon: 'warning',
                title: 'تأیید به‌روزرسانی پایگاه داده',
                html: warningHtml,
                showCancelButton: true,
                confirmButtonText: 'بله، موافقم',
                cancelButtonText: 'لغو',
                reverseButtons: true,
                customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-danger', cancelButton: 'btn btn-cancel' }
            });
            if (!res.isConfirmed) return;

            // Show update progress modal
            Swal.fire({
                title: 'در حال به‌روزرسانی',
                html: `
                    <div style="text-align: center; padding: 1rem;">
                        <div id="updateProgressDisplay" style="font-size: 3rem; font-weight: bold; color: white; margin-bottom: 1rem;">1%</div>
                        <p id="updateProgressText" style="color: white; font-size: 1.1rem;">در حال بررسی جداول موقت...</p>
                    </div>
                `,
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                customClass: { popup: 'swal2-rtl swal2-glass' }
            });

            // Animate and poll server progress
            let updProgress = 1;
            let updServerProgress = false;
            const updDisp = document.getElementById('updateProgressDisplay');
            const updText = document.getElementById('updateProgressText');

            const updAnim = setInterval(() => {
                if (updServerProgress) return;
                updProgress += Math.random() * 3 + 0.5;
                if (updProgress > 95) updProgress = 95;
                if (updDisp) updDisp.textContent = updProgress >= 10 ? Math.round(updProgress) + '%' : 'شروع...';
                if (updText) {
                    if (updProgress < 30) updText.textContent = 'در حال بررسی جداول موقت...';
                    else if (updProgress < 60) updText.textContent = 'در حال پاکسازی و آماده‌سازی...';
                    else updText.textContent = 'در حال درج اطلاعات...';
                }
            }, 300);

            const pollUpdate = async () => {
                try {
                    const resp = await guardedFetch(`../API/getProcessProgress.php?filename=${encodeURIComponent('update')}`);
                    if (!resp.ok) return;
                    const payload = await resp.json();
                    if (!payload) return;
                    if (typeof payload.processedRows === 'number' && typeof payload.totalRows === 'number' && payload.totalRows > 0) {
                        updServerProgress = true;
                        const percent = Math.min(99, Math.round((payload.processedRows / payload.totalRows) * 100));
                        if (updDisp) updDisp.textContent = percent + '%';
                        if (updText) updText.textContent = payload.message || 'در حال به‌روزرسانی...';
                    } else if (payload.stage === 'error') {
                        updServerProgress = true;
                        if (updDisp) updDisp.textContent = '0%';
                        if (updText) updText.textContent = payload.message || 'خطا در به‌روزرسانی';
                    }
                } catch (e) { /* ignore */ }
            };
            const updPoll = setInterval(pollUpdate, 500);

            try {
                const response = await guardedFetch('../API/updateDatabase.php', { method: 'POST' });
                clearInterval(updAnim);
                clearInterval(updPoll);

                if (!response.ok) {
                    Swal.close();
                    if (response.status === 403) {
                        let message = 'دسترسی به این عملیات ممکن نیست.';
                        try {
                            const payload = await response.json();
                            if (payload && payload.message) message = payload.message;
                        } catch (err) { /* ignore */ }
                        showLicenseForbidden(message);
                        return;
                    }
                    let errMsg = 'خطا در به‌روزرسانی پایگاه داده';
                    try { const payload = await response.json(); if (payload && payload.error) errMsg = payload.error; } catch (e) { }
                    throw new Error(errMsg);
                }

                const result = await response.json();
                if (result && result.success) {
                    if (updDisp) updDisp.textContent = '100%';
                    if (updText) updText.textContent = 'به‌روزرسانی کامل شد!';
                    setTimeout(async () => {
                        Swal.close();
                        const c = result.inserted?.courses ?? 0;
                        const s = result.inserted?.students ?? 0;
                        const es = result.inserted?.exam_seats ?? 0;
                        const l = result.inserted?.locations ?? 0;
                        await Swal.fire({
                            icon: 'success',
                            title: 'موفق',
                            width: '60rem',
                            html: `به‌روزرسانی با موفقیت انجام شد<br>دروس: <b>${c}</b> | دانشجویان: <b>${s}</b> | صندلی‌ها: <b>${es}</b> | مکان‌ها: <b>${l}</b>`,
                            confirmButtonText: 'باشه',
                            customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' }
                        });
                        // Refresh dashboard stats
                        try { await loadDashboardData(); } catch (e) { /* ignore */ }
                    }, 500);
                } else {
                    Swal.close();
                    throw new Error(result?.error || 'خطای نامشخص');
                }
            } catch (error) {
                try { clearInterval(updAnim); } catch (e) { }
                try { clearInterval(updPoll); } catch (e) { }
                Swal.close();
                if (error?.isLicenseError) return; // already handled
                await Swal.fire({
                    icon: 'error',
                    title: 'خطا',
                    text: error?.message || 'خطا در به‌روزرسانی پایگاه داده',
                    confirmButtonText: 'باشه',
                    customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' }
                });
            }
        });
    }
} catch (e) {
    console.warn('Failed to init updateDBBtn handler', e);
}


async function loadDashboardData() {
    try {

        const configResponse = await guardedFetch('../API/getConfig.php', { cache: 'no-store' });
        const config = await configResponse.json();


        if (config.AdminNickName) {
            document.getElementById('adminUsername').textContent = config.AdminNickName;
        }

        // Store config globally for use in reports
        window.appConfig = config;


        const statsResponse = await guardedFetch('../API/getStatistics.php', { cache: 'no-store' });
        const stats = await statsResponse.json();

        if (!stats.error) {
            document.getElementById('totalStudents').textContent = stats.totalStudents || 0;
            document.getElementById('totalCourses').textContent = stats.totalCourses || 0;
            document.getElementById('nextExamStudents').textContent = stats.nextExamStudents || 0;
            document.getElementById('nextExamDateTime').textContent = stats.nextExamDateTime || 'آزمونی یافت نشد';

            // Disable/enable Remaining Sessions card based on value
            if (typeof stats.remainingSessions !== 'undefined') {
                const el = document.getElementById('remainingSessions');
                const card = el ? el.closest('.dashboard-card') : null;
                if (el) {

                    if (!stats.remainingSessions || stats.remainingSessions === 0) {
                        el.textContent = '۰';
                        if (card) {
                            card.classList.add('stat-card-disabled');

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

            // Disable/enable Next Exam card when no upcoming exam
            try {
                const nextLabelEl = document.getElementById('nextExamDateTime');
                const nextCard = nextLabelEl ? nextLabelEl.closest('.dashboard-card') : null;
                const noExam = !stats.nextExamStudents || stats.nextExamStudents === 0 || !stats.nextExamDateTime || String(stats.nextExamDateTime).trim() === '' || String(nextLabelEl?.textContent || '').trim() === 'آزمونی یافت نشد';
                if (nextCard) {
                    if (noExam) {
                        nextCard.classList.add('stat-card-disabled');
                        nextCard.style.cursor = 'default';
                        // neutralize click
                        try { nextCard.onclick = null; } catch (e) { }
                        nextCard.style.pointerEvents = 'none';
                    } else {
                        nextCard.classList.remove('stat-card-disabled');
                        nextCard.style.cursor = 'pointer';
                        nextCard.style.pointerEvents = '';
                        // restore click handler in case it was removed
                        try { nextCard.onclick = showNextExamReport; } catch (e) { }
                    }
                }
            } catch (e) { /* ignore */ }

        }

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


async function showRemainingSessions() {
    try {

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


        let cardsHtml = '<div class="session-mini-grid">';
        future.forEach(f => {
            const time = f.exam_time;
            const date = f.exam_date;
            const total = f.student_count || 0;

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
                        Swal.close();

                        setTimeout(() => {
                            applyNextExamOverride(d, t, { customTitle: `آزمون تاریخ ${d} ساعت ${t}` });
                            showNextExamReport();
                        }, 120);
                    });
                });
            }
        });
    } catch (err) {
        console.error('Error loading future exams:', err);
        Swal.fire({ icon: 'error', title: 'خطا', text: 'خطا در دریافت جلسات آینده', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
    }
}


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


if (checkAuth()) {
    loadDashboardData();
}


let reportsChartInstance = null;

let chartDefaultsConfigured = false;

let reportsResizeRegistered = false;
let reportsResizeTimer = null;



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
                    return reject(new Error('Failed to load Chart.js'));
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
            // try { destroySmallOverviewPies(); } catch (e) { /* ignore */ }
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
        // try { renderSmallOverviewPies(stats); } catch (e) { console.warn('Could not render overview pies:', e); }

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
        width: '60rem',
        confirmButtonText: 'آپلود فایل',
        cancelButtonText: 'انصراف',
        customClass: {
            popup: 'swal2-rtl swal2-glass',
            confirmButton: 'btn btn-primary',
            cancelButton: 'btn btn-cancel'
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
                <style>
                /* Make progress digits monospaced/tabular for consistent width */
                .tabular-digits { font-variant-numeric: tabular-nums; font-family: Vazir, 'DejaVu Sans Mono', monospace; letter-spacing: 0.01em; }
                .upload-progress-wrap { background: #e0e0e0; border-radius: 10px; overflow: hidden; height: 36px; margin-bottom: 0.85rem; }
                .upload-progress-bar { background: linear-gradient(90deg, #1a6fa6, #127ead); height: 100%; width: 0%; transition: width 0.25s; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 1.05rem; }
                </style>
                <div class="upload-progress-wrap"><div id="uploadProgressBar" class="upload-progress-bar tabular-digits">۰٪</div></div>
                <p id="uploadProgressText" style="color: white; font-size: 1.05rem; margin:0;">در حال آپلود فایل...</p>
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

                const pers = (typeof toPersianDigits === 'function') ? toPersianDigits(percentComplete) : String(percentComplete);

                if (progressBar) {
                    progressBar.style.width = percentComplete + '%';
                    progressBar.textContent = pers + '٪';
                }

                if (progressText) {
                    progressText.textContent = `در حال آپلود ${pers}٪`;
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
                <div id="processProgressDisplay" class="tabular-digits" style="font-size: 3rem; font-weight: bold; color: white; margin-bottom: 1rem;">۰٪</div>
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
            const pers = (typeof toPersianDigits === 'function') ? toPersianDigits(Math.round(progress)) : String(Math.round(progress));
            progressDisplay.textContent = pers + '٪';
            progressDisplay.classList.add('tabular-digits');
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
                const pers = (typeof toPersianDigits === 'function') ? toPersianDigits(percent) : String(percent);
                if (progressDisplay) progressDisplay.textContent = pers + '٪';
                if (progressText) progressText.textContent = payload.message || 'در حال پردازش...';
            } else if (payload.stage === 'error') {
                // show server-side validation error
                serverProgressAvailable = true;
                if (progressDisplay) progressDisplay.textContent = (typeof toPersianDigits === 'function' ? toPersianDigits(0) : '0') + '٪';
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
            cancelButton: 'btn btn-cancel'
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
            const reportCard = document.getElementById('reportCard');
            reportCard.style.display = 'block';
            reportCard.classList.add('fade-in-up');
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

async function loadCourseReportByCode(courseCode, options = {}) {
    const { showErrors = true } = options;
    if (!courseCode) return false;
    try {
        const response = await guardedFetch(`../API/getCourseReport.php?course_code=${encodeURIComponent(courseCode)}`, { cache: 'no-store' });
        const data = await response.json();

        if (data.error) {
            if (showErrors) {
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
            }
            return false;
        }

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
        const reportCard = document.getElementById('reportCard');
        reportCard.style.display = 'block';
        reportCard.classList.add('fade-in-up');
        setTimeout(scrollReportCardIntoView, 100);
        return true;
    } catch (error) {
        console.error('Error:', error);
        if (!error?.isLicenseError && showErrors) {
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
        return false;
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
            cancelButton: 'btn btn-cancel'
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
        await loadCourseReportByCode(courseCode, { showErrors: true });
    }
}

function applyNextExamOverride(examDate, examTime, options = {}) {
    if (!examDate || !examTime) return;
    const nextEl = document.getElementById('nextExamDateTime');
    if (!nextEl) return;

    const normalizedDate = toEnglishDigits(String(examDate)).replace(/-/g, '/');
    const normalizedTime = toEnglishDigits(String(examTime));

    setLastExamContext(normalizedDate, normalizedTime);

    if (window._overrideExamContext && window._overrideExamContext.active) {
        // Update existing override with new values but keep original label reference
        window._overrideExamContext.exam_date = normalizedDate;
        window._overrideExamContext.exam_time = normalizedTime;
        window._overrideExamContext.customTitle = options.customTitle || null;
        window._overrideExamContext.display_text = `${normalizedTime} | ${normalizedDate}`;
    } else {
        window._overrideExamContext = {
            exam_date: normalizedDate,
            exam_time: normalizedTime,
            previous_text: nextEl.textContent,
            customTitle: options.customTitle || null,
            active: true,
            display_text: `${normalizedTime} | ${normalizedDate}`
        };
    }

    nextEl.textContent = window._overrideExamContext.display_text;
    if (window._overrideExamContext.customTitle) {
        window.customExamReportTitle = window._overrideExamContext.customTitle;
    }
}

async function showNextExamReport() {
    const nextEl = document.getElementById('nextExamDateTime');
    const override = window._overrideExamContext && window._overrideExamContext.active ? window._overrideExamContext : null;
    const originalLabel = override ? (override.previous_text ?? (nextEl ? nextEl.textContent : '')) : null;

    try {
        let examTime = '';
        let examDate = '';
        if (override) {
            examTime = override.exam_time;
            examDate = override.exam_date;
            setLastExamContext(examDate, examTime);
        } else {
            if (!nextEl) {
                await Swal.fire({
                    icon: 'error',
                    title: 'خطا',
                    text: 'زمان آزمون در دسترس نیست',
                    confirmButtonText: 'باشه',
                    customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' }
                });
                return;
            }
            // Get exam date and time from the label
            const nextExamDateTimeText = nextEl.textContent;

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

            examTime = toEnglishDigits(parts[0]);
            examDate = toEnglishDigits(parts[1]).replace(/-/g, '/');
            setLastExamContext(examDate, examTime);
        }

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

        // Store students and full report globally for other actions (printing, essentials)
        window.allStudents = students;
        // keep the full report response so printing helpers can reuse it
        window.currentExamReport = data;
        setLastExamContext(data.exam_date, data.exam_time);

        // Build a compact inline info string that always shows 4 items: date | time | courses | students
        const dateDisplayInline = data.exam_date ? toPersianDigits(data.exam_date) : 'بدون تاریخ';
        const timeDisplayInline = data.exam_time ? toPersianDigits(data.exam_time) : 'بدون ساعت';
        const courseCountInline = Array.isArray(courses) ? courses.length : 0;
        const studentCountInline = Array.isArray(students) ? students.length : 0;
        const inlineInfo = `${dateDisplayInline} | ${timeDisplayInline} | ${toPersianDigits(courseCountInline)} درس | ${toPersianDigits(studentCountInline)} نفر`;

        const headerTitle = window.customExamReportTitle || 'جزئیات جلسه آزمون';
        // Compact single-row details bar with 5 cells: date | time | courses | students | essentials icon
        let html = `
            <div class="mb-4">
                <h5 class="text-primary mb-3">${headerTitle}</h5>
                <div class="table-responsive">
                    <style>
                        .details-compact { width:100%; border-collapse:separate; border-spacing:0; direction: rtl; }
                        .details-compact td { padding: 6px 10px; vertical-align: middle; text-align: center; border: 1px solid #e3e6ea; height: 56px; color: #495057; white-space: nowrap; }
                        .details-compact td.icon-cell { width: 56px; }
                        .details-compact img.icon { width: 44px; height: 44px; display:block; margin: 0 auto; object-fit: contain; pointer-events: none; }
                    </style>


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
                            <h6 class="mb-2">آمار سریع جلسه <span class="text-muted">(${inlineInfo})</span></h6>
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
                                <button id="examEssentialsBtn" class="btn btn-link p-0" type="button" title="ملزومات آزمون" onclick="try{ examEssentialsHandler(); }catch(e){ console.error(e); }">
                                    <img class="icon" src="/assets/app/Essentials.png" alt="ملزومات آزمون">
                                </button>
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
        const reportCard = document.getElementById('reportCard');
        reportCard.style.display = 'block';
        reportCard.classList.add('fade-in-up');
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
    } finally {
        if (override && override.active) {
            if (nextEl && originalLabel !== null && typeof originalLabel !== 'undefined') {
                nextEl.textContent = originalLabel;
            }
            window._overrideExamContext = null;
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
async function printSeatNumbersReport() {
    try {
        const context = window._lastExamContext || null;
        const normalizeDate = (value) => toEnglishDigits(String(value || '')).replace(/-/g, '/');
        const normalizeTime = (value) => toEnglishDigits(String(value || ''));

        async function getReportForContext(examDate, examTime) {
            const response = await guardedFetch(`../API/getNextExamReport.php?exam_date=${encodeURIComponent(examDate)}&exam_time=${encodeURIComponent(examTime)}`, { cache: 'no-store' });
            const data = await response.json();
            if (data && !data.error) {
                window.currentExamReport = data;
                window.allStudents = Array.isArray(data.students) ? data.students : [];
                setLastExamContext(data.exam_date, data.exam_time);
                return data;
            }
            if (data && data.error) {
                throw new Error(data.error);
            }
            throw new Error('گزارش جلسه در دسترس نیست');
        }

        let report = window.currentExamReport || null;
        let needsFetch = false;
        const ctxDate = context && context.exam_date ? normalizeDate(context.exam_date) : null;
        const ctxTime = context && context.exam_time ? normalizeTime(context.exam_time) : null;

        if (ctxDate && ctxTime) {
            if (!report || !report.exam_date || !report.exam_time) {
                needsFetch = true;
            } else {
                const repDate = normalizeDate(report.exam_date);
                const repTime = normalizeTime(report.exam_time);
                if (repDate !== ctxDate || repTime !== ctxTime) {
                    needsFetch = true;
                }
            }
        }

        if (needsFetch && ctxDate && ctxTime) {
            try {
                report = await getReportForContext(ctxDate, ctxTime);
            } catch (fetchErr) {
                return Swal.fire({ icon: 'error', title: 'خطا', text: fetchErr.message || 'خطا در دریافت اطلاعات جلسه', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
            }
        }

        let students = [];
        if (report && Array.isArray(report.students)) {
            students = report.students.slice();
        } else if (Array.isArray(window.allStudents)) {
            students = window.allStudents.slice();
        }

        if (!students.length) {
            return Swal.fire({ icon: 'info', title: 'اطلاعات', text: 'هیچ دانشجویی برای چاپ یافت نشد', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
        }

        window.allStudents = students.slice();

        if (!report) {
            report = { exam_date: ctxDate || '', exam_time: ctxTime || '', students: students.slice() };
        } else if (!Array.isArray(report.students)) {
            report.students = students.slice();
        }
        window.currentExamReport = report;

        const repDateRaw = report && report.exam_date ? report.exam_date : (context && context.exam_date ? context.exam_date : '');
        const repTimeRaw = report && report.exam_time ? report.exam_time : (context && context.exam_time ? context.exam_time : '');
        const repDateNorm = repDateRaw ? normalizeDate(repDateRaw) : '';
        const repTimeNorm = repTimeRaw ? normalizeTime(repTimeRaw) : '';

        let title = '';
        const timeText = repTimeNorm ? toPersianDigits(repTimeNorm) : '';
        const dateText = repDateNorm ? toPersianDigits(repDateNorm) : '';
        if (timeText && dateText) title = `${timeText} | ${dateText}`;
        else title = timeText || dateText || '';
        if (!title) {
            title = document.querySelector('#nextExamDateTime')?.textContent || '';
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
            course_code: s.course_code || s.courseCode || '',
            seat_number: (typeof s.seat_number !== 'undefined') ? String(s.seat_number) : '',
            // optional location fields returned by API: building and class/room name
            building: s.building || s.building_name || s.location || '',
            class_name: s.class_name || s.room || s.class || ''
        });

        const entries = students.map(normalize);

        // Ensure config is loaded for sorting
        if (!window.appConfig) {
            try {
                const configResponse = await guardedFetch('../API/getConfig.php', { cache: 'no-store' });
                const config = await configResponse.json();
                window.appConfig = config;
            } catch (e) {
                console.warn('Could not load config for sorting', e);
            }
        }

        // Sort by course_code if GroupByCourse is YES, then by last name then first name
        const groupByCourse = window.appConfig?.GroupByCourse === 'YES';
        entries.sort((a, b) => {
            if (groupByCourse) {
                const courseA = (a.course_code || '').trim();
                const courseB = (b.course_code || '').trim();
                if (courseA !== courseB) return courseA.localeCompare(courseB, 'fa') || courseA.localeCompare(courseB);
            }
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
        // Prefer configured University from server; fall back to footerText
        let university = '';
        try {
            const cfgResp = await guardedFetch('../API/getConfig.php', { cache: 'no-store' });
            if (cfgResp && cfgResp.ok) {
                const cfg = await cfgResp.json();
                university = (cfg.University || (document.getElementById('footerText')?.textContent || '')).replace(/^نسار\s*-\s*/i, '').trim();
            } else {
                university = (document.getElementById('footerText')?.textContent || '');
            }
        } catch (e) {
            university = (document.getElementById('footerText')?.textContent || '');
        }
        if (!university) university = 'گزارش شماره صندلی';

        function esc(txt) { const d = document.createElement('div'); d.textContent = txt || ''; return d.innerHTML; }

        const fontHref = (window.location && window.location.origin ? window.location.origin : '') + '/assets/fonts/vazir/vazir.css';
        let docHtml = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>گزارش شماره صندلی</title><link rel="stylesheet" href="${fontHref}">`;
        docHtml += `<style>
                        @page { size: A4 landscape; margin: 4mm; }
                        @page portrait { size: A4 portrait; margin: 6mm; }
                        .kroki-page { page: portrait; box-sizing:border-box; padding:6mm; }
                        .kroki-page table { table-layout: fixed; width: 100%; box-sizing: border-box; }
            .kroki-page table th, .kroki-page table td { word-break: break-word; white-space: normal; overflow-wrap: anywhere; box-sizing: border-box; }
            .kroki-page table th { font-size: 11pt; }
                        html,body { margin:0; padding:0; }
                        body { font-family: Vazir, Tahoma, Arial, sans-serif; color: #111; font-size: 8.5pt; }
            .report-header { text-align: center; margin-bottom: 4px; }
            .report-title { font-size: 12pt; font-weight: 700; margin-bottom:2px }
                        .report-meta { font-size: 20pt; color: #111; margin-top:2px; font-weight: 900; }
            .page { page-break-after: always; margin-bottom: 0; }
            table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 8.5pt; line-height: 1.06; }
            th, td { padding: 4px 6px; text-align: right; border-bottom: 0.5px solid #e0e0e0; font-size: 8.5pt; box-sizing: border-box; }
            thead th { background: #efefef; font-weight: 700; font-size: 9pt; padding: 5px 6px; text-align: center; }
                        .seat-col { text-align: center; }
            tbody tr:nth-child(odd) { background: #fafafa; }
            td { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .col-wrap { display: flex; gap: 6px; position: relative; }
            .col { width: 50%; }
            .col-wrap::before { content: ''; position: absolute; left: 50%; top: 0; bottom: 0; width: 0.6px; background: #bdbdbd; transform: translateX(-0.3px); }
            .col { padding-right: 6px; }
            .small-muted { color: #666; font-size: 8pt; }
            .page-range { text-align: center; color: #666; font-size: 9pt; margin-top: 4px; }
            /* Avoid an extra blank page at the end caused by page-break-after on .page */
            .page:last-child { page-break-after: auto; }
            @media print {
                .no-print { display: none !important; }
                .page { page-break-after: always; }
            }
        </style>`;
        docHtml += `</head><body>`;

        for (let p = 0; p < totalPages; p++) {
            docHtml += `<div class="page">`;
            docHtml += `<div class="report-header"><div class="report-title">${esc(university)}</div>`;
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
                    docHtml += `<div class="col"><table><thead><tr><th style="width:31%">نام و نام خانوادگی</th><th style="width:57%">نام درس</th><th class="seat-col" style="width:12%">صندلی</th></tr></thead><tbody>`;
                    colArr.forEach((row, idx) => {
                        // compute global index relative to the page slice
                        const globalIndex = start + (colIndex === 0 ? idx : half + idx);
                        docHtml += `<tr>`;
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
                docHtml += `<div><table><thead><tr><th style="width:31%">نام و نام خانوادگی</th><th style="width:57%">نام درس</th><th class="seat-col" style="width:12%">صندلی</th></tr></thead><tbody>`;
                slice.forEach((row, idx) => {
                    const globalIndex = start + idx;
                    docHtml += `<tr>`;
                    const fullName2 = esc((row.last_name || '') + ' ' + (row.first_name || ''));
                    const courseName2 = esc(row.course_name || '');
                    docHtml += `<td title="${fullName2}">${fullName2}</td>`;
                    docHtml += `<td title="${courseName2}">${courseName2}</td>`;
                    docHtml += `<td class="seat-col">${esc(row.seat_number || '')}</td>`;
                    docHtml += `</tr>`;
                });
                docHtml += `</tbody></table></div>`;
            }

            // Footer range for page indices
            const pageStart = (p * perPage) + 1;
            const pageEnd = Math.min((p + 1) * perPage, entries.length);
            const prStart = typeof toPersianDigits === 'function' ? toPersianDigits(pageStart) : String(pageStart);
            const prEnd = typeof toPersianDigits === 'function' ? toPersianDigits(pageEnd) : String(pageEnd);
            docHtml += `<div class="page-range">از شماره ${prStart} تا ${prEnd}</div>`;

            docHtml += `</div>`; // .page
        }

        // Append a separate "کروکی" page that maps seat-number ranges to building/class
        try {
            // Robust parser for seat_number values. Handles:
            // - single numbers ("12")
            // - multiple numbers separated by commas/spaces ("1,2,3" or "1 2 3")
            // - ranges using hyphen or the word 'تا' ("1-25" or "1 تا 25") -> expands the range
            const parseSeatNumbers = (raw) => {
                if (!raw && raw !== 0) return [];
                const s = String(raw).trim();
                const nums = [];

                // detect explicit range like "12-25" or with en/em dash
                const rangeMatch = s.match(/(\d+)\s*[-–—]\s*(\d+)/);
                if (rangeMatch) {
                    const a = parseInt(rangeMatch[1], 10);
                    const b = parseInt(rangeMatch[2], 10);
                    if (!Number.isNaN(a) && !Number.isNaN(b)) {
                        const start = Math.min(a, b);
                        const end = Math.max(a, b);
                        // protect against absurd ranges
                        const maxSpan = 1000;
                        const span = Math.min(end - start + 1, maxSpan);
                        for (let i = 0; i < span; i++) nums.push(start + i);
                        return nums;
                    }
                }

                // detect Persian "تا" or variants like "1 تا 25"
                const persRange = s.match(/(\d+)\s*(?:تا|تا‌)\s*(\d+)/i);
                if (persRange) {
                    const a = parseInt(persRange[1], 10);
                    const b = parseInt(persRange[2], 10);
                    if (!Number.isNaN(a) && !Number.isNaN(b)) {
                        const start = Math.min(a, b);
                        const end = Math.max(a, b);
                        const maxSpan = 1000;
                        const span = Math.min(end - start + 1, maxSpan);
                        for (let i = 0; i < span; i++) nums.push(start + i);
                        return nums;
                    }
                }

                // fallback: extract all digit sequences and return them
                const matches = s.match(/\d+/g);
                if (matches && matches.length) {
                    for (const m of matches) {
                        const v = parseInt(m, 10);
                        if (!Number.isNaN(v)) nums.push(v);
                    }
                }

                return nums;
            };

            const groups = {};
            entries.forEach(r => {
                const b = (r.building || '').trim() || 'بدون ساختمان';
                const c = (r.class_name || '').trim() || 'بدون کلاس';
                const key = b + '||' + c;
                if (!groups[key]) groups[key] = { building: b, class_name: c, nums: [] };
                try {
                    const parsed = parseSeatNumbers(r.seat_number);
                    if (parsed && parsed.length) {
                        groups[key].nums.push(...parsed);
                    }
                } catch (e) {
                    // ignore single malformed entries
                }
            });

            const groupKeys = Object.keys(groups);
            if (groupKeys.length) {
                // Build one row per group (class/room): compute unique min/max and unique count
                const rows = [];
                groupKeys.forEach(k => {
                    const g = groups[k];
                    const uniq = Array.from(new Set(g.nums)).sort((a, b) => a - b);
                    const start = uniq.length ? uniq[0] : null;
                    const end = uniq.length ? uniq[uniq.length - 1] : null;
                    const count = uniq.length;
                    if (start !== null) rows.push({ building: g.building, class_name: g.class_name, start, end, count });
                });

                // sort globally by start (ascending), then by building/class as tie-breaker
                rows.sort((a, b) => (a.start - b.start) || a.building.localeCompare(b.building, 'fa') || a.class_name.localeCompare(b.class_name, 'fa'));

                let mapHtml = `<div class="page kroki-page">`;

                mapHtml += `<div class="report-header"><div class="report-title kroki-title-large" style="font-weight:900; line-height:1;">کروکی محل استقرار صندلی‌ها</div>`;

                mapHtml += `<div class="report-meta" style="font-size:14pt;margin-top:6px;font-weight:700;">${esc(title)}</div></div>`;

                mapHtml += `<div style="padding:8px;">
                    <table style="width:100%;border-collapse:collapse;margin-top:8px;font-size:12pt; table-layout: fixed;">
                        <thead>
                            <tr style="background:#efefef;font-weight:700;text-align:center;">
                                <th style="padding:8px;border:1px solid #ddd;width:12%">از شماره</th>
                                <th style="padding:8px;border:1px solid #ddd;width:12%">تا شماره</th>
                                <th style="padding:8px;border:1px solid #ddd;width:10%">تعداد</th>
                                <th style="padding:8px;border:1px solid #ddd;width:30%">ساختمان</th>
                                <th style="padding:8px;border:1px solid #ddd;width:36%">کلاس / اتاق</th>
                            </tr>
                        </thead>
                        <tbody>`;

                rows.forEach(r => {
                    const from = toPersianDigits(r.start);
                    const to = toPersianDigits(r.end);
                    const cnt = toPersianDigits(r.count);
                    mapHtml += `<tr style="text-align:center;"><td style="padding:8px;border:1px solid #ddd;">${from}</td><td style="padding:8px;border:1px solid #ddd;">${to}</td><td style="padding:8px;border:1px solid #ddd;">${cnt}</td><td style="padding:8px;border:1px solid #ddd;">${esc(r.building)}</td><td style="padding:8px;border:1px solid #ddd;">${esc(r.class_name)}</td></tr>`;
                });

                mapHtml += `</tbody></table></div></div>`;
                docHtml += mapHtml;
            }
        } catch (e) {

            console.warn('Could not build kroki page:', e);
        }

        docHtml += `</body></html>`;



        const desiredPerPage = perPage;
        const firstPageEntries = entries.slice(0, desiredPerPage);


        const minFontPt = 6.5;
        let testFontPt = 9.5;
        let fits = false;


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
                .page-range { text-align: center; color: #666; font-size: ${Math.max(8, fontPt)}pt; margin-top: 4px; }
            </style></head><body>`;
            h += `<div class="page">`;

            const half = Math.ceil(firstPageEntries.length / 2);
            const left = firstPageEntries.slice(0, half);
            const right = firstPageEntries.slice(half);
            h += `<div class="col-wrap">`;
            [left, right].forEach((colArr, ci) => {
                h += `<div class="col"><table><thead><tr><th style="width:31%">نام و نام خانوادگی</th><th style="width:57%">نام درس</th><th style="width:12%">صندلی</th></tr></thead><tbody>`;
                colArr.forEach((r, idx) => {
                    const fullName = (r.last_name || '') + ' ' + (r.first_name || '');
                    const courseName = r.course_name || '';
                    h += `<tr><td title="${fullName}">${fullName}</td><td title="${courseName}">${courseName}</td><td class="seat-col">${r.seat_number || ''}</td></tr>`;
                });
                h += `</tbody></table></div>`;
            });
            // Footer range for preview page indices
            const pStart = 1;
            const pEnd = firstPageEntries.length;
            const pStartFa = typeof toPersianDigits === 'function' ? toPersianDigits(pStart) : String(pStart);
            const pEndFa = typeof toPersianDigits === 'function' ? toPersianDigits(pEnd) : String(pEnd);
            h += `<div class="page-range">از شماره ${pStartFa} تا ${pEndFa}</div>`;

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

                setTimeout(() => {
                    const pageEl = doc.querySelector('.page');
                    if (!pageEl) return resolve(false);

                    const fitsNow = pageEl.scrollHeight <= pageEl.clientHeight + 1;
                    resolve(fitsNow);
                }, 180);
            } catch (e) { resolve(false); }
        });

        (async () => {
            while (testFontPt >= minFontPt) {


                fits = await tryFit();
                if (fits) break;
                testFontPt = Math.round((testFontPt - 0.25) * 100) / 100;
            }

            if (fits) {

                try {
                    const fullDoc = iframe.contentDocument || iframe.contentWindow.document;



                    let finalHtml = docHtml.replace(/font-size:\s*[\d.]+pt;/g, `font-size: ${testFontPt}pt;`);


                    finalHtml = finalHtml.replace('</head>', `<style>.report-meta{font-size:20pt !important; font-weight:900 !important;} .kroki-page .kroki-title-large{font-size: 32pt !important; font-weight:900 !important;}</style></head>`);
                    fullDoc.open();
                    fullDoc.write(finalHtml);
                    fullDoc.close();

                    try { Swal.close(); } catch (e) { }
                    const cw = iframe.contentWindow;
                    let cleaned = false;
                    const cleanup = () => {
                        if (cleaned) return;
                        cleaned = true;
                        try { closeSwalLoadingHard(); } catch (e) { }
                        try { document.body.removeChild(iframe); } catch (e) { }
                        try { window.removeEventListener('focus', onFocusOnce, true); } catch (e) { }
                        try { reopenEssentialsMenuIfRequested(); } catch (e) { }
                    };
                    const onFocusOnce = () => { setTimeout(cleanup, 150); };
                    try {
                        if (cw) {
                            cw.onafterprint = cleanup;
                            window.addEventListener('focus', onFocusOnce, true);
                            try { safePrintIframe(iframe, cw); } catch (e) { console.error('Print invoke error:', e); }
                            setTimeout(cleanup, 5000);
                        } else {
                            setTimeout(cleanup, 300);
                        }
                    } catch (e) {
                        console.error('Print error:', e);
                        setTimeout(cleanup, 300);
                    }
                } catch (e) {
                    document.body.removeChild(iframe);
                    try { Swal.close(); } catch (er) { }
                    Swal.fire({ icon: 'error', title: 'خطا', text: 'خطا در چاپ از iframe', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
                }
            } else {

                try { document.body.removeChild(iframe); } catch (e) { }

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
                setTimeout(() => { try { safePrintWindow(w); } catch (e) { console.error('Print error (fallback window):', e); } }, 450);
            }
        })();

    } catch (err) {

        console.error('Error building printable report (printEssentialsSecretary):', err);
        const msg = (err && (err.message || err.toString())) ? String(err.message || err) : 'خطا در آماده‌سازی گزارش چاپ';
        const stack = (err && err.stack) ? String(err.stack).split('\n').slice(0, 5).join('\n') : '';
        Swal.fire({ icon: 'error', title: 'خطا', html: `<div style="text-align:right">${esc(msg)}<br/><pre style="text-align:left;white-space:pre-wrap;font-size:0.8rem;color:#666">${esc(stack)}</pre></div>`, confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
    }
}


async function examEssentialsHandler() {
    Swal.fire({
        icon: 'info',
        title: 'ملزومات جلسه آزمون',
        html: `
            <div style="display:flex;flex-direction:column;gap:12px;margin-top:1rem;">
                <!-- Quick print shortcuts that reuse existing handlers -->
                <button id="essentialsPrintSessionBtn" class="btn btn-primary w-100" style="padding:12px;font-size:1rem;font-weight:600;" onclick="try{ window._reopenEssentialsMenu=true; Swal.close(); setTimeout(()=>{ printSessionReport(); }, 80); }catch(e){ console.error(e); }">
                    صورتجلسه آزمون
                </button>
                <button id="essentialsPrintSeatBtn" class="btn btn-primary w-100" style="padding:12px;font-size:1rem;font-weight:600;" onclick="try{ window._reopenEssentialsMenu=true; Swal.close(); setTimeout(()=>{ printSeatNumbersReport(); }, 80); }catch(e){ console.error(e); }">
                     شماره‌ صندلی‌آزمون
                </button>

                <button id="essentialsSecretaryBtn" class="btn btn-primary w-100" style="padding:12px;font-size:1rem;font-weight:600;" onclick="try{ startEssentialsPrint('secretary'); }catch(e){ console.error(e); }">
                    ملزومات منشی جلسه
                </button>
                <button id="essentialsReproductionBtn" class="btn btn-primary w-100" style="padding:12px;font-size:1rem;font-weight:600;" onclick="try{ startEssentialsPrint('reproduction'); }catch(e){ console.error(e); }">
                    ملزومات اتاق تکثیر
                </button>
                <button id="essentialsDescriptiveBtn" class="btn btn-primary w-100" style="padding:12px;font-size:1rem;font-weight:600;" onclick="try{ startEssentialsPrint('descriptive'); }catch(e){ console.error(e); }">
                    برچسب پاکت‌های تشریحی
                </button>
                <!-- 
                <button id="essentialsTestBtn" class="btn btn-primary w-100" style="padding:12px;font-size:1rem;font-weight:600;" onclick="try{ startEssentialsPrint('test'); }catch(e){ console.error(e); }">
                    برچسب پاکت‌های تستی
                </button> 
                -->
            </div>
        `,
        showConfirmButton: false,
        showCancelButton: false,
        customClass: { popup: 'swal2-rtl swal2-glass' }
    });
}




function startEssentialsPrint(kind) {

    try { window._reopenEssentialsMenu = true; } catch (e) { window._reopenEssentialsMenu = true; }
    try { Swal.close(); } catch (e) { }

    setTimeout(() => {
        try {
            if (kind === 'secretary') printEssentialsSecretary();
            else if (kind === 'reproduction') printEssentialsReproduction();
            else if (kind === 'descriptive') printEssentialsDescriptive();
            else if (kind === 'test') printEssentialsTest();
        } catch (e) {
            console.error('startEssentialsPrint error:', e);
        }
    }, 100);
}


async function printEssentialsSecretary() {
    try {
        Swal.fire({
            title: 'در حال ساخت گزارش',
            html: 'لطفاً منتظر بمانید...',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); },
            customClass: { popup: 'swal2-rtl swal2-glass' },
            showConfirmButton: false
        });

        const fontHref = (window.location && window.location.origin ? window.location.origin : '') + '/assets/fonts/vazir/vazir.css';
        const university = (document.getElementById('footerText')?.textContent || '').trim().replace(/^نسار\s*-\s*/, '') || 'گزارش ملزومات منشی جلسه';

        const context = window._lastExamContext || null;
        const normalizeDate = (value) => toEnglishDigits(String(value || '')).replace(/-/g, '/');
        const normalizeTime = (value) => toEnglishDigits(String(value || ''));

        async function getReportForContext(examDate, examTime) {
            const response = await guardedFetch(`../API/getNextExamReport.php?exam_date=${encodeURIComponent(examDate)}&exam_time=${encodeURIComponent(examTime)}`, { cache: 'no-store' });
            const data = await response.json();
            if (data && !data.error) {
                window.currentExamReport = data;
                window.allStudents = data.students || [];
                setLastExamContext(data.exam_date, data.exam_time);
                return data;
            }
            if (data && data.error) {
                throw new Error(data.error);
            }
            throw new Error('گزارش جلسه در دسترس نیست');
        }

        let report = window.currentExamReport || null;
        let needsFetch = false;
        const ctxDate = context && context.exam_date ? normalizeDate(context.exam_date) : null;
        const ctxTime = context && context.exam_time ? normalizeTime(context.exam_time) : null;

        if (!report || !report.exam_date || !report.exam_time) {
            needsFetch = Boolean(ctxDate && ctxTime);
        } else if (ctxDate && ctxTime) {
            const repDate = normalizeDate(report.exam_date);
            const repTime = normalizeTime(report.exam_time);
            if (repDate !== ctxDate || repTime !== ctxTime) {
                needsFetch = true;
            }
        }

        if (needsFetch && ctxDate && ctxTime) {
            try {
                report = await getReportForContext(ctxDate, ctxTime);
            } catch (fetchErr) {
                Swal.close();
                return Swal.fire({ icon: 'error', title: 'خطا', text: fetchErr.message || 'خطا در دریافت اطلاعات جلسه', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
            }
        }

        report = report || { exam_date: '', exam_time: '', courses: [], students: window.allStudents || [] };

        // local esc helper (some contexts may not expose the global esc)
        const esc = (txt) => { try { const d = document.createElement('div'); d.textContent = txt || ''; return d.innerHTML; } catch (e) { return String(txt || ''); } };

        // Helper: parse seat numbers (supports ranges and Persian 'تا')
        const parseSeatNumbers = (raw) => {
            if (!raw && raw !== 0) return [];
            const s = String(raw).trim();
            const nums = [];
            const rangeMatch = s.match(/(\d+)\s*[-–—]\s*(\d+)/);
            if (rangeMatch) {
                const a = parseInt(rangeMatch[1], 10);
                const b = parseInt(rangeMatch[2], 10);
                if (!Number.isNaN(a) && !Number.isNaN(b)) {
                    const start = Math.min(a, b);
                    const end = Math.max(a, b);
                    const maxSpan = 1000;
                    const span = Math.min(end - start + 1, maxSpan);
                    for (let i = 0; i < span; i++) nums.push(start + i);
                    return nums;
                }
            }
            const persRange = s.match(/(\d+)\s*(?:تا|تا\u200c)\s*(\d+)/i);
            if (persRange) {
                const a = parseInt(persRange[1], 10);
                const b = parseInt(persRange[2], 10);
                if (!Number.isNaN(a) && !Number.isNaN(b)) {
                    const start = Math.min(a, b);
                    const end = Math.max(a, b);
                    const maxSpan = 1000;
                    const span = Math.min(end - start + 1, maxSpan);
                    for (let i = 0; i < span; i++) nums.push(start + i);
                    return nums;
                }
            }
            const matches = s.match(/\d+/g);
            if (matches && matches.length) {
                for (const m of matches) {
                    const v = parseInt(m, 10);
                    if (!Number.isNaN(v)) nums.push(v);
                }
            }
            return nums;
        };

        // Build HTML
        let docHtml = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>ملزومات منشی جلسه</title><link rel="stylesheet" href="${fontHref}">`;
        docHtml += `<style>
            @page { size: A4 portrait; margin: 6mm; }
            html, body { margin: 0; padding: 0; }
            body { font-family: Vazir, Tahoma, Arial, sans-serif; color: #111; font-size: 11pt; }
            /* Remove fixed min-height to avoid forcing an extra blank page when content fits on one page */
            .page { width: 210mm; box-sizing: border-box; padding: 6mm 8mm 8mm 8mm; overflow: visible; }
            .header { background: transparent; color: #000; padding: 2px 4px 4px 4px; text-align: center; margin-bottom:4px; }
            .header .title { font-size: 16pt; font-weight:900; margin-top:0; }
            .header .meta { font-size: 12pt; margin-top:4px; color:#000; font-weight:700; }
            .exam-type-section { width:96%; margin:12px auto 0 auto; }
            .exam-type-section.break-before { page-break-before: always; }
            .course { margin-top: 3mm; page-break-inside: avoid; break-inside: avoid; -webkit-column-break-inside: avoid; -webkit-page-break-inside: avoid; }
            .course-inner { width:100%; margin:0 auto; }
            .course .course-head { font-weight:800; font-size:12.5pt; margin-bottom:2px; }
            .course-header { width:100%; margin-bottom:2px; border-collapse:collapse; table-layout:fixed; }
            .course-header td { box-sizing:border-box; }
            .course-header .course-index { width:8%; background:#000; color:#fff; text-align:center; font-weight:900; padding:4px 0; border-radius:4px; font-size:11pt; }
            .course-header .course-info { width:77%; text-align:right; font-size:13pt; font-weight:700; padding:0 8px 0 0; }
            .course-header .course-total { width:15%; text-align:left; font-size:12pt; font-weight:700; padding:0 4px 0 0; }
            .nested { margin-top:4px; border-collapse:collapse; width:100%; box-sizing:border-box; table-layout:fixed; max-width:100%; min-width:0; }
            .nested, .nested th, .nested td { box-sizing: border-box; }
            .nested th, .nested td { border:1px solid #ddd; padding:3px 6px; text-align:right; overflow-wrap:anywhere; word-break:break-word; }
            .nested thead th { background:#f1f1f1; font-weight:700; text-align:center; font-size:10pt; }
            .nested thead { display: table-header-group; }
            .small { font-size:10pt; color:#555; }
            .course-type-group { width:100%; text-align:center; margin-bottom:6px; }
            /* type header spans same width as tables to keep alignment consistent */
            .etypeBar { display:block; width:100%; text-align:center; }
            .etypeBar .label { display:block; width:100%; background:#000; color:#fff; font-weight:900; padding:8px 0; border-radius:8px; font-size:15pt; }
            @media print { .no-print { display:none !important; } }
        </style></head><body>`;

        // Header: white background, black text, larger date/time
        const examDate = report.exam_date || '';
        const examTime = report.exam_time || '';
        docHtml += `<div class="page">`;
        docHtml += `<div class="header" style="background:transparent;color:#000;padding:4px 2px 6px 2px;text-align:center;">` +
            `<div class="title" style="font-size:16pt;font-weight:900;color:#000;margin-bottom:2px;">لیست منشی جلسه</div>` +
            `<div class="meta" style="font-size:12pt;margin-top:2px;color:#000;font-weight:700;">${toPersianDigits(examTime)} &nbsp; | &nbsp; ${toPersianDigits(examDate)}</div>` +
            `</div>`;

        // Prepare students and courses
        const students = report.students || [];
        const courses = report.courses || [];

        // We'll order by exam type: الکترونیکی first, then کتبی, then others
        const examOrder = ['الکترونیکی', 'کتبی'];
        const usedCourses = new Set();

        let hadPrevTypeCourses = false;
        let courseIndex = 0;
        for (const exType of examOrder.concat([''])) {
            // find courses that have students with this exam type
            const coursesForType = courses.filter(c => {
                const s = students.find(st => st.course_code === c.course_code && (st.exam_type || '') === exType);
                return !!s;
            });
            if (!coursesForType.length) continue;

            // For written exams (کتبی) insert a single page-break before the whole group
            // Instead of injecting an empty DIV (which can cause a blank page), apply the
            // page-break-before style to the header element itself for the first کتبی group.
            const sectionClass = 'exam-type-section';

            docHtml += `<div class="${sectionClass}">`;
            docHtml += `<div class="course-type-group"><div class="etypeBar"><div class="label">${esc(exType || 'سایر')}</div></div></div>`;

            for (const course of coursesForType) {


                docHtml += `<div class="course" style="page-break-inside: avoid; break-inside: avoid; -webkit-column-break-inside: avoid; -webkit-page-break-inside: avoid;"><div class="course-inner">`;
                usedCourses.add(course.course_code);

                const stu = students.filter(s => s.course_code === course.course_code && (s.exam_type || '') === exType);
                const total = stu.length;
                // Course header: row index | code | name  and count on the left as "NN نفر"
                courseIndex += 1;
                docHtml += `<table class="course-header"><tr>` +
                    `<td class="course-index">${toPersianDigits(courseIndex)}</td>` +
                    `<td class="course-info">${toPersianDigits(`${esc(course.course_code)} | ${esc(course.course_name || '')}`)}</td>` +
                    `<td class="course-total">${toPersianDigits(total)} نفر</td>` +
                    `</tr></table>`;

                // Group by building + class_name
                const groups = {};
                stu.forEach(s => {
                    const b = (s.building || '').trim() || 'بدون ساختمان';
                    const cl = (s.class_name || '').trim() || 'بدون کلاس';
                    const key = b + '||' + cl;
                    if (!groups[key]) groups[key] = { building: b, class_name: cl, nums: [] };
                    try {
                        const parsed = parseSeatNumbers(s.seat_number);
                        if (parsed && parsed.length) groups[key].nums.push(...parsed);
                    } catch (e) { /* ignore invalid */ }
                });

                // render nested table: order from/to/count before building/class
                docHtml += `<table class="nested" style="table-layout:fixed;width:100%;page-break-inside:avoid;min-width:0;"><thead><tr>` +
                    `<th style="width:12%;text-align:center;">از شماره</th>` +
                    `<th style="width:12%;text-align:center;">تا شماره</th>` +
                    `<th style="width:12%;text-align:center;">تعداد</th>` +
                    `<th style="width:34%;text-align:center;">ساختمان</th>` +
                    `<th style="width:30%;text-align:center;">کلاس / اتاق</th>` +
                    `</tr></thead><tbody>`;
                const gkeys = Object.keys(groups);
                if (!gkeys.length) {
                    docHtml += `<tr><td colspan="5" style="text-align:center">بدون اطلاعات کروکی برای این درس</td></tr>`;
                } else {
                    const rows = [];
                    gkeys.forEach(k => {
                        const g = groups[k];
                        const uniq = Array.from(new Set(g.nums)).sort((a, b) => a - b);
                        const start = uniq.length ? uniq[0] : null;
                        const end = uniq.length ? uniq[uniq.length - 1] : null;
                        const count = uniq.length;
                        if (start !== null) rows.push({ building: g.building, class_name: g.class_name, start, end, count });
                    });
                    // sort by building/class or start
                    rows.sort((a, b) => (a.building.localeCompare(b.building, 'fa') || a.class_name.localeCompare(b.class_name, 'fa') || a.start - b.start));
                    rows.forEach(r => {
                        docHtml += `<tr>` +
                            `<td style="text-align:center;padding:3px 6px;font-size:11pt;">${toPersianDigits(r.start)}</td>` +
                            `<td style="text-align:center;padding:3px 6px;font-size:11pt;">${toPersianDigits(r.end)}</td>` +
                            `<td style="text-align:center;padding:3px 6px;font-size:11pt;">${toPersianDigits(r.count)}</td>` +
                            `<td style="padding:3px 6px;font-size:10pt;text-align:right;">${toPersianDigits(esc(r.building))}</td>` +
                            `<td style="padding:3px 6px;font-size:10pt;text-align:right;">${toPersianDigits(esc(r.class_name))}</td>` +
                            `</tr>`;
                    });
                }
                docHtml += `</tbody></table>`;

                docHtml += `</div></div>`;
                hadPrevTypeCourses = true;
            }

            docHtml += `</div>`;
        }


        const remainingCourses = courses.filter(c => !usedCourses.has(c.course_code));
        if (remainingCourses.length) {
            docHtml += `<div class="course"><div class="course-head">سایر دروس:</div>`;
            remainingCourses.forEach(course => {
                const stu = students.filter(s => s.course_code === course.course_code);
                const total = stu.length;
                const codeStr = toPersianDigits(esc(course.course_code));
                const nameStr = toPersianDigits(esc(course.course_name || ''));
                docHtml += `<div style="margin-bottom:6px;"><strong>${codeStr}</strong> — ${nameStr} &nbsp; <span class="small">(تعداد: ${toPersianDigits(total)})</span></div>`;
            });
            docHtml += `</div>`;
        }

        // add a fixed footer which will show page numbers when printing. total will be filled by parent script.
        // docHtml += `<div class="printed-footer"><span class="totalPages"></span></div>`;
        docHtml += `</div></body></html>`;

        const iframe = document.createElement('iframe');
        iframe.style.position = 'fixed';
        iframe.style.left = '-10000px';
        iframe.style.top = '0';
        iframe.style.width = '210mm';
        iframe.style.height = '297mm';
        iframe.style.border = '0';
        iframe.style.visibility = 'hidden';
        document.body.appendChild(iframe);

        const doc = iframe.contentDocument || iframe.contentWindow.document;
        doc.open();
        doc.write(docHtml);
        doc.close();

        try { Swal.close(); } catch (e) { }


        try {
            const idoc = iframe.contentDocument || iframe.contentWindow.document;
            const pages = idoc.querySelectorAll('.page');
            if (pages && pages.length) {
                const last = pages[pages.length - 1];
                if (last && (!last.textContent || last.textContent.trim().length === 0)) {
                    last.parentNode.removeChild(last);
                }
            }
        } catch (e) {

        }


        const cw = iframe.contentWindow;
        let cleaned = false;
        const cleanup = () => {
            if (cleaned) return;
            cleaned = true;
            try { closeSwalLoadingHard(); } catch (e) { }
            try { document.body.removeChild(iframe); } catch (e) { }
            try { window.removeEventListener('focus', onFocusOnce, true); } catch (e) { }
            try { reopenEssentialsMenuIfRequested(); } catch (e) { }
        };
        const onFocusOnce = () => { setTimeout(cleanup, 150); };
        try {
            if (cw) {
                cw.onafterprint = cleanup;

                window.addEventListener('focus', onFocusOnce, true);
                try { safePrintIframe(iframe, cw); } catch (e) { console.error('Print invoke error:', e); }

                setTimeout(cleanup, 5000);
            } else {
                setTimeout(cleanup, 300);
            }
        } catch (e) {
            console.error('Print error:', e);
            setTimeout(cleanup, 300);
        }

    } catch (err) {

        try { console.error('Error building printable report (detailed):', err && err.stack ? err.stack : err); } catch (e) { console.error('Error logging failed', e); }

        const msg = (err && err.message) ? String(err.message) : 'خطا در آماده‌سازی گزارش چاپ';
        const stack = (err && err.stack) ? String(err.stack).split('\n').slice(0, 6).join('\n') : '';
        Swal.fire({ icon: 'error', title: 'خطا در آماده‌سازی گزارش چاپ', html: `<div style="text-align:right;direction:ltr;white-space:pre-wrap;">${esc(msg)}<br><small style='color:#666;margin-top:8px;display:block;'>${esc(stack)}</small></div>`, confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
    }
}


async function printEssentialsReproduction() {
    try {
        Swal.fire({
            title: 'در حال ساخت گزارش',
            html: 'لطفاً منتظر بمانید...',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); },
            customClass: { popup: 'swal2-rtl swal2-glass' },
            showConfirmButton: false
        });

        const fontHref = (window.location && window.location.origin ? window.location.origin : '') + '/assets/fonts/vazir/vazir.css';
        const university = (document.getElementById('footerText')?.textContent || '').trim().replace(/^نسار\s*-\s*/, '') || 'گزارش ملزومات اتاق تکثیر';

        const context = window._lastExamContext || null;
        const normalizeDate = (value) => toEnglishDigits(String(value || '')).replace(/-/g, '/');
        const normalizeTime = (value) => toEnglishDigits(String(value || ''));

        async function getReportForContext(examDate, examTime) {
            const response = await guardedFetch(`../API/getNextExamReport.php?exam_date=${encodeURIComponent(examDate)}&exam_time=${encodeURIComponent(examTime)}`, { cache: 'no-store' });
            const data = await response.json();
            if (data && !data.error) {
                window.currentExamReport = data;
                window.allStudents = data.students || [];
                setLastExamContext(data.exam_date, data.exam_time);
                return data;
            }
            if (data && data.error) {
                throw new Error(data.error);
            }
            throw new Error('گزارش جلسه در دسترس نیست');
        }

        let report = window.currentExamReport || null;
        let needsFetch = false;
        const ctxDate = context && context.exam_date ? normalizeDate(context.exam_date) : null;
        const ctxTime = context && context.exam_time ? normalizeTime(context.exam_time) : null;

        if (!report || !report.exam_date || !report.exam_time) {
            needsFetch = Boolean(ctxDate && ctxTime);
        } else if (ctxDate && ctxTime) {
            const repDate = normalizeDate(report.exam_date);
            const repTime = normalizeTime(report.exam_time);
            if (repDate !== ctxDate || repTime !== ctxTime) {
                needsFetch = true;
            }
        }

        if (needsFetch && ctxDate && ctxTime) {
            try {
                report = await getReportForContext(ctxDate, ctxTime);
            } catch (fetchErr) {
                Swal.close();
                return Swal.fire({ icon: 'error', title: 'خطا', text: fetchErr.message || 'خطا در دریافت اطلاعات جلسه', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
            }
        }

        report = report || { exam_date: '', exam_time: '', courses: [], students: window.allStudents || [] };

        const esc = (txt) => { try { const d = document.createElement('div'); d.textContent = txt || ''; return d.innerHTML; } catch (e) { return String(txt || ''); } };
        const parseSeatNumbers = (raw) => {
            if (!raw && raw !== 0) return [];
            const s = String(raw).trim();
            const nums = [];
            const rangeMatch = s.match(/(\d+)\s*[-–—]\s*(\d+)/);
            if (rangeMatch) {
                const a = parseInt(rangeMatch[1], 10);
                const b = parseInt(rangeMatch[2], 10);
                if (!Number.isNaN(a) && !Number.isNaN(b)) {
                    const start = Math.min(a, b);
                    const end = Math.max(a, b);
                    const maxSpan = 1000;
                    const span = Math.min(end - start + 1, maxSpan);
                    for (let i = 0; i < span; i++) nums.push(start + i);
                    return nums;
                }
            }
            const persRange = s.match(/(\d+)\s*(?:تا|تا\u200c)\s*(\d+)/i);
            if (persRange) {
                const a = parseInt(persRange[1], 10);
                const b = parseInt(persRange[2], 10);
                if (!Number.isNaN(a) && !Number.isNaN(b)) {
                    const start = Math.min(a, b);
                    const end = Math.max(a, b);
                    const maxSpan = 1000;
                    const span = Math.min(end - start + 1, maxSpan);
                    for (let i = 0; i < span; i++) nums.push(start + i);
                    return nums;
                }
            }
            const matches = s.match(/\d+/g);
            if (matches && matches.length) {
                for (const m of matches) {
                    const v = parseInt(m, 10);
                    if (!Number.isNaN(v)) nums.push(v);
                }
            }
            return nums;
        };

        let docHtml = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>ملزومات اتاق تکثیر</title><link rel="stylesheet" href="${fontHref}">`;
        docHtml += `<style>
            @page { size: A4 portrait; margin: 6mm; }
            html, body { margin: 0; padding: 0; }
            body { font-family: Vazir, Tahoma, Arial, sans-serif; color: #111; font-size: 11pt; }
            .page { width: 210mm; box-sizing: border-box; padding: 6mm 18mm 8mm 18mm; overflow: visible; }
            .header { background: transparent; color: #000; padding: 2px 4px 4px 4px; text-align: center; margin-bottom:4px; }
            .header .title { font-size: 16pt; font-weight:900; margin-top:0; }
            .header .meta { font-size: 12pt; margin-top:4px; color:#000; font-weight:700; }
            .course { margin-top: 3mm; page-break-inside: avoid; break-inside: avoid; -webkit-column-break-inside: avoid; -webkit-page-break-inside: avoid; }
            .course-inner { width:96%; margin:0 auto; }
            .course-header { width:100%; margin-bottom:2px; border-collapse:collapse; table-layout:fixed; }
            .course-header td { box-sizing:border-box; }
            .course-header .course-index { width:8%; background:#000; color:#fff; text-align:center; font-weight:900; padding:4px 0; border-radius:4px; font-size:11pt; }
            .course-header .course-info { width:77%; text-align:right; font-size:13pt; font-weight:700; padding:0 8px 0 0; }
            .course-header .course-total { width:15%; text-align:left; font-size:12pt; font-weight:700; padding:0 4px 0 0; }
            .nested { margin-top:4px; border-collapse:collapse; width:100%; box-sizing:border-box; table-layout:fixed; max-width:100%; min-width:0; }
            .nested, .nested th, .nested td { box-sizing: border-box; }
            .nested th, .nested td { border:1px solid #ddd; padding:3px 6px; text-align:right; overflow-wrap:anywhere; word-break:break-word; }
            .nested thead th { background:#f1f1f1; font-weight:700; text-align:center; font-size:10pt; }
            .nested thead { display: table-header-group; }
            .course-type-group { width:96%; margin:12px auto 6px auto; text-align:center; }
            .etypeBar { display:block; width:100%; text-align:center; }
            .etypeBar .label { display:block; width:100%; background:#000; color:#fff; font-weight:900; padding:8px 0; border-radius:8px; font-size:15pt; text-align:center; }
            .simple-list { width:100%; border-collapse:collapse; margin-top:6px; }
            .simple-list th, .simple-list td { border:1px solid #ddd; padding:4px 6px; font-size:12pt; }
            .simple-list thead th { background:#f1f1f1; text-align:center; }
        </style></head><body>`;

        const examDate = report.exam_date || '';
        const examTime = report.exam_time || '';
        docHtml += `<div class="page">`;
        docHtml += `<div class="header" style="background:transparent;color:#000;padding:4px 2px 6px 2px;text-align:center;">` +
            `<div class="title" style="font-size:16pt;font-weight:900;color:#000;margin-bottom:2px;">ملزومات اتاق تکثیر</div>` +
            `<div class="meta" style="font-size:12pt;margin-top:2px;color:#000;font-weight:700;">${toPersianDigits(examTime)} &nbsp; | &nbsp; ${toPersianDigits(examDate)}</div>` +
            `</div>`;

        const students = report.students || [];
        const courses = report.courses || [];

        // 1) Electronic section: summary rows only (course code + name + count)
        const electronicCourses = courses.filter(c => students.some(st => st.course_code === c.course_code && (st.exam_type || '') === 'الکترونیکی'));
        if (electronicCourses.length) {
            docHtml += `<div class="etypeBar"><div class="label">الکترونیکی</div></div>`;
            // Use same inner container width as written to avoid left overflow
            docHtml += `<div class="course" style="page-break-inside: avoid; break-inside: avoid; -webkit-column-break-inside: avoid; -webkit-page-break-inside: avoid;"><div class="course-inner">`;
            docHtml += `<table class="simple-list"><thead><tr><th style="width:8%">#</th><th style="width:18%">کد</th><th>نام درس</th><th style="width:18%">تعداد</th></tr></thead><tbody>`;
            let i = 0;
            electronicCourses.forEach(course => {
                const cnt = students.filter(s => s.course_code === course.course_code && (s.exam_type || '') === 'الکترونیکی').length;
                if (cnt > 0) {
                    i += 1;
                    const codeCell = toPersianDigits(esc(course.course_code || ''));
                    const nameCell = toPersianDigits(esc(course.course_name || ''));
                    docHtml += `<tr>` +
                        `<td style=\"text-align:center;font-weight:700;\">${toPersianDigits(i)}</td>` +
                        `<td style=\"text-align:center;\">${codeCell}</td>` +
                        `<td style=\"text-align:right;\">${nameCell}</td>` +
                        `<td style=\"text-align:center;font-weight:700;\">${toPersianDigits(cnt)}</td>` +
                        `</tr>`;
                }
            });
            if (i === 0) docHtml += `<tr><td colspan=\"4\" style=\"text-align:center\">بدون درس الکترونیکی</td></tr>`;
            docHtml += `</tbody></table>`;
            docHtml += `</div></div>`;
        }

        // 2) Written section: full detail like secretary
        const writtenCourses = courses.filter(c => students.some(st => st.course_code === c.course_code && (st.exam_type || '') === 'کتبی'));
        if (writtenCourses.length) {
            docHtml += `<div>`;
            docHtml += `<div class="etypeBar" style="margin-top:8px;"><div class="label">کتبی</div></div>`;
            let courseIndex = 0;
            writtenCourses.forEach(course => {
                const stu = students.filter(s => s.course_code === course.course_code && (s.exam_type || '') === 'کتبی');
                const total = stu.length;
                courseIndex += 1;
                docHtml += `<div class="course" style="page-break-inside: avoid; break-inside: avoid; -webkit-column-break-inside: avoid; -webkit-page-break-inside: avoid;"><div class="course-inner">`;
                docHtml += `<table class="course-header"><tr>` +
                    `<td class="course-index">${toPersianDigits(courseIndex)}</td>` +
                    `<td class="course-info">${toPersianDigits(`${esc(course.course_code)} | ${esc(course.course_name || '')}`)}</td>` +
                    `<td class="course-total">${toPersianDigits(total)} نفر</td>` +
                    `</tr></table>`;

                const groups = {};
                stu.forEach(s => {
                    const b = (s.building || '').trim() || 'بدون ساختمان';
                    const cl = (s.class_name || '').trim() || 'بدون کلاس';
                    const key = b + '||' + cl;
                    if (!groups[key]) groups[key] = { building: b, class_name: cl, nums: [] };
                    try {
                        const parsed = parseSeatNumbers(s.seat_number);
                        if (parsed && parsed.length) groups[key].nums.push(...parsed);
                    } catch (e) { /* ignore */ }
                });

                docHtml += `<table class="nested" style="table-layout:fixed;width:100%;page-break-inside:avoid;min-width:0;"><thead><tr>` +
                    `<th style="width:12%;text-align:center;">از شماره</th>` +
                    `<th style="width:12%;text-align:center;">تا شماره</th>` +
                    `<th style="width:12%;text-align:center;">تعداد</th>` +
                    `<th style="width:34%;text-align:center;">ساختمان</th>` +
                    `<th style="width:30%;text-align:center;">کلاس / اتاق</th>` +
                    `</tr></thead><tbody>`;
                const gkeys = Object.keys(groups);
                if (!gkeys.length) {
                    docHtml += `<tr><td colspan="5" style="text-align:center">بدون اطلاعات کروکی برای این درس</td></tr>`;
                } else {
                    const rows = [];
                    gkeys.forEach(k => {
                        const g = groups[k];
                        const uniq = Array.from(new Set(g.nums)).sort((a, b) => a - b);
                        const start = uniq.length ? uniq[0] : null;
                        const end = uniq.length ? uniq[uniq.length - 1] : null;
                        const count = uniq.length;
                        if (start !== null) rows.push({ building: g.building, class_name: g.class_name, start, end, count });
                    });
                    rows.sort((a, b) => (a.building.localeCompare(b.building, 'fa') || a.class_name.localeCompare(b.class_name, 'fa') || a.start - b.start));
                    rows.forEach(r => {
                        docHtml += `<tr>` +
                            `<td style="text-align:center;padding:3px 6px;font-size:11pt;">${toPersianDigits(r.start)}</td>` +
                            `<td style="text-align:center;padding:3px 6px;font-size:11pt;">${toPersianDigits(r.end)}</td>` +
                            `<td style="text-align:center;padding:3px 6px;font-size:11pt;">${toPersianDigits(r.count)}</td>` +
                            `<td style="padding:3px 6px;font-size:10pt;text-align:right;">${toPersianDigits(esc(r.building))}</td>` +
                            `<td style="padding:3px 6px;font-size:10pt;text-align:right;">${toPersianDigits(esc(r.class_name))}</td>` +
                            `</tr>`;
                    });
                }
                docHtml += `</tbody></table>`;
                docHtml += `</div></div>`;
            });
            docHtml += `</div>`;
        }

        docHtml += `</div></body></html>`;

        const iframe = document.createElement('iframe');
        iframe.style.position = 'fixed';
        iframe.style.left = '-10000px';
        iframe.style.top = '0';
        iframe.style.width = '210mm';
        iframe.style.height = '297mm';
        iframe.style.border = '0';
        iframe.style.visibility = 'hidden';
        document.body.appendChild(iframe);

        const doc = iframe.contentDocument || iframe.contentWindow.document;
        doc.open();
        doc.write(docHtml);
        doc.close();


        try { Swal.close(); } catch (e) { }


        try {
            const idoc = iframe.contentDocument || iframe.contentWindow.document;
            const pages = idoc.querySelectorAll('.page');
            if (pages && pages.length) {
                const last = pages[pages.length - 1];
                if (last && (!last.textContent || last.textContent.trim().length === 0)) {
                    last.parentNode.removeChild(last);
                }
            }
        } catch (e) { }


        const cw = iframe.contentWindow;
        let cleaned = false;
        const cleanup = () => {
            if (cleaned) return;
            cleaned = true;
            try { closeSwalLoadingHard(); } catch (e) { }
            try { document.body.removeChild(iframe); } catch (e) { }
            try { window.removeEventListener('focus', onFocusOnce, true); } catch (e) { }
            try { reopenEssentialsMenuIfRequested(); } catch (e) { }
        };
        const onFocusOnce = () => { setTimeout(cleanup, 150); };
        try {
            if (cw) {
                cw.onafterprint = cleanup;
                window.addEventListener('focus', onFocusOnce, true);
                try { safePrintIframe(iframe, cw); } catch (e) { console.error('Print invoke error:', e); }
                setTimeout(cleanup, 5000);
            } else {
                setTimeout(cleanup, 300);
            }
        } catch (e) {
            console.error('Print error:', e);
            setTimeout(cleanup, 300);
        }

    } catch (err) {
        console.error('Error building printable report:', err);
        Swal.fire({ icon: 'error', title: 'خطا', text: 'خطا در آماده‌سازی گزارش چاپ', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
    }
}


async function printEssentialsDescriptive() {
    try {
        Swal.fire({
            title: 'در حال ساخت گزارش',
            html: 'لطفاً منتظر بمانید...',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); },
            customClass: { popup: 'swal2-rtl swal2-glass' },
            showConfirmButton: false
        });

        const fontHref = (window.location && window.location.origin ? window.location.origin : '') + '/assets/fonts/vazir/vazir.css';
        const university = (document.getElementById('footerText')?.textContent || '').trim().replace(/^نسار\s*-\s*/, '') || 'برچسب پاکت‌های تشریحی';

        // Ensure we have current exam report in context
        const context = window._lastExamContext || null;
        const normalizeDate = (value) => toEnglishDigits(String(value || '')).replace(/-/g, '/');
        const normalizeTime = (value) => toEnglishDigits(String(value || ''));

        async function getReportForContext(examDate, examTime) {
            const response = await guardedFetch(`../API/getNextExamReport.php?exam_date=${encodeURIComponent(examDate)}&exam_time=${encodeURIComponent(examTime)}`, { cache: 'no-store' });
            const data = await response.json();
            if (data && !data.error) {
                window.currentExamReport = data;
                window.allStudents = data.students || [];
                setLastExamContext(data.exam_date, data.exam_time);
                return data;
            }
            if (data && data.error) throw new Error(data.error);
            throw new Error('گزارش جلسه در دسترس نیست');
        }

        let report = window.currentExamReport || null;
        const ctxDate = context && context.exam_date ? normalizeDate(context.exam_date) : null;
        const ctxTime = context && context.exam_time ? normalizeTime(context.exam_time) : null;
        if ((!report || !report.exam_date || !report.exam_time) && ctxDate && ctxTime) {
            try { report = await getReportForContext(ctxDate, ctxTime); } catch (e) { /* ignore, show error later */ }
        }
        report = report || { exam_date: ctxDate || '', exam_time: ctxTime || '', courses: [], students: window.allStudents || [] };

        const courses = Array.isArray(report.courses) ? report.courses.slice() : [];
        // Only exclude pure "تستی" courses; include everything else (تشریحی و ترکیبی بماند)
        const normalizeType = (t) => String(t || '').trim().replace(/\s*[-–—]\s*/g, '-').replace(/\s+/g, ' ');
        const isPureTest = (t) => normalizeType(t) === 'تستی';
        const selectedCourses = courses.filter(c => !isPureTest(c.course_type));
        if (!selectedCourses.length) {
            try { closeSwalLoadingHard(); } catch (e) { }
            return Swal.fire({
                icon: 'info',
                title: 'اطلاعات',
                text: 'هیچ درسی برای چاپ برچسب پاکت یافت نشد',
                showConfirmButton: true,
                confirmButtonText: 'باشه',
                allowOutsideClick: true,
                allowEscapeKey: true,
                didOpen: () => { try { Swal.hideLoading(); } catch (e) { } },
                customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' }
            });
        }

        const dateFa = toPersianDigits(report.exam_date || ctxDate || '');
        const timeFa = toPersianDigits(report.exam_time || ctxTime || '');
        // Common CSS for the printable descriptive label
        const commonHead = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>برچسب پاکت‌های تشریحی</title><link rel="stylesheet" href="${fontHref}">`;
        const commonStyle = `<style>
            @page { size: A5 landscape; margin: 8mm 8mm 8mm 5mm; }
            html, body { margin: 0; padding: 0; }
            body { font-family: Vazir, Tahoma, Arial, sans-serif; color: #111; }
            .page { box-sizing: border-box; padding: 1mm 10mm 0mm 18mm; display:flex; flex-direction:column; justify-content:flex-start; gap: 4mm; overflow: hidden; page-break-inside: avoid; break-inside: avoid; }
            .page + .page { page-break-before: always; break-before: page; }
            .main { font-size: 13.2pt; line-height: 1.65; text-align: justify; text-justify: inter-word; flex: 0 0 auto; }
            .strong { font-weight: 900; }
            .count-blank { display:inline-block; min-width: 10mm; border-bottom: 2px solid #000; margin: 0 3mm; position:relative; top: -1px; }
            .footer { margin-top: 2mm; }
            .signatures { width:100%; border-collapse: collapse; table-layout: fixed; }
            .signatures td { border:1px solid #111; padding: 5mm; vertical-align: top; font-size: 10pt; height: 26mm; }
            .sign-title { font-weight: 700; margin-bottom: 6mm; display:block; text-align:center; font-size: 11pt; }
            .sign-line { display:block; border-top: 1px dashed #444; height: 0; margin-top: 6mm; }
            @media print { .page { page-break-inside: avoid; } .page + .page { page-break-before: always; } }
        </style></head>`;

        const buildSinglePageBody = (cName, cCode, cType) => `
            <div class="page">
                <div class="main">
                    <div style="margin-bottom: 6mm; font-weight:800;">استاد ارجمند؛</div>
                    <div>
                        بدین وسیله تعداد <span class="count-blank"></span> برگه تشریحی مربوط به درس <span class="strong">${cName}</span> با کد <span class="strong">${cCode}</span>
                        که آزمون آن در تاریخ <span class="strong">${dateFa}</span> و ساعت <span class="strong">${timeFa}</span>
                        به صورت <span class="strong">${cType}</span> برگزار گردیده، تحویل حضور استاد محترم می‌گردد.
                    </div>
                    <div style="margin-top: 5mm;">
                        <span class="strong">تأکید می‌شود:</span><br/>
                        بر اساس ضوابط آموزشی، استاد محترم موظف است مطابق با نمونه سوالات ضمیمه و کلید سؤالات موجود در سامانه گلستان، حداکثر ظرف ۵ روز پس از تاریخ تحویل، نسبت به تصحیح کامل اوراق و ثبت نمرات نهایی در سامانه گلستان اقدام نماید.
                    </div>
                </div>
                <div class="footer">
                    <table class="signatures">
                        <tr>
                            <td>
                                <span class="sign-title">تحویل‌دهنده</span>
                                <div>نام و نام خانوادگی: __________________________</div>
                                <div class="sign-line"></div>
                                <div style="margin-top:3mm;text-align:center;font-weight:700;">امضاء</div>
                            </td>
                            <td>
                                <span class="sign-title">تحویل‌گیرنده (استاد)</span>
                                <div>نام و نام خانوادگی: __________________________</div>
                                <div class="sign-line"></div>
                                <div style="margin-top:3mm;text-align:center;font-weight:700;">امضاء</div>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>`;

        // Build pages with fixed font sizes (no dynamic fitting)
        const pages = [];
        for (const course of selectedCourses) {
            const cName = toPersianDigits(String(course.course_name || ''));
            const cCode = toPersianDigits(String(course.course_code || ''));
            const cType = toPersianDigits(String(course.course_type || 'کتبی'));
            pages.push(buildSinglePageBody(cName, cCode, cType));
        }

        // Now write final combined document (single style + many pages)
        const finalHtml = commonHead + commonStyle + `<body>` + pages.join('') + `</body></html>`;
        // Create a hidden iframe to render and print
        const iframe = document.createElement('iframe');
        iframe.style.position = 'fixed';
        iframe.style.left = '-10000px';
        iframe.style.top = '0';
        iframe.style.width = '210mm';
        iframe.style.height = '148mm';
        iframe.style.border = '0';
        iframe.style.visibility = 'hidden';
        document.body.appendChild(iframe);
        try {
            const doc = iframe.contentDocument || iframe.contentWindow.document;
            doc.open();
            doc.write(finalHtml);
            doc.close();
        } catch (e) { }

        try { Swal.close(); } catch (e) { }

        const cw = iframe.contentWindow;
        let cleaned = false;
        const cleanup = () => {
            if (cleaned) return;
            cleaned = true;
            try { closeSwalLoadingHard(); } catch (e) { }
            try { document.body.removeChild(iframe); } catch (e) { }
            try { window.removeEventListener('focus', onFocusOnce, true); } catch (e) { }
            try { reopenEssentialsMenuIfRequested(); } catch (e) { }
        };
        const onFocusOnce = () => { setTimeout(cleanup, 150); };
        try {
            if (cw) {
                cw.onafterprint = cleanup;
                window.addEventListener('focus', onFocusOnce, true);
                try { safePrintIframe(iframe, cw); } catch (e) { console.error('Print invoke error:', e); }
                setTimeout(cleanup, 5000);
            } else {
                setTimeout(cleanup, 300);
            }
        } catch (e) {
            console.error('Print error:', e);
            setTimeout(cleanup, 300);
        }

    } catch (err) {
        console.error('Error building printable report:', err);
        Swal.fire({ icon: 'error', title: 'خطا', text: 'خطا در آماده‌سازی گزارش چاپ', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
    }
}


async function printEssentialsTest() {
    try {
        Swal.fire({
            title: 'در حال ساخت گزارش',
            html: 'لطفاً منتظر بمانید...',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); },
            customClass: { popup: 'swal2-rtl swal2-glass' },
            showConfirmButton: false
        });

        const fontHref = (window.location && window.location.origin ? window.location.origin : '') + '/assets/fonts/vazir/vazir.css';
        const university = (document.getElementById('footerText')?.textContent || '').trim().replace(/^نسار\s*-\s*/, '') || 'برچسب پاکت‌های تستی';

        let docHtml = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>برچسب پاکت‌های تستی</title><link rel="stylesheet" href="${fontHref}">`;
        docHtml += `<style>
            @page { size: A5 landscape; margin: 4mm; }
            html, body { margin: 0; padding: 0; }
            body { font-family: Vazir, Tahoma, Arial, sans-serif; color: #111; font-size: 10pt; }
            .page { width: 210mm; height: 148mm; box-sizing: border-box; padding: 8mm; }
            @media print {
                .no-print { display: none !important; }
            }
        </style></head><body>`;
        docHtml += `<div class="page">`;
        docHtml += `<div style="text-align:center;font-size:16pt;font-weight:700;margin-bottom:15mm;">${university}</div>`;
        docHtml += `<div style="text-align:center;font-size:14pt;font-weight:600;margin-bottom:8mm;">برچسب پاکت‌های تستی</div>`;
        docHtml += `<div style="text-align:center;color:#666;margin-top:30mm;">محتوای گزارش به زودی اضافه خواهد شد</div>`;
        docHtml += `</div></body></html>`;

        const iframe = document.createElement('iframe');
        iframe.style.position = 'fixed';
        iframe.style.left = '-10000px';
        iframe.style.top = '0';
        iframe.style.width = '210mm';
        iframe.style.height = '148mm';
        iframe.style.border = '0';
        iframe.style.visibility = 'hidden';
        document.body.appendChild(iframe);

        const doc = iframe.contentDocument || iframe.contentWindow.document;
        doc.open();
        doc.write(docHtml);
        doc.close();


        try { Swal.close(); } catch (e) { }


        const cw = iframe.contentWindow;
        let cleaned = false;
        const cleanup = () => {
            if (cleaned) return;
            cleaned = true;
            try { closeSwalLoadingHard(); } catch (e) { }
            try { document.body.removeChild(iframe); } catch (e) { }
            try { window.removeEventListener('focus', onFocusOnce, true); } catch (e) { }
            try { reopenEssentialsMenuIfRequested(); } catch (e) { }
        };
        const onFocusOnce = () => { setTimeout(cleanup, 150); };
        try {
            if (cw) {
                cw.onafterprint = cleanup;
                window.addEventListener('focus', onFocusOnce, true);
                try { safePrintIframe(iframe, cw); } catch (e) { console.error('Print invoke error:', e); }
                setTimeout(cleanup, 5000);
            } else {
                setTimeout(cleanup, 300);
            }
        } catch (e) {
            console.error('Print error:', e);
            setTimeout(cleanup, 300);
        }

    } catch (err) {
        console.error('Error building printable report:', err);
        Swal.fire({ icon: 'error', title: 'خطا', text: 'خطا در آماده‌سازی گزارش چاپ', confirmButtonText: 'باشه', customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary' } });
    }
}

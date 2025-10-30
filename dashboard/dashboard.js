// Device detection and SweetAlert for non-desktop users
function isDesktopDevice() {
    const isTouch = 'ontouchstart' in window || navigator.maxTouchPoints > 1;
    const width = window.innerWidth || document.documentElement.clientWidth;
    const userAgent = navigator.userAgent.toLowerCase();
    const isMobileUA = /android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini/.test(userAgent);
    return !isTouch && width > 900 && !isMobileUA;
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
    نِسار (نسخه ${VERSION}) یک وب‌اپلیکیشن پیشرفته و مدرن است که با بهره‌گیری از طراحی مبتنی بر تجربه کاربری نوین و سبک گلس‌مورفیسم، به دانشجویان دانشگاه پیام نور این امکان را می‌دهد تا برنامه امتحانات، شماره صندلی، محل برگزاری و وضعیت آزمون‌های خود را به‌صورت یکپارچه و متمرکز مشاهده کنند.
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
        let html = `
            <div class="mb-4">
                <h5 class="text-primary mb-3">${headerTitle}</h5>
				<div class="table-responsive">
					<table class="table table-bordered">
						<tr>
							<th style="width: 30%;">تاریخ آزمون</th>
							<td>${data.exam_date}</td>
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

        // Show course list only if more than one course
        if (courses && courses.length > 1) {
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

        // Course pie
        const cCtx = document.getElementById('miniPieCourse').getContext('2d');
        miniPieInstances.course = new Chart(cCtx, {
            type: 'doughnut',
            data: { labels: courseLabels, datasets: [{ data: courseValues, backgroundColor: courseLabels.map((_, i) => palette[i % palette.length]), borderColor: 'rgba(255,255,255,0.6)', borderWidth: 4 }] },
            options: {
                responsive: false,
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
                },
                cutout: '30%'
            }
        });

        // Exam type pie
        const eCtx = document.getElementById('miniPieExamType').getContext('2d');
        miniPieInstances.examType = new Chart(eCtx, {
            type: 'doughnut',
            data: { labels: examLabels, datasets: [{ data: examValues, backgroundColor: examLabels.map((_, i) => palette[i % palette.length]), borderColor: 'rgba(255,255,255,0.6)', borderWidth: 4 }] },
            options: {
                responsive: false,
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
                },
                cutout: '40%'
            }
        });

        // Course type pie
        const ctCtx = document.getElementById('miniPieCourseType').getContext('2d');
        miniPieInstances.courseType = new Chart(ctCtx, {
            type: 'doughnut',
            data: { labels: ctLabels, datasets: [{ data: ctValues, backgroundColor: ctLabels.map((_, i) => palette[i % palette.length]), borderColor: 'rgba(255,255,255,0.6)', borderWidth: 4 }] },
            options: {
                responsive: false,
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
                },
                cutout: '40%'
            }
        });

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

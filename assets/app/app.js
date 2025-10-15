document.addEventListener('DOMContentLoaded', () => {
    // Listen for service worker update messages and show SweetAlert
    if (navigator.serviceWorker) {
        navigator.serviceWorker.addEventListener('message', event => {
            if (event.data?.type === 'sw-update') {
                const { version, changes } = event.data || {};
                // Short list (in case SW sent verbose) and show reload button
                const items = (changes || []).slice(0, 5);
                let countdownInterval = null;
                let autoSeconds = 10;
                const html = `
                    <div style="text-align:right;direction:rtl;">
                        <ul style="margin:0 0 0.6rem 0;padding-inline-start:1rem;">${items.map(c => `<li>${c}</li>`).join('')}</ul>
                        <div class="swal2-countdown">بارگذاری خودکار در <strong class="swal2-countdown-value">${autoSeconds}</strong> ثانیه...</div>
                    </div>`;

                Swal.fire({
                    icon: 'info',
                    title: 'نسخه جدید آماده است',
                    html,
                    showCancelButton: true,
                    confirmButtonText: 'بارگذاری مجدد',
                    cancelButtonText: 'بعدا',
                    buttonsStyling: false,
                    customClass: { popup: 'swal2-rtl swal2-glass', confirmButton: 'btn btn-primary', cancelButton: 'btn btn-secondary' },
                    width: 520,
                    didOpen: () => {
                        const valueEl = Swal.getHtmlContainer()?.querySelector('.swal2-countdown-value');
                        if (!valueEl) return;
                        const tick = () => {
                            autoSeconds -= 1;
                            valueEl.textContent = autoSeconds;
                            if (autoSeconds <= 0) {
                                clearInterval(countdownInterval);
                                // force reload to pick up new SW
                                window.location.reload(true);
                            }
                        };
                        countdownInterval = window.setInterval(tick, 1000);
                    },
                    willClose: () => {
                        if (countdownInterval) clearInterval(countdownInterval);
                    }
                }).then(result => {
                    if (countdownInterval) clearInterval(countdownInterval);
                    if (result.isConfirmed) {
                        window.location.reload(true);
                    }
                });
            }
        });
    }
    const form = document.getElementById('examForm');
    const searchBtn = document.getElementById('searchBtn');
    const examCards = document.getElementById('examCards');
    const studentTypeRadio = document.getElementById('studentType');
    const staffTypeRadio = document.getElementById('staffType');
    const firstFieldLabel = document.getElementById('firstFieldLabel');
    const secondFieldLabel = document.getElementById('secondFieldLabel');
    const studentIdInput = document.getElementById('studentId');
    const nationalIdInput = document.getElementById('nationalId');
    const loginRow = document.getElementById('loginRow');
    const loginSection = document.getElementById('loginSection');
    const footerClock = document.getElementById('footerClock');
    const footerSpacer = document.querySelector('.footer-spacer');
    const REFRESH_INTERVAL_MS = 60000;
    const CLOCK_REFRESH_MS = REFRESH_INTERVAL_MS;

    let refreshTimer = null;
    let clockTimer = null;
    let currentCredentials = null;
    let lastSnapshot = '';
    let lastPayload = [];
    let lastFullName = '';

    function getPersianMonthName(dateStr) {
        const parts = dateStr.split('/');
        const month = parseInt(parts[1], 10);
        const months = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
        return months[month] || '';
    }

    // Fetch and cache config
    async function loadConfig() {
        try {
            const response = await fetch('API/getConfig.php');
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const config = await response.json();
            localStorage.setItem('appConfig', JSON.stringify(config));
            return config;
        } catch (error) {
            console.warn('Failed to load config:', error);
            // Fallback to cached config
            const cached = localStorage.getItem('appConfig');
            return cached ? JSON.parse(cached) : { University: '', Order: '', IsInit: 'NO' };
        }
    }

    // Load config on start
    let appConfig = null;
    loadConfig().then(config => {
        appConfig = config;
        // Update footer text with University
        const footerText = document.getElementById('footerText');
        if (footerText) {
            footerText.textContent = config.University ? `نسار - ${config.University}` : 'نسار';
        }

        // Check IsInit
        if (config.IsInit !== 'YES') {
            showInitModal(config);
        }
    });

    async function showInitModal(currentConfig) {
        const { value: formValues } = await Swal.fire({
            title: 'تنظیمات اولیه',
            html: `
                <div style="text-align:right; direction:rtl; line-height:2; max-width: 400px; margin: 0 auto;">
                    <label for="swal-order">سفارش‌دهنده:</label><br>
                    <input id="swal-order" class="swal2-input" value="" style="width:100%; max-width: 380px; margin-bottom:10px;"><br>
                    <label for="swal-university">دانشگاه:</label><br>
                    <input id="swal-university" class="swal2-input" value="" style="width:100%; max-width: 380px;">
                </div>
            `,
            focusConfirm: false,
            showCancelButton: false,
            confirmButtonText: 'ذخیره',
            width: 600,
            buttonsStyling: false,
            allowOutsideClick: false,
            allowEscapeKey: false,
            preConfirm: () => {
                const order = document.getElementById('swal-order').value.trim();
                const university = document.getElementById('swal-university').value.trim();
                if (!order || !university) {
                    Swal.showValidationMessage('هر دو فیلد باید پر شوند');
                    return false;
                }
                return { Order: order, University: university };
            },
            customClass: {
                popup: 'swal2-rtl swal2-glass',
                confirmButton: 'btn btn-primary mx-2'
            }
        });

        if (formValues) {
            try {
                const response = await fetch('API/updateConfig.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formValues)
                });
                const result = await response.json();
                if (result.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'ذخیره شد',
                        text: 'تنظیمات آپدیت شد.',
                        confirmButtonText: 'باشه',
                        customClass: {
                            popup: 'swal2-rtl swal2-glass'
                        }
                    });
                    // Reload config
                    const newConfig = await loadConfig();
                    appConfig = newConfig;
                    const footerText = document.getElementById('footerText');
                    if (footerText) {
                        footerText.textContent = newConfig.University ? `نسار - ${newConfig.University}` : 'نسار';
                    }
                } else {
                    throw new Error(result.error || 'خطا در آپدیت');
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'خطا',
                    text: 'خطا در ذخیره تنظیمات.',
                    confirmButtonText: 'باشه',
                    customClass: {
                        popup: 'swal2-rtl swal2-glass'
                    }
                });
            }
        }
    }

    // Temporarily disable staff mode (only student mode active for now)
    if (staffTypeRadio) {
        staffTypeRadio.disabled = true;
        // Optional: ensure student is selected
        if (studentTypeRadio) studentTypeRadio.checked = true;
    }

    // Handle user type change
    function handleUserTypeChange() {
        // Clear input fields
        studentIdInput.value = '';
        nationalIdInput.value = '';

        // Only student mode is active for now
        if (studentTypeRadio.checked || (staffTypeRadio && staffTypeRadio.disabled)) {
            // Student mode
            firstFieldLabel.textContent = 'شماره دانشجویی';
            secondFieldLabel.textContent = 'کد ملی / شماره شناسنامه';
            studentIdInput.placeholder = 'مثال: 403254321';
            nationalIdInput.placeholder = 'مثال: 3781985569';
            nationalIdInput.type = 'tel';
        } else if (staffTypeRadio && staffTypeRadio.checked) {
            // Staff mode (disabled for now)
            firstFieldLabel.textContent = 'نام کاربری';
            secondFieldLabel.textContent = 'رمز عبور';
            studentIdInput.placeholder = '';
            nationalIdInput.placeholder = '';
            nationalIdInput.type = 'password';
        }
    }

    // Add event listeners for radio buttons
    studentTypeRadio.addEventListener('change', handleUserTypeChange);
    if (!staffTypeRadio.disabled) {
        staffTypeRadio.addEventListener('change', handleUserTypeChange);
    }

    // Initialize with default state
    handleUserTypeChange();
    updateServerClock();
    if (footerClock && footerSpacer) footerSpacer.textContent = footerClock.textContent;
    startClockRefresh();

    // Copyright footer click event
    const copyrightFooter = document.getElementById('copyrightFooter');
    if (copyrightFooter) {
        copyrightFooter.addEventListener('click', async () => {
            let countdownInterval;
            // Ensure config is loaded
            if (!appConfig) {
                appConfig = await loadConfig();
            }
            const university = appConfig.University || 'دانشگاه پیام نور مرکز بیجار';
            const order = appConfig.Order || 'اداره آموزش، پژوهش، فرهنگی و دانشجوئی دانشگاه پیام نور مرکز بیجار';
            Swal.fire({
                title: 'درباره اپلیکیشن',
                html: `
                    <div style="line-height:1.9;font-size:1.05rem;text-align:justify;">
                       نسار یک نرم افزار وب اپلیکیشن پیشرو است که با رویکرد تجربه کاربری مدرن و ظاهر شیشه‌ای (گلس مورفیسم) به دانشجویان پیام نور کمک می‌کند تا برنامه‌ی امتحانات، شماره صندلی، محل برگزاری و وضعیت آزمون‌های خود را یک‌جا مشاهده کنند.
<br>
                       این برنامه به سفارش <span style="color: lime; font-weight: bold;">${escapeHtml(order)}</span> و توسط <span style="color: gold; font-weight: bold;">مهدی حسنی</span> توسعه یافته است
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
        });
    }

    // --- Add to Home Screen (A2HS) prompts ---
    let deferredPrompt = null;
    let shownInstallHint = false;

    // Detect if already installed (standalone display mode)
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

    window.addEventListener('beforeinstallprompt', (e) => {
        // Chrome/Android fires this when eligible
        // We must call preventDefault to show our custom prompt later
        e.preventDefault();
        deferredPrompt = e;
        if (!isStandalone) {
            // Show our custom install prompt
            showInstallToast();
        }
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt = null;
        // Inform user installed successfully
        Swal.fire({
            icon: 'success',
            title: 'نصب شد',
            text: 'اپلیکیشن «نسار» با موفقیت به صفحه اصلی شما اضافه شد.',
            confirmButtonText: 'باشه',
            buttonsStyling: false,
            customClass: {
                popup: 'swal2-rtl swal2-glass',
                confirmButton: 'btn btn-primary btn-lg px-4'
            }
        });
    });

    function showInstallToast() {
        // Only once per session
        if (shownInstallHint) return; shownInstallHint = true;

        const isIOS = /iphone|ipad|ipod/i.test(window.navigator.userAgent);
        const isAndroid = /android/i.test(window.navigator.userAgent);

        if (isAndroid && deferredPrompt) {
            Swal.fire({
                title: 'نصب به‌عنوان اپلیکیشن',
                html: 'می‌توانید «نسار» را به صفحه اصلی اضافه کنید تا مثل یک اپ اجرا شود.',
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: 'نصب کن',
                cancelButtonText: 'بعداً',
                buttonsStyling: false,
                customClass: {
                    popup: 'swal2-rtl swal2-glass',
                    confirmButton: 'btn btn-primary mx-2',
                    cancelButton: 'btn btn-light mx-2'
                }
            }).then(async (result) => {
                if (result.isConfirmed && deferredPrompt) {
                    deferredPrompt.prompt();
                    const choice = await deferredPrompt.userChoice;
                    deferredPrompt = null;
                    if (choice.outcome === 'accepted') {
                        // user accepted; nothing else needed
                    }
                }
            });
        } else if (isIOS && !isStandalone) {
            // iOS Safari doesn't support beforeinstallprompt; show manual steps
            Swal.fire({
                title: 'افزودن به صفحه اصلی (iOS)',
                html: `
                    <div style="text-align:right;line-height:1.9">
                      1) دکمه Share در Safari را بزنید.<br>
                      2) گزینه <b>Add to Home Screen</b> را انتخاب کنید.<br>
                      3) روی <b>Add</b> بزنید تا «نسار» به صفحه اصلی اضافه شود.
                    </div>
                `,
                icon: 'info',
                confirmButtonText: 'متوجه شدم',
                buttonsStyling: false,
                customClass: {
                    popup: 'swal2-rtl swal2-glass',
                    confirmButton: 'btn btn-primary btn-lg px-4'
                }
            });
        }
    }

    // Offer install hint shortly after load if eligible
    setTimeout(() => { if (!isStandalone) showInstallToast(); }, 1500);

    // Encryption helpers
    const ENCRYPTION_KEY = 'PNU_EXAM_SEAT_2025_SECRET_KEY'; // In production, this should be more secure

    function encryptData(data) {
        try {
            // Use simple Base64 encoding for compatibility
            const jsonString = JSON.stringify(data);
            const encoded = btoa(unescape(encodeURIComponent(jsonString)));
            return encoded;
        } catch (e) {
            console.error('Encryption failed:', e);
            return null;
        }
    }

    function decryptData(encryptedData) {
        try {
            const decoded = decodeURIComponent(escape(atob(encryptedData)));
            return JSON.parse(decoded);
        } catch (e) {
            console.error('Decryption failed:', e);
            return null;
        }
    }

    async function fetchExamPayload(studentId, nationalId) {
        const credentials = { student_id: studentId, national_id: nationalId };
        const encryptedData = encryptData(credentials);

        if (!encryptedData) {
            throw new Error('Failed to encrypt data');
        }

        const body = new FormData();
        body.append('encrypted_data', encryptedData);

        const response = await fetch('API/getStudentExams.php', { method: 'POST', body });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        let payload;
        try {
            payload = await response.json();
        } catch (e) {
            throw new Error('Invalid JSON response');
        }
        if (payload && typeof payload === 'object' && 'error' in payload) {
            const userError = new Error(payload.error || 'درخواست نامعتبر');
            userError.isUserError = true;
            throw userError;
        }
        if (!Array.isArray(payload)) throw new Error('Invalid response format');
        return payload;
    }

    async function updateServerClock() {
        if (!footerClock) return;
        try {
            const response = await fetch('API/serverTime.php', { cache: 'no-store' });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const payload = await response.json();
            if (!payload || !payload.date || !payload.time) {
                throw new Error('Invalid payload structure');
            }
            const formattedDate = toPersianDigits(payload.date);
            const formattedTime = toPersianDigits(payload.time);
            const formattedStamp = `${formattedDate} | ${formattedTime}`;
            footerClock.textContent = formattedStamp;
            if (footerSpacer) footerSpacer.textContent = formattedStamp;
        } catch (error) {
            console.warn('Clock update failed:', error);
        }
    }

    function startClockRefresh() {
        if (clockTimer) clearTimeout(clockTimer);

        const scheduleNextTick = () => {
            const now = new Date();
            const elapsed = (now.getSeconds() * 1000) + now.getMilliseconds();
            const remainder = elapsed % CLOCK_REFRESH_MS;
            const delay = remainder === 0 ? CLOCK_REFRESH_MS : CLOCK_REFRESH_MS - remainder;

            clockTimer = setTimeout(async () => {
                await updateServerClock();
                scheduleNextTick();
            }, delay);
        };

        scheduleNextTick();
    }

    // Session helpers
    function setCookie(name, value, days) {
        const d = new Date();
        d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
        const expires = 'expires=' + d.toUTCString();
        document.cookie = `${name}=${value};${expires};path=/`;
    }

    function getCookie(name) {
        const cname = name + '=';
        const decodedCookie = decodeURIComponent(document.cookie);
        const ca = decodedCookie.split(';');
        for (let c of ca) {
            while (c.charAt(0) === ' ') c = c.substring(1);
            if (c.indexOf(cname) === 0) return c.substring(cname.length, c.length);
        }
        return '';
    }

    function eraseCookie(name) {
        document.cookie = name + '=; Max-Age=-99999999; path=/';
    }


    function stopAutoRefresh() {
        if (refreshTimer) {
            clearTimeout(refreshTimer);
            refreshTimer = null;
        }
    }

    function startAutoRefresh(studentId, nationalId) {
        currentCredentials = { studentId, nationalId };
        stopAutoRefresh();
        const scheduleNextRefresh = () => {
            const now = new Date();
            const elapsed = (now.getSeconds() * 1000) + now.getMilliseconds();
            const remainder = elapsed % REFRESH_INTERVAL_MS;
            const delay = remainder === 0 ? REFRESH_INTERVAL_MS : REFRESH_INTERVAL_MS - remainder;

            refreshTimer = setTimeout(async () => {
                updateServerClock();
                try {
                    const payload = await fetchExamPayload(currentCredentials.studentId, currentCredentials.nationalId);
                    const snapshot = JSON.stringify(payload || []);
                    if (snapshot === lastSnapshot) {
                        const needsReorder = updateCountdowns();
                        if (needsReorder) {
                            const firstExam = payload[0] || {};
                            const refreshedName = `${firstExam.first_name || ''} ${firstExam.last_name || ''}`.trim();
                            lastFullName = refreshedName || lastFullName;
                            renderResults(payload, lastFullName);
                            ensureLogoutButton(lastFullName, currentCredentials.studentId);
                        }
                        return;
                    }
                    lastSnapshot = snapshot;
                    const first = payload[0] || {};
                    const fullName = `${first.first_name || ''} ${first.last_name || ''}`.trim();
                    lastFullName = fullName;
                    renderResults(payload, fullName);
                    ensureLogoutButton(fullName, currentCredentials.studentId);
                } catch (error) {
                    console.warn('Auto-refresh failed:', error);
                } finally {
                    scheduleNextRefresh();
                }
            }, delay);
        };

        scheduleNextRefresh();
    }

    function hideLogin() {
        const target = document.getElementById('loginRow') || loginSection;
        if (target) target.classList.add('d-none');
        document.body.classList.remove('login-active');
    }

    function showLogin() {
        const target = document.getElementById('loginRow') || loginSection;
        if (target) target.classList.remove('d-none');
        document.body.classList.add('login-active');
        document.documentElement.classList.remove('session-cookie-detected');
    }

    function ensureLogoutButton(fullName, studentId) {
        // Remove old button if exists
        const old = document.getElementById('logoutBtn');
        if (old) old.remove();

        document.documentElement.classList.add('session-cookie-detected');

        // Create logout button in top right corner
        const btn = document.createElement('button');
        btn.id = 'logoutBtn';
        btn.type = 'button';
        btn.className = 'btn btn-outline-danger';
        btn.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 1001;';
        const safeName = fullName ? String(fullName).trim() : '';
        // If studentId not provided, try to read it from cookie
        let sid = studentId ? String(studentId).trim() : '';
        if (!sid) {
            try {
                const raw = getCookie('userSession');
                if (raw) {
                    const data = JSON.parse(decodeURIComponent(raw));
                    sid = (data.student_id || '').toString().trim();
                }
            } catch (e) { /* ignore */ }
        }

        // Build label: خروج ( نام | شناسه ) with Persian digits and spaces
        let label = 'خروج';
        const parts = [];
        if (safeName) parts.push(safeName);
        if (sid) parts.push(toPersianDigits(sid));
        if (parts.length) {
            label = `خروج ( ${parts.join(' | ')} )`;
        }
        btn.textContent = label;

        document.body.appendChild(btn);

        btn.addEventListener('click', () => {
            stopAutoRefresh();
            currentCredentials = null;
            lastSnapshot = '';
            lastPayload = [];
            lastFullName = '';
            eraseCookie('userSession');
            document.documentElement.classList.remove('session-cookie-detected');
            clearResults();
            showLogin();
        });

        // add body class to create safe top padding
        document.body.classList.add('has-logout');
    }

    function clearResults() {
        if (examCards) examCards.innerHTML = '';
        const btn = document.getElementById('logoutBtn');
        if (btn) btn.remove();
        document.body.classList.remove('has-logout');
        lastPayload = [];
    }

    form.addEventListener('submit', async event => {
        event.preventDefault();

        const mode = studentTypeRadio.checked ? 'student' : 'staff';
        // Sanitize to digits-only for student mode
        const studentId = toEnglishDigits(studentIdInput.value).replace(/[^0-9]/g, '').trim();
        const nationalId = toEnglishDigits(nationalIdInput.value).replace(/[^0-9]/g, '').trim();

        if (mode === 'student') {
            if (!studentId || !nationalId) {
                showAlert('warning', 'خطا!', 'وارد کردن نام کاربری و رمز عبور الزامی است.');
                return;
            }
        } else {
            // Staff flow is disabled for now
            showAlert('info', 'به‌زودی', 'ورود همکاران به‌زودی فعال می‌شود.');
            return;
        }

        stopAutoRefresh();
        currentCredentials = null;
        lastSnapshot = '';
        lastPayload = [];
        lastFullName = '';

        toggleLoading(true);
        clearResults();
        updateServerClock();
        try {
            const payload = await fetchExamPayload(studentId, nationalId);

            if (payload.length === 0) {
                showAlert('info', 'توجه', 'هیچ امتحانی برای اطلاعات وارد شده یافت نشد.');
                return;
            }

            // Save session (30 days)
            const first = payload[0] || null;
            const fullName = first ? `${first.first_name || ''} ${first.last_name || ''}`.trim() : '';
            lastFullName = fullName;
            const session = {
                student_id: studentId,
                national_id: nationalId,
                first_name: first?.first_name || '',
                last_name: first?.last_name || ''
            };
            try {
                setCookie('userSession', encodeURIComponent(JSON.stringify(session)), 30);
            } catch (e) {
                console.warn('Failed to set cookie', e);
            }

            renderResults(payload, fullName);
            ensureLogoutButton(fullName, studentId);
            lastSnapshot = JSON.stringify(payload || []);
            startAutoRefresh(studentId, nationalId);
        } catch (error) {
            console.error('Fetch error:', error);
            if (error && error.isUserError) {
                showAlert('warning', 'ورود ناموفق', 'رمز عبور و شماره دانشجویی صحیح نیست یا اطلاعاتی برای این شماره وجود ندارد.');
            } else {
                showAlert('error', 'خطا در اتصال!', 'مشکلی در ارتباط با سرور رخ داده است. لطفاً بعداً تلاش کنید.');
            }
        } finally {
            toggleLoading(false);
        }
    });

    // remove obsolete closeResults handler (results panel removed)

    // Auto login via cookie
    (async function autoLoginFromCookie() {
        const raw = getCookie('userSession');
        if (!raw) {
            stopAutoRefresh();
            currentCredentials = null;
            lastSnapshot = '';
            lastPayload = [];
            showLogin();
            return;
        }
        try {
            const data = JSON.parse(decodeURIComponent(raw));
            const sid = (data.student_id || '').toString().trim();
            const nid = (data.national_id || '').toString().trim();
            if (!sid || !nid) return;

            hideLogin();
            toggleLoading(true);
            clearResults();
            updateServerClock();

            // Encrypt credentials for auto-login
            const credentials = { student_id: sid, national_id: nid };
            const encryptedData = encryptData(credentials);

            if (!encryptedData) {
                throw new Error('Failed to encrypt data');
            }

            const body = new FormData();
            body.append('encrypted_data', encryptedData);
            const response = await fetch('API/getStudentExams.php', { method: 'POST', body });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const payload = await response.json();
            if (payload.error) {
                const userError = new Error(payload.error);
                userError.isUserError = true;
                throw userError;
            }
            if (!Array.isArray(payload) || payload.length === 0) throw new Error('هیچ امتحانی یافت نشد');

            const first = payload[0];
            const fullName = `${first.first_name || ''} ${first.last_name || ''}`.trim();
            lastFullName = fullName;
            renderResults(payload, fullName);
            ensureLogoutButton(fullName, sid);
            lastSnapshot = JSON.stringify(payload || []);
            startAutoRefresh(sid, nid);
        } catch (e) {
            console.warn('Auto-login failed:', e);
            stopAutoRefresh();
            currentCredentials = null;
            lastSnapshot = '';
            lastPayload = [];
            lastFullName = '';
            eraseCookie('userSession');
            if (!e?.isUserError) {
                showAlert('error', 'خطا در اتصال!', 'مشکلی در ارتباط با سرور رخ داده است. لطفاً بعداً تلاش کنید.');
            }
            showLogin();
        } finally {
            toggleLoading(false);
        }
    })();

    function toggleLoading(isLoading) {
        const spinner = searchBtn.querySelector('.spinner-border');
        const text = searchBtn.querySelector('.text');

        if (isLoading) {
            spinner.classList.remove('d-none');
            text.textContent = 'در حال جستجو...';
            searchBtn.disabled = true;
        } else {
            spinner.classList.add('d-none');
            text.textContent = 'جستجو';
            searchBtn.disabled = false;
        }
    }

    function renderResults(exams, fullName) {
        // Skip user info banner (removed per request)
        const previousScroll = window.scrollY || 0;
        const now = Date.now();
        const decorated = exams.map((exam, idx) => {
            const target = createExamDateTime(exam.exam_date, exam.exam_time);
            const timeValue = target ? target.getTime() : 0;
            const isUpcoming = target ? timeValue > now : false;
            return { exam, idx, target, timeValue, isUpcoming };
        });

        const upcoming = decorated
            .filter(item => item.isUpcoming)
            .sort((a, b) => (a.timeValue || Infinity) - (b.timeValue || Infinity));

        const past = decorated
            .filter(item => !item.isUpcoming)
            .sort((a, b) => (b.timeValue || -Infinity) - (a.timeValue || -Infinity));

        const htmlParts = [];

        if (upcoming.length) {
            const upcomingMarkup = upcoming.map(({ exam, idx }) => {
                const seatNum = exam.seat_number || '';
                const isNumericSeat = /^\d+$/.test(seatNum.toString().trim());
                const seatClass = isNumericSeat ? 'seat-available' : 'seat-hidden';
                const countdownText = getCountdownText(exam.exam_date, exam.exam_time);
                const countdownMarkup = countdownText ? `<div class="exam-countdown">${countdownText}</div>` : '';
                return `
                <div class="exam-card ${seatClass} upcoming" tabindex="0" data-exam-origin="${idx}" data-exam-status="upcoming">
                    <div class="exam-title">
                        <span>${escapeHtml(exam.course_name)}</span>
                    </div>
                    <div class="exam-meta">${toPersianDigits(exam.exam_date)} | ${toPersianDigits(exam.exam_time)}</div>
                    ${countdownMarkup}
                </div>
            `;
            }).join('');
            htmlParts.push(upcomingMarkup);
        }

        if (upcoming.length && past.length) {
            htmlParts.push('<div class="exam-divider" role="presentation"></div>');
        }

        if (past.length) {
            const pastMarkup = past.map(({ exam, idx }) => {
                const seatNum = exam.seat_number || '';
                const isNumericSeat = /^\d+$/.test(seatNum.toString().trim());
                const seatClass = isNumericSeat ? 'seat-available' : 'seat-hidden';
                return `
                <div class="exam-card ${seatClass} past" tabindex="0" data-exam-origin="${idx}" data-exam-status="past">
                    <div class="exam-title">
                        <span>${escapeHtml(exam.course_name)}</span>
                    </div>
                </div>
            `;
            }).join('');
            htmlParts.push(pastMarkup);
        }

        examCards.innerHTML = htmlParts.join('');
        lastPayload = exams.slice();
        lastFullName = fullName || lastFullName;

        const maxScroll = Math.max(0, document.body.scrollHeight - window.innerHeight);
        const targetScroll = Math.min(previousScroll, maxScroll);
        if (Math.abs(window.scrollY - targetScroll) > 1) {
            window.scrollTo(0, targetScroll);
        }

        const attachModal = (exam, status) => {
            return () => {
                if (status === 'past') {
                    const seatValue = (exam.seat_number ?? '').toString().trim();
                    const typeParts = [];
                    if (exam.exam_type) typeParts.push(escapeHtml(exam.exam_type));
                    if (exam.course_type) typeParts.push(escapeHtml(exam.course_type));
                    const typeSentence = typeParts.length ? ` به صورت ${typeParts.join(' و ')}` : '';
                    let message = `آزمون درس ${escapeHtml(exam.course_name)} در تاریخ ${toPersianDigits(exam.exam_date)} ساعت ${toPersianDigits(exam.exam_time)}${typeSentence} برگزار گردیده`;
                    if (seatValue) {
                        message += ` و شماره صندلی شما در این آزمون ${toPersianDigits(seatValue)} بوده است.`;
                    } else {
                        message += ' است.';
                    }
                    let countdownInterval;
                    Swal.fire({
                        title: toPersianDigits(exam.course_code),
                        html: `
                            <div style="text-align:justify;direction:rtl;line-height:1.9;font-size:1.05em;">
                                ${message}
                            </div>
                            <div class="swal2-countdown">
                                <span class="swal2-countdown-value">${toPersianDigits(15)}</span>
                            </div>
                        `,
                        timer: 15000,
                        showConfirmButton: false,
                        allowOutsideClick: true,
                        allowEscapeKey: true,
                        allowEnterKey: false,
                        buttonsStyling: false,
                        customClass: {
                            popup: 'swal2-rtl swal2-glass'
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
                    return;
                }

                const parts = exam.exam_date.split('/');
                const formattedDate = `${toPersianDigits(parts[2])} ${getPersianMonthName(exam.exam_date)} ${toPersianDigits(parts[0])}`;
                Swal.fire({
                    title: `${toPersianDigits(exam.course_code)}`,
                    html: `
                        <div lang="fa" style="text-align:justify;direction:rtl;line-height:1.9;font-size:1.05em; hyphens: auto; -webkit-hyphens: auto; -moz-hyphens: auto;">
                            آزمون ${escapeHtml(exam.course_type)} ${escapeHtml(exam.course_name)} راس ساعت ${toPersianDigits(exam.exam_time)} روز ${exam.exam_day} ${formattedDate} به شیوه ${escapeHtml(exam.exam_type)} برگزار خواهد شد. ${/^\d+$/.test(exam.seat_number) ? `شماره صندلی شما ${toPersianDigits(exam.seat_number)} می‌باشد.` : exam.seat_number}${/^\d+$/.test(exam.seat_number) ? `<br><br>ساختمان: <span style="color: #007bff;">${escapeHtml(exam.building) || '-'}</span><br>کلاس: <span style="color: #007bff;">${escapeHtml(exam.class_name) || '-'}</span><br>ردیف: <span style="color: #007bff;">${toPersianDigits(exam.seat_row) || '-'}</span>` : ''}
                        </div>
                    `,
                    confirmButtonText: 'بستن',
                    buttonsStyling: false,
                    customClass: {
                        popup: 'swal2-rtl swal2-glass',
                        confirmButton: 'btn btn-primary btn-lg px-4'
                    }
                });
            };
        };

        const cards = examCards.querySelectorAll('.exam-card');
        cards.forEach(card => {
            const origin = parseInt(card.getAttribute('data-exam-origin'), 10);
            if (Number.isNaN(origin) || !lastPayload[origin]) return;
            const status = card.getAttribute('data-exam-status');
            card.addEventListener('click', attachModal(lastPayload[origin], status));
        });

        hideLogin();
    }

    function updateCountdowns(payload = lastPayload) {
        if (!Array.isArray(payload) || payload.length === 0) return false;
        let needsReorder = false;
        const now = Date.now();
        payload.forEach((exam, idx) => {
            const card = examCards?.querySelector(`.exam-card[data-exam-origin='${idx}']`);
            if (!card) return;
            const status = card.getAttribute('data-exam-status');
            const target = createExamDateTime(exam.exam_date, exam.exam_time);
            if (!target || target.getTime() <= now) {
                if (status === 'upcoming') {
                    needsReorder = true;
                }
                const countdownExisting = card.querySelector('.exam-countdown');
                if (countdownExisting) countdownExisting.remove();
                return;
            }

            const text = getCountdownText(exam.exam_date, exam.exam_time);
            let countdown = card.querySelector('.exam-countdown');
            if (!text) {
                if (countdown) countdown.remove();
                return;
            }
            if (countdown) {
                countdown.textContent = text;
                return;
            }
            const meta = card.querySelector('.exam-meta');
            countdown = document.createElement('div');
            countdown.className = 'exam-countdown';
            countdown.textContent = text;
            if (meta && meta.parentNode) {
                meta.parentNode.insertBefore(countdown, meta.nextSibling);
            } else {
                card.appendChild(countdown);
            }
        });
        return needsReorder;
    }

    function showAlert(icon, title, text) {
        Swal.fire({
            icon,
            title,
            text,
            confirmButtonText: 'باشه',
            buttonsStyling: false,
            customClass: {
                popup: 'swal2-rtl swal2-glass',
                confirmButton: 'btn btn-primary btn-lg px-4'
            }
        });
    }

    function toEnglishDigits(value) {
        if (!value) return '';
        const persianMap = { '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9' };
        const arabicMap = { '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9' };
        return value
            .split('')
            .map(ch => (persianMap[ch] ?? arabicMap[ch] ?? ch))
            .join('');
    }

    function toPersianDigits(value) {
        if (value === null || value === undefined) return '';
        const digits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        return value
            .toString()
            .replace(/\d/g, d => digits[Number(d)]);
    }

    function getCountdownText(examDate, examTime) {
        const target = createExamDateTime(examDate, examTime);
        if (!target) return '';
        const diff = target.getTime() - Date.now();
        if (diff <= 0) return '';

        const totalMinutes = Math.floor(diff / 60000);
        const days = Math.floor(totalMinutes / (60 * 24));
        let remainingMinutes = totalMinutes - (days * 60 * 24);
        const hours = Math.floor(remainingMinutes / 60);
        remainingMinutes -= hours * 60;
        const minutes = remainingMinutes;

        const parts = [];
        if (days > 0) parts.push(`${toPersianDigits(days)} روز`);
        if (hours > 0) parts.push(`${toPersianDigits(hours)} ساعت`);
        if (minutes > 0 && days < 3) parts.push(`${toPersianDigits(minutes)} دقیقه`);
        if (!parts.length) parts.push('کمتر از یک دقیقه');

        return `${parts.join(' و ')} مانده تا زمان آزمون`;
    }

    function createExamDateTime(examDateStr, examTimeStr) {
        if (!examDateStr) return null;
        const normalizedDate = toEnglishDigits(String(examDateStr).trim()).replace(/-/g, '/');
        const segments = normalizedDate.split('/').map(part => parseInt(part, 10));
        if (segments.length !== 3 || segments.some(Number.isNaN)) return null;
        let [year, month, day] = segments;

        if (year < 1700) {
            const gregorian = jalaliToGregorian(year, month, day);
            if (!gregorian) return null;
            [year, month, day] = gregorian;
        }

        const timeString = examTimeStr ? toEnglishDigits(String(examTimeStr).trim()) : '00:00';
        const timeParts = timeString.split(':').map(part => parseInt(part, 10));
        const hour = timeParts[0] ?? 0;
        const minute = timeParts[1] ?? 0;
        if (Number.isNaN(hour) || Number.isNaN(minute)) return null;

        return new Date(year, month - 1, day, hour, minute, 0);
    }

    function jalaliToGregorian(jy, jm, jd) {
        jy = parseInt(jy, 10);
        jm = parseInt(jm, 10);
        jd = parseInt(jd, 10);
        if ([jy, jm, jd].some(Number.isNaN)) return null;

        jy += 1595;
        let days = -355668 + (365 * jy) + Math.floor(jy / 33) * 8 + Math.floor(((jy % 33) + 3) / 4) + jd + (jm < 7 ? (jm - 1) * 31 : ((jm - 7) * 30) + 186);

        let gy = 400 * Math.floor(days / 146097);
        days %= 146097;

        if (days > 36524) {
            gy += 100 * Math.floor(--days / 36524);
            days %= 36524;
            if (days >= 365) days++;
        }

        gy += 4 * Math.floor(days / 1461);
        days %= 1461;

        if (days > 365) {
            gy += Math.floor((days - 1) / 365);
            days = (days - 1) % 365;
        }

        const gd = days + 1;
        const monthDays = [0, 31, ((gy % 4 === 0 && gy % 100 !== 0) || (gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        let gm = 1;
        let remaining = gd;
        while (gm <= 12 && remaining > monthDays[gm]) {
            remaining -= monthDays[gm];
            gm += 1;
        }

        return [gy, gm, remaining];
    }

    function escapeHtml(value) {
        if (!value) return '';
        return value
            .toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
});

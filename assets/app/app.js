document.addEventListener('DOMContentLoaded', () => {
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
    const REFRESH_INTERVAL_MS = 60000;

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

    // Copyright footer click event
    const copyrightFooter = document.getElementById('copyrightFooter');
    if (copyrightFooter) {
        copyrightFooter.addEventListener('click', () => {
            Swal.fire({
                title: 'مرکز سنجش و آزمون دانشگاه پیام نور',
                text: 'مهدی حسنی',
                timer: 30000,
                timerProgressBar: true,
                showConfirmButton: false,
                confirmButtonText: 'بستن',
                allowOutsideClick: true,
                allowEscapeKey: true,
                customClass: {
                    popup: 'swal2-rtl',
                    confirmButton: 'btn btn-primary btn-lg px-4'
                },
                didOpen: () => {
                    const timerProgressBar = Swal.getTimerProgressBar();
                    if (timerProgressBar) {
                        timerProgressBar.style.background = 'linear-gradient(to right, #2196F3, #1976d2)';
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
        e.preventDefault();
        deferredPrompt = e;
        if (!isStandalone) {
            // Offer install via a subtle toast/button
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
            customClass: { confirmButton: 'btn btn-primary btn-lg px-4' }
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
                customClass: { confirmButton: 'btn btn-primary mx-2', cancelButton: 'btn btn-light mx-2' }
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
                customClass: { confirmButton: 'btn btn-primary btn-lg px-4' }
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
        const payload = await response.json();
        if (payload.error) throw new Error(payload.error);
        if (!Array.isArray(payload)) throw new Error('Invalid response format');
        return payload;
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

    let refreshTimer = null;
    let currentCredentials = null;
    let lastSnapshot = '';

    function stopAutoRefresh() {
        if (refreshTimer) {
            clearInterval(refreshTimer);
            refreshTimer = null;
        }
    }

    function startAutoRefresh(studentId, nationalId) {
        currentCredentials = { studentId, nationalId };
        stopAutoRefresh();
        refreshTimer = setInterval(async () => {
            try {
                const payload = await fetchExamPayload(currentCredentials.studentId, currentCredentials.nationalId);
                const snapshot = JSON.stringify(payload || []);
                if (snapshot === lastSnapshot) return;
                lastSnapshot = snapshot;
                const first = payload[0] || {};
                const fullName = `${first.first_name || ''} ${first.last_name || ''}`.trim();
                renderResults(payload, fullName);
                ensureLogoutButton(fullName, currentCredentials.studentId);
            } catch (error) {
                console.warn('Auto-refresh failed:', error);
            }
        }, REFRESH_INTERVAL_MS);
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
    }

    function ensureLogoutButton(fullName, studentId) {
        // Remove old button if exists
        const old = document.getElementById('logoutBtn');
        if (old) old.remove();

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
            eraseCookie('userSession');
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
    }

    form.addEventListener('submit', async event => {
        event.preventDefault();

        const mode = studentTypeRadio.checked ? 'student' : 'staff';
        // Sanitize to digits-only for student mode
        const studentId = toEnglishDigits(studentIdInput.value).replace(/[^0-9]/g, '').trim();
        const nationalId = toEnglishDigits(nationalIdInput.value).replace(/[^0-9]/g, '').trim();

        if (mode === 'student') {
            if (!studentId || !nationalId) {
                showAlert('warning', 'خطا!', 'لطفاً شماره دانشجویی و کد ملی را وارد کنید.');
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

        toggleLoading(true);
        clearResults(); try {
            const payload = await fetchExamPayload(studentId, nationalId);

            if (payload.length === 0) {
                showAlert('info', 'توجه', 'هیچ امتحانی برای اطلاعات وارد شده یافت نشد.');
                return;
            }

            // Save session (30 days)
            const first = payload[0] || null;
            const fullName = first ? `${first.first_name || ''} ${first.last_name || ''}`.trim() : '';
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
            showAlert('error', 'خطا در اتصال!', 'مشکلی در ارتباط با سرور رخ داده است. لطفاً بعداً تلاش کنید.');
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
            if (payload.error) throw new Error(payload.error);
            if (!Array.isArray(payload) || payload.length === 0) throw new Error('هیچ امتحانی یافت نشد');

            const first = payload[0];
            const fullName = `${first.first_name || ''} ${first.last_name || ''}`.trim();
            renderResults(payload, fullName);
            ensureLogoutButton(fullName, sid);
            lastSnapshot = JSON.stringify(payload || []);
            startAutoRefresh(sid, nid);
        } catch (e) {
            console.warn('Auto-login failed:', e);
            stopAutoRefresh();
            currentCredentials = null;
            lastSnapshot = '';
            eraseCookie('userSession');
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

        // Beautiful cards grid
        const html = exams.map((exam, idx) => {
            // Check if seat number is numeric (green) or text (red)
            const seatNum = exam.seat_number || '';
            const isNumericSeat = /^\d+$/.test(seatNum.toString().trim());
            const cardClass = isNumericSeat ? 'exam-card seat-available' : 'exam-card seat-hidden';
            const countdownText = getCountdownText(exam.exam_date, exam.exam_time);
            const countdownMarkup = countdownText ? `<div class="exam-countdown">${countdownText}</div>` : '';

            return `
            <div class="${cardClass}" tabindex="0" data-exam-idx="${idx}">
                <div class="exam-title">
                    <span class="exam-name">${escapeHtml(exam.course_name)}</span>
                </div>
                <div class="exam-meta">${toPersianDigits(exam.exam_date)} | ${toPersianDigits(exam.exam_time)}</div>
                ${countdownMarkup}
                <div class="exam-detail seat-info"><span class="exam-label">شماره صندلی:</span><span class="exam-value">${toPersianDigits(exam.seat_number)}</span></div>
            </div>
        `;
        }).join('');

        examCards.innerHTML = html;

        // Add click event for SweetAlert details
        exams.forEach((exam, idx) => {
            const card = examCards.querySelector(`.exam-card[data-exam-idx='${idx}']`);
            if (card) {
                card.addEventListener('click', () => {
                    Swal.fire({
                        title: `${escapeHtml(exam.course_name)} (${toPersianDigits(exam.course_code)})`,
                        html: `
                            <div style='text-align:right;font-size:1.1em;'>
                                <b>نوع درس:</b> ${escapeHtml(exam.course_type)}<br>
                                <b>نوع امتحان:</b> ${escapeHtml(exam.exam_type)}<br>
                                <b>تاریخ:</b> ${toPersianDigits(exam.exam_date)}<br>
                                <b>ساعت:</b> ${toPersianDigits(exam.exam_time)}<br>
                                <b>شماره صندلی:</b> ${toPersianDigits(exam.seat_number)}<br>
                                <b>ساختمان:</b> ${escapeHtml(exam.building) || '-'}<br>
                                <b>کلاس:</b> ${escapeHtml(exam.class_name) || '-'}<br>
                                <b>ردیف:</b> ${toPersianDigits(exam.seat_row) || '-'}<br>
                            </div>
                        `,
                        confirmButtonText: 'بستن',
                        buttonsStyling: false,
                        customClass: {
                            popup: 'swal2-rtl',
                            confirmButton: 'btn btn-primary btn-lg px-4'
                        }
                    });
                });
            }
        });

        hideLogin();
    }

    function hideResults() {
        // obsolete - results now render directly on page
        clearResults();
    }

    function showAlert(icon, title, text) {
        Swal.fire({
            icon,
            title,
            text,
            confirmButtonText: 'باشه',
            buttonsStyling: false,
            customClass: {
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

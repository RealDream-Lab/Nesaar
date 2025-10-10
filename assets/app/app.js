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

    function hideLogin() {
        const target = document.getElementById('loginRow') || loginSection;
        if (target) target.classList.add('d-none');
    }

    function showLogin() {
        const target = document.getElementById('loginRow') || loginSection;
        if (target) target.classList.remove('d-none');
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

        toggleLoading(true);
        clearResults(); try {
            // Encrypt sensitive data before sending
            const credentials = { student_id: studentId, national_id: nationalId };
            const encryptedData = encryptData(credentials);

            if (!encryptedData) {
                throw new Error('Failed to encrypt data');
            }

            const body = new FormData();
            body.append('encrypted_data', encryptedData);

            const response = await fetch('API/getStudentExams.php', { method: 'POST', body });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const payload = await response.json();

            if (payload.error) {
                showAlert('error', 'خطا!', payload.error);
                return;
            }

            if (!Array.isArray(payload) || payload.length === 0) {
                showAlert('info', 'توجه', 'هیچ امتحانی برای اطلاعات وارد شده یافت نشد.');
                return;
            }

            // Save session (30 days)
            const first = Array.isArray(payload) && payload[0] ? payload[0] : null;
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
        if (!raw) { showLogin(); return; }
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
        } catch (e) {
            console.warn('Auto-login failed:', e);
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

            return `
            <div class="${cardClass}" tabindex="0" data-exam-idx="${idx}">
                <div class="exam-title">
                    <span class="exam-name">${escapeHtml(exam.course_name)}</span>
                </div>
                <div class="exam-meta">${toPersianDigits(exam.exam_date)} | ${toPersianDigits(exam.exam_time)}</div>
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

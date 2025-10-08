document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('examForm');
  const searchBtn = document.getElementById('searchBtn');
  const results = document.getElementById('results');
  const resultContent = document.getElementById('resultContent');
  const closeResults = document.getElementById('closeResults');

  form.addEventListener('submit', async event => {
    event.preventDefault();

    const studentId = toEnglishDigits(document.getElementById('studentId').value.trim());
    const nationalId = toEnglishDigits(document.getElementById('nationalId').value.trim());

    if (!studentId || !nationalId) {
      showAlert('warning', 'خطا!', 'لطفاً شما دانشجویی و کد ملی را وارد کنید.');
      return;
    }

    toggleLoading(true);
    hideResults();

    try {
      const body = new FormData();
      body.append('student_id', studentId);
      body.append('national_id', nationalId);

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

      renderResults(payload);
    } catch (error) {
      console.error('Fetch error:', error);
      showAlert('error', 'خطا در اتصال!', 'مشکلی در ارتباط با سرور رخ  است. لطفاً بعداً تلاش کنید.');
    } finally {
      toggleLoading(false);
    }
  });

  closeResults.addEventListener('click', hideResults);

  function toggleLoading(isLoading) {
    const spinner = searchBtn.querySelector('.spinner-border');
    const text = searchBtn.querySelector('.text');

    if (isLoading) {
      spinner.classList.remove('d-none');
      text....';
      searchBtn.disabled = true;
    } else {
      spinner.classList.add('d-none');
      text.textContent = 'جستجو';
      searchBtn.disabled = false;
    }
  }

  function renderResults(exams) {
    resultContent.innerHTML = exams
      .map(exam => `
        <div class="exam-item">
          <div class="row">
            <div class="col-md-6">
              <h6>اطلاعات دانشجو</h6>
              <p><strong>نام:</strong> ${escapeHtml(exam.first_name)} ${escapeHtml(exam.last_name)}</p>
              <p><strong>شماره دانشجویی:</strong> ${toPersianDigits(exam.student_id)}</p>
              <p><strong>مقطع:</strong> ${escapeHtml(exam.degree)}</p>
            </div>
            <div class="col-md-6">
              <h6>اطلاعات امتحان</h6>
              <p><strong>درس:</strong> ${escapeHtml(exam.course_name)}</p>
              <p><strong>کد درس:</strong> ${toPersianDigits(exam.course_code)}</p>
              <p><strong>تاریخ:</strong> ${toPersianDigits(exam.exam_date)}</p>
              <p><strong>ساعت:</strong> ${toPersianDigits(exam.exam_time)}</p>
            </div>
          </div>
          <hr />
          <div class="row mt-3">
            <div class="col-md-6">
              <h6>محل امتحان</h6>
              <p><strong>شماره صندلی:</strong> ${toPersianDigits(exam.seat_number)}</p>
              <p><strong>ساختمان:</strong> ${escapeHtml(exam.building) || '-'}</p>
              <p><strong>کلاس:</strong> ${escapeHtml(exam.class_name) || '-'}</p>
              <p><strong>ردیف:</strong> ${toPersianDigits(exam.seat_row) || '-'}</p>
            </div>
            <div class="col-md-6">
              <h6>سایر اطلاعات</h6>
              <p><strong>نوع امتحان:</strong> ${escapeHtml(exam.exam_type)}</p>
              <p><strong>نوع درس:</strong> ${escapeHtml(exam.course_type)}</p>
            </div>
          </div>
        </div>
      `)
      .join('');

    results.classList.remove('d-none');
  }

  function hideResults() {
    results.classList.add('d-none');
    resultContent.innerHTML = '';
  }

  function showAlert(icon, title, text) {
    Swal.fire({ icon, title, text, confirmButtonText: 'باشه' });
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

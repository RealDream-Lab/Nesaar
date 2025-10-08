// اپلیکیشن مشاهده شماره صندلی امتحان// اپلیکیشن مشاهده شماره صندلی امتحان

document.addEventListener('DOMContentLoaded', function() {document.addEventListener('DOMContentLoaded', function() {

    const examForm = document.getElementById('examForm');    const examForm = document.getElementById('examForm');

    const searchBtn = document.getElementById('searchBtn');    const searchBtn = document.getElementById('searchBtn');

    const results = document.getElementById('results');    const results = document.getElementById('results');

    const resultContent = document.getElementById('resultContent');    const resultContent = document.getElementById('resultContent');



    // ارسال فرم    // ارسال فرم

    examForm.addEventListener('submit', async function(e) {    examForm.addEventListener('submit', async function(e) {

        e.preventDefault();        e.preventDefault();

                

        const studentId = document.getElementById('studentId').value.trim();        const studentId = document.getElementById('studentId').value.trim();

        const nationalId = document.getElementById('nationalId').value.trim();        const nationalId = document.getElementById('nationalId').value.trim();

                

        if (!studentId || !nationalId) {        if (!studentId || !nationalId) {

            Swal.fire({            Swal.fire({

                icon: 'warning',                icon: 'warning',

                title: 'خطا!',                title: 'خطا!',

                text: 'لطفاً تمام فیلدها را پر کنید',                text: 'لطفاً تمام فیلدها را پر کنید',

                confirmButtonText: 'باشه'                confirmButtonText: 'باشه'

            });            });

            return;            return;

        }        }



        // نمایش لودینگ        // نمایش لودینگ

        showLoading(true);        showLoading(true);

        hideResults();        hideResults();



        try {        try {

            // ارسال درخواست به API            // ارسال درخواست به API

            const formData = new FormData();            const formData = new FormData();

            formData.append('student_id', convertToEnglishNumbers(studentId));            formData.append('student_id', convertToEnglishNumbers(studentId));

            formData.append('national_id', convertToEnglishNumbers(nationalId));            formData.append('national_id', convertToEnglishNumbers(nationalId));



            const response = await fetch('API/getStudentExams.php', {            const response = await fetch('API/getStudentExams.php', {

                method: 'POST',                method: 'POST',

                body: formData                body: formData

            });            });



            const data = await response.json();            const data = await response.json();



            if (data.error) {            if (data.error) {

                Swal.fire({                Swal.fire({

                    icon: 'error',                    icon: 'error',

                    title: 'خطا!',                    title: 'خطا!',

                    text: data.error,                    text: data.error,

                    confirmButtonText: 'باشه'                    confirmButtonText: 'باشه'

                });                });

            } else {            } else {

                displayResults(data);                displayResults(data);

            }            }



        } catch (error) {        } catch (error) {

            console.error('Error:', error);            console.error('Error:', error);

            Swal.fire({            Swal.fire({

                icon: 'error',                icon: 'error',

                title: 'خطا در اتصال!',                title: 'خطا در اتصال!',

                text: 'مشکلی در اتصال به سرور رخ داده است',                text: 'مشکلی در اتصال به سرور رخ داده است',

                confirmButtonText: 'باشه'                confirmButtonText: 'باشه'

            });            });

        } finally {        } finally {

            showLoading(false);            showLoading(false);

        }        }

    });    });



    // نمایش/مخفی کردن لودینگ    // نمایش/مخفی کردن لودینگ

    function showLoading(show) {    function showLoading(show) {

        const spinner = searchBtn.querySelector('.spinner-border');        const spinner = searchBtn.querySelector('.spinner-border');

        const text = searchBtn.querySelector('.text');        const text = searchBtn.querySelector('.text');

                

        if (show) {        if (show) {

            spinner.classList.remove('d-none');            spinner.classList.remove('d-none');

            text.textContent = 'در حال جستجو...';            text.textContent = 'در حال جستجو...';

            searchBtn.disabled = true;            searchBtn.disabled = true;

        } else {        } else {

            spinner.classList.add('d-none');            spinner.classList.add('d-none');

            text.textContent = 'جستجو';            text.textContent = 'جستجو';

            searchBtn.disabled = false;            searchBtn.disabled = false;

        }        }

    }    }



    // مخفی کردن نتایج    // مخفی کردن نتایج

    function hideResults() {    function hideResults() {

        results.classList.add('d-none');        results.classList.add('d-none');

    }    }



    // نمایش نتایج    // نمایش نتایج

    function displayResults(data) {    function displayResults(data) {

        let html = '';        let html = '';

                

        if (Array.isArray(data) && data.length > 0) {        if (Array.isArray(data) && data.length > 0) {

            data.forEach(exam => {            data.forEach(exam => {

                html += `                html += `

                    <div class="mb-4 p-3 border rounded">                    <div class="mb-4 p-3 border rounded">

                        <div class="row">                        <div class="row">

                            <div class="col-md-6">                            <div class="col-md-6">

                                <h6 class="text-primary">اطلاعات دانشجو</h6>                                <h6 class="text-primary">اطلاعات دانشجو</h6>

                                <p><strong>نام:</strong> ${exam.first_name} ${exam.last_name}</p>                                <p><strong>نام:</strong> ${exam.first_name} ${exam.last_name}</p>

                                <p><strong>شماره دانشجویی:</strong> ${convertToPersianNumbers(exam.student_id)}</p>                                <p><strong>شماره دانشجویی:</strong> ${convertToPersianNumbers(exam.student_id)}</p>

                                <p><strong>مقطع:</strong> ${exam.degree}</p>                                <p><strong>مقطع:</strong> ${exam.degree}</p>

                            </div>                            </div>

                            <div class="col-md-6">                            <div class="col-md-6">

                                <h6 class="text-success">اطلاعات امتحان</h6>                                <h6 class="text-success">اطلاعات امتحان</h6>

                                <p><strong>درس:</strong> ${exam.course_name}</p>                                <p><strong>درس:</strong> ${exam.course_name}</p>

                                <p><strong>کد درس:</strong> ${exam.course_code}</p>                                <p><strong>کد درس:</strong> ${exam.course_code}</p>

                                <p><strong>تاریخ امتحان:</strong> ${exam.exam_date}</p>                                <p><strong>تاریخ امتحان:</strong> ${exam.exam_date}</p>

                                <p><strong>ساعت امتحان:</strong> ${convertToPersianNumbers(exam.exam_time)}</p>                                <p><strong>ساعت امتحان:</strong> ${convertToPersianNumbers(exam.exam_time)}</p>

                            </div>                            </div>

                        </div>                        </div>

                        <hr>                        <hr>

                        <div class="row">                        <div class="row">

                            <div class="col-md-6">                            <div class="col-md-6">

                                <h6 class="text-info">محل امتحان</h6>                                <h6 class="text-info">محل امتحان</h6>

                                <p><strong>شماره صندلی:</strong> ${exam.seat_number}</p>                                <p><strong>شماره صندلی:</strong> ${exam.seat_number}</p>

                                <p><strong>ساختمان:</strong> ${exam.building || '-'}</p>                                <p><strong>ساختمان:</strong> ${exam.building || '-'}</p>

                                <p><strong>کلاس:</strong> ${exam.class_name || '-'}</p>                                <p><strong>کلاس:</strong> ${exam.class_name || '-'}</p>

                                <p><strong>ردیف:</strong> ${exam.seat_row || '-'}</p>                                <p><strong>ردیف:</strong> ${exam.seat_row || '-'}</p>

                            </div>                            </div>

                            <div class="col-md-6">                            <div class="col-md-6">

                                <h6 class="text-warning">سایر اطلاعات</h6>                                <h6 class="text-warning">سایر اطلاعات</h6>

                                <p><strong>نوع امتحان:</strong> ${exam.exam_type}</p>                                <p><strong>نوع امتحان:</strong> ${exam.exam_type}</p>

                                <p><strong>نوع درس:</strong> ${exam.course_type}</p>                                <p><strong>نوع درس:</strong> ${exam.course_type}</p>

                                <p><strong>مرکز مبدا:</strong> ${exam.source_center}</p>                                <p><strong>مرکز مبدا:</strong> ${exam.source_center}</p>

                                <p><strong>مرکز مقصد:</strong> ${exam.destination_center}</p>                                <p><strong>مرکز مقصد:</strong> ${exam.destination_center}</p>

                            </div>                            </div>

                        </div>                        </div>

                    </div>                    </div>

                `;                `;

            });            });

        } else {        } else {

            html = '<p class="text-center text-muted">اطلاعاتی یافت نشد</p>';            html = '<p class="text-center text-muted">اطلاعاتی یافت نشد</p>';

        }        }

                

        resultContent.innerHTML = html;        resultContent.innerHTML = html;

        results.classList.remove('d-none');        results.classList.remove('d-none');

    }    }



    // تبدیل اعداد فارسی به انگلیسی    // تبدیل اعداد فارسی به انگلیسی

    function convertToEnglishNumbers(str) {    function convertToEnglishNumbers(str) {

        const persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];        const persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

        const englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];        const englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

                

        for (let i = 0; i < persianNumbers.length; i++) {        for (let i = 0; i < persianNumbers.length; i++) {

            str = str.replace(new RegExp(persianNumbers[i], 'g'), englishNumbers[i]);            str = str.replace(new RegExp(persianNumbers[i], 'g'), englishNumbers[i]);

        }        }

        return str;        return str;

    }    }



    // تبدیل اعداد انگلیسی به فارسی    // تبدیل اعداد انگلیسی به فارسی

    function convertToPersianNumbers(str) {    function convertToPersianNumbers(str) {

        if (!str) return '';        if (!str) return '';

        const englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];        const englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        const persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];        const persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

                

        for (let i = 0; i < englishNumbers.length; i++) {        for (let i = 0; i < englishNumbers.length; i++) {

            str = str.toString().replace(new RegExp(englishNumbers[i], 'g'), persianNumbers[i]);            str = str.toString().replace(new RegExp(englishNumbers[i], 'g'), persianNumbers[i]);

        }        }

        return str;        return str;

    }    }

});});
        // You can replace this with Persian date if needed
        dateElement.textContent = now.toLocaleDateString('fa-IR');
        timeElement.textContent = now.toLocaleTimeString('fa-IR', {
            hour: '2-digit',
            minute: '2-digit'
        });
    }
}

// Update time every minute
setInterval(updateDateTime, 60000);
updateDateTime();

// Form Handler
document.getElementById('exam-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const studentId = document.getElementById('student-id').value.trim();
    const nationalId = document.getElementById('national-id').value.trim();
    const searchBtn = document.getElementById('search-btn');
    const btnText = searchBtn.querySelector('.btn-text');
    const loading = searchBtn.querySelector('.loading');
    const resultsDiv = document.getElementById('results');
    const errorDiv = document.getElementById('error');
    
    // Validation
    if (!studentId || !nationalId) {
        showError('لطفاً تمام فیلدها را پر کنید');
        return;
    }
    
    if (nationalId.length !== 10) {
        showError('شماره ملی باید 10 رقم باشد');
        return;
    }
    
    // Show loading
    searchBtn.disabled = true;
    btnText.style.display = 'none';
    loading.style.display = 'inline';
    errorDiv.style.display = 'none';
    resultsDiv.style.display = 'none';
    
    try {
        const formData = new FormData();
        formData.append('student_id', studentId);
        formData.append('national_id', nationalId);
        
        const response = await fetch('/API/getStudentExams.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.error) {
            showError(data.error);
        } else if (data.length > 0) {
            showResults(data);
        } else {
            showError('اطلاعاتی یافت نشد');
        }
        
    } catch (error) {
        console.error('خطا:', error);
        showError('خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.');
    } finally {
        // Hide loading
        searchBtn.disabled = false;
        btnText.style.display = 'inline';
        loading.style.display = 'none';
    }
});

function showError(message) {
    const errorDiv = document.getElementById('error');
    const resultsDiv = document.getElementById('results');
    
    errorDiv.textContent = message;
    errorDiv.style.display = 'block';
    resultsDiv.style.display = 'none';
}

function showResults(data) {
    const resultsDiv = document.getElementById('results');
    const errorDiv = document.getElementById('error');
    
    let html = '<h2>📋 نتایج جستجو</h2>';
    
    data.forEach(exam => {
        html += `
            <div class="exam-card">
                <div class="exam-header">
                    <div class="course-name">${exam.course_name || 'نام درس موجود نیست'}</div>
                    <div class="course-code">کد درس: ${exam.course_code || 'نامشخص'}</div>
                </div>
                
                <div class="exam-details">
                    <div class="detail-item">
                        <span class="detail-label">📅 تاریخ:</span>
                        <span class="detail-value">${exam.exam_date || 'نامشخص'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">🕐 ساعت:</span>
                        <span class="detail-value">${exam.exam_time || 'نامشخص'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">📚 نوع:</span>
                        <span class="detail-value">${exam.exam_type || 'نامشخص'}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">🎓 مقطع:</span>
                        <span class="detail-value">${exam.degree || 'نامشخص'}</span>
                    </div>
                </div>
                
                <div class="seat-info ${exam.seat_number === 'مخفی تا نیم ساعت قبل آزمون' ? 'seat-hidden' : ''}">
                    ${exam.seat_number === 'مخفی تا نیم ساعت قبل آزمون' ? 
                        '🔒 ' + exam.seat_number :
                        `🪑 صندلی: ${exam.seat_number || 'نامشخص'} | 🏢 ساختمان: ${exam.building || 'نامشخص'} | 🚪 کلاس: ${exam.class_name || 'نامشخص'} | 📍 ردیف: ${exam.seat_row || 'نامشخص'}`
                    }
                </div>
            </div>
        `;
    });
    
    resultsDiv.innerHTML = html;
    resultsDiv.style.display = 'block';
    errorDiv.style.display = 'none';
}

// Add to Home Screen Prompt
let deferredPrompt;

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    
    // Show install button or banner
    console.log('PWA install prompt available');
});

// Clear form function
function clearForm() {
    document.getElementById('student-id').value = '';
    document.getElementById('national-id').value = '';
    document.getElementById('results').style.display = 'none';
    document.getElementById('error').style.display = 'none';
}
// Service Worker Registration
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
            .then((registration) => {
                console.log('SW registered: ', registration);
            })
            .catch((registrationError) => {
                console.log('SW registration failed: ', registrationError);
            });
    });
}

// Update Date and Time
function updateDateTime() {
    const now = new Date();
    const dateElement = document.getElementById('current-date');
    const timeElement = document.getElementById('current-time');
    
    if (dateElement && timeElement) {
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
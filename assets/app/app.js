document.addEventListener('DOMContentLoaded',function(){const form=document.getElementById('examForm');const btn=document.getElementById('searchBtn');const results=document.getElementById('results');const content=document.getElementById('resultContent');form.addEventListener('submit',async function(e){e.preventDefault();const sid=document.getElementById('studentId').value.trim();const nid=document.getElementById('nationalId').value.trim();if(!sid||!nid){Swal.fire({icon:'warning',title:'خطا!',text:'لطفاً تمام فیلدها را پر کنید',confirmButtonText:'باشه'});return}showLoading(true);hideResults();try{const fd=new FormData();fd.append('student_id',toEn(sid));fd.append('national_id',toEn(nid));const res=await fetch('API/getStudentExams.php',{method:'POST',body:fd});const data=await res.json();if(data.error){Swal.fire({icon:'error',title:'خطا!',text:data.error,confirmButtonText:'باشه'})}else{displayResults(data)}}catch(error){Swal.fire({icon:'error',title:'خطا در اتصال!',text:'مشکلی در اتصال به سرور رخ داده است',confirmButtonText:'باشه'})}finally{showLoading(false)}});function showLoading(show){const spinner=btn.querySelector('.spinner-border');const text=btn.querySelector('.text');if(show){spinner.classList.remove('d-none');text.textContent='در حال جستجو...';btn.disabled=true}else{spinner.classList.add('d-none');text.textContent='جستجو';btn.disabled=false}}function hideResults(){results.classList.add('d-none')}function displayResults(data){let html='';if(Array.isArray(data)&&data.length>0){data.forEach(exam=>{html+='<div class="exam-item">';html+='<div class="row"><div class="col-md-6">';html+='<h6>اطلاعات دانشجو</h6>';html+='<p><strong>نام:</strong> '+exam.first_name+' '+exam.last_name+'</p>';html+='<p><strong>شماره دانشجویی:</strong> '+toFa(exam.student_id)+'</p>';html+='<p><strong>مقطع:</strong> '+exam.degree+'</p>';html+='</div><div class="col-md-6">';html+='<h6>اطلاعات امتحان</h6>';html+='<p><strong>درس:</strong> '+exam.course_name+'</p>';html+='<p><strong>کد درس:</strong> '+exam.course_code+'</p>';html+='<p><strong>تاریخ:</strong> '+exam.exam_date+'</p>';html+='<p><strong>ساعت:</strong> '+toFa(exam.exam_time)+'</p>';html+='</div></div><hr><div class="row mt-3"><div class="col-md-6">';html+='<h6>محل امتحان</h6>';html+='<p><strong>شماره صندلی:</strong> '+exam.seat_number+'</p>';html+='<p><strong>ساختمان:</strong> '+(exam.building||'-')+'</p>';html+='<p><strong>کلاس:</strong> '+(exam.class_name||'-')+'</p>';html+='<p><strong>ردیف:</strong> '+(exam.seat_row||'-')+'</p>';html+='</div><div class="col-md-6">';html+='<h6>سایر اطلاعات</h6>';html+='<p><strong>نوع امتحان:</strong> '+exam.exam_type+'</p>';html+='<p><strong>نوع درس:</strong> '+exam.course_type+'</p>';html+='</div></div></div>'})}else{html='<p class="text-center text-muted">اطلاعاتی یافت نشد</p>'}content.innerHTML=html;results.classList.remove('d-none')}function toEn(str){const p=['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];const e=['0','1','2','3','4','5','6','7','8','9'];for(let i=0;i<10;i++){str=str.replace(new RegExp(p[i],'g'),e[i])}return str}function toFa(str){if(!str)return'';const e=['0','1','2','3','4','5','6','7','8','9'];const p=['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];str=str.toString();for(let i=0;i<10;i++){str=str.replace(new RegExp(e[i],'g'),p[i])}return str}});document.addEventListener('DOMContentLoaded', function() {

    const examForm = document.getElementById('examForm');
    const searchBtn = document.getElementById('searchBtn');
    const results = document.getElementById('results');
    const resultContent = document.getElementById('resultContent');

    examForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const studentId = document.getElementById('studentId').value.trim();
        const nationalId = document.getElementById('nationalId').value.trim();
        
        if (!studentId || !nationalId) {
            Swal.fire({
                icon: 'warning',
                title: 'خطا!',
                text: 'لطفاً تمام فیلدها را پر کنید',
                confirmButtonText: 'باشه'
            });
            return;
        }

        showLoading(true);
        hideResults();

        try {
            const formData = new FormData();
            formData.append('student_id', convertToEnglish(studentId));
            formData.append('national_id', convertToEnglish(nationalId));

            const response = await fetch('API/getStudentExams.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.error) {
                Swal.fire({
                    icon: 'error',
                    title: 'خط!',
                    text: data.error,
                    confirmButtonText: 'باشه'
                });
            } else {
                displayResults(data);
            }

        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'خطا در اتصال!',
                text: 'مشکلی در اتصال به سرور 'خطا در اتصال!', داده است',
                confirmButtonText: ''
            });
        } finally {
            showLoading(false);
        }
    });

    function showLoading(show) {
        const spinner = searchBtn.querySelector('.spinner-border');
        const text = searchBtn.querySelector('.text');
        
        if (show) {
            spinner.classList.remove('d-none');
            text.textContent = 'در حال جستجو...';
            searchBtn.disabled = true;
        } else {
            spinner.classList.add('d-none');
            text.textContent = 'جستجو';
            searchBtn.disabled = false;
        }
    }

    function hideResults() {
        results.classList.add('d-none');
    }

    function displayResults(data) {
        let html = '';
        
        if (Array.isArray(data) && data.length > 0) {
            data.forEach(exam => {
                html += '<div class="exam-item">';
                html += '<div class="row">';
                html += '<div class="col-md-6">';
                html += '<h6>اطلاعات دانشجو</h6>';
                html += '<p><strong>نام:</strong> ' + exam.first_name + ' ' + exam.last_name + '</p>';
                html += '<p><:</strong> ' + toPersian(exam.student_id) + '</p>';
                html += '<p><strong>مقطع:</strong> ' + exam.degree + '</p>';
                html += '</div>';
                html += '<div class="col-md-6">';
                html += '<h6> امتحان</h6>';
                html += '<p><strong>درس:</strong> ' + exam.course_name + '</p>';
                html += '<p><strong>کد درس:</strong> ' + exam.course_code + '</p>';
                html += '<p><strong>تاریخ:</strong> ' + exam.exam_date + '</p>';
                html += '<p><strong>ساعت:</strong> ' + toPersian(exam.exam_time) + '</p>';
                html += '</div>';
                html += '</div>';
                html += '<hr>';
                html += '<div class="row mt-3">';
                html += '<div class="col-md-6">';
                html += '<h6>محل امتحان</h6>';
                html += '<p><strong>شماره صن:</strong> ' + exam.seat_number + '</p>';
                html += '<p><strong>ساختمان:</strong> ' + (exam.building || '-') + '</p>';
                html += '<p><strong>کلاس:</strong> ' + (exam.class_name || '-') + '</p>';
                html += '<p><strong>ردیف:</strong> ' + (exam.seat_row || '-') + '</p>';
                html += '</div>';
                html += '<div class="col-md-6">';
                html += '<h6>سایر اطلاعات</h6>';
                html += '<p><strong>نوع امتحان:</strong> ' + exam.exam_type + '</p>';
                html += '<p><strong>نوع درس:</strong> ' + exam.course_type + '</p>';
                html += '</div>';
                html += '</div>';
                html += '</div>';
            });
        } else {
            html = '<p class="text-center text-muted">اطلاعاتی یافت نشد</p>';
        }
        
        resultContent.innerHTML = html;
        results.classList.remove('d-none');
    }

    function convertToEnglish(str) {
        const p = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        const e = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        for (let i = 0; i < 10; i++) {
            str = str.replace(new RegExp(p[i], 'g'), e[i]);
        }
        return str;
    }

    function toPersian(str) {
        if (!str) return '';
        const e = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        const p = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        str = str.toString();
        for (let i = 0; i < 10; i++) {
            str = str.replace(new RegExp(e[i], 'g'), p[i]);
        }
        return str;
    }
});

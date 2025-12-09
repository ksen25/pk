document.addEventListener('DOMContentLoaded', function () {
    // Элементы DOM для классов, форм обучения и контейнера чекбоксов
    const classRadios = document.querySelectorAll('input[name="foreign_class"]');
    const formRadios = document.querySelectorAll('input[name="foreign_forms"]');
    const checkboxContainer = document.getElementById('checkboxContainer');

    // Функция для загрузки профессий/специальностей
    function loadProfessions(classId, formId) {
        checkboxContainer.innerHTML = ''; // Очистить контейнер перед загрузкой

        // Отправка AJAX-запроса
        const xhr = new XMLHttpRequest();
        xhr.open('GET', `load/load_professions.php?class_id=${classId}&form_id=${formId}`, true);
        xhr.onload = function () {
            if (xhr.status === 200) {
                checkboxContainer.innerHTML = xhr.responseText; // Вставить загруженные данные
                addCheckboxListeners(); // Добавить обработчики для новых чекбоксов
            } else {
                alert('Ошибка загрузки профессий/специальностей');
            }
        };
        xhr.send();
    }

    // Функция для обработки изменений радиокнопок
    function updateProfessions() {
        const selectedClass = document.querySelector('input[name="foreign_class"]:checked');
        const selectedForm = document.querySelector('input[name="foreign_forms"]:checked');

        if (selectedClass && selectedForm) {
            const classId = selectedClass.value;
            const formId = selectedForm.value;
            loadProfessions(classId, formId); // Загрузка профессий с учетом выбранных значений
        }
    }

    // Привязка обработчиков к радиокнопкам класса и формы обучения
    classRadios.forEach(radio => {
        radio.addEventListener('change', updateProfessions);
    });

    formRadios.forEach(radio => {
        radio.addEventListener('change', updateProfessions);
    });

    // Функция для ограничения выбора до 5 чекбоксов
    function addCheckboxListeners() {
        const checkboxes = document.querySelectorAll('.profession-checkbox');
        const maxSelection = 5;

        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                const selectedCount = document.querySelectorAll('.profession-checkbox:checked').length;

                if (selectedCount > maxSelection) {
                    checkbox.checked = false; // Отменить выбор
                    alert(`Можно выбрать максимум ${maxSelection} профессий/специальностей.`);
                }
            });
        });
    }

    // Обработка изменений состояния чекбокса согласия
    const agreementCheckbox = document.getElementById('agreementCheckbox');
    const submitButton = document.querySelector('.btn-primary');

    // Изначально кнопка отправки неактивна
    submitButton.disabled = true;

    agreementCheckbox.addEventListener('change', () => {
        submitButton.disabled = !agreementCheckbox.checked;
    });

    // Отображение вступительного испытания для выбранной профессии
    const examField = document.getElementById('entranceExamField');
    const entranceExamInput = document.getElementById('entranceExam');

    checkboxContainer.addEventListener('change', () => {
        const selectedCheckbox = document.querySelector('.profession-checkbox:checked[data-exam-title]');
        if (selectedCheckbox) {
            const examTitle = selectedCheckbox.dataset.examTitle;
            if (examTitle) {
                examField.style.display = 'block';
                entranceExamInput.value = examTitle;
            } else {
                examField.style.display = 'none';
                entranceExamInput.value = '';
            }
        } else {
            examField.style.display = 'none';
            entranceExamInput.value = '';
        }
    });

    // Специальные условия (ОВЗ)
    const ovzCheckbox = document.getElementById('ovzCheckbox');
    const ovzHiddenField = document.getElementById('ovzHidden');

    ovzCheckbox.addEventListener('change', () => {
        ovzHiddenField.value = ovzCheckbox.checked ? '1' : '0';
    });

});
    const emailField = document.getElementById('email');
    emailField.addEventListener('input', () => {
        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailPattern.test(emailField.value)) {
            emailField.setCustomValidity("Введите корректный адрес электронной почты.");
        } else {
            emailField.setCustomValidity("");
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.target.classList.contains('input-words')) {
            if (event.key >= '0' && event.key <= '9') {
                event.preventDefault(); // Блокирует ввод цифр с клавиатуры
            }
        }
    });

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Форма заявления</title>
    <link rel="stylesheet" href="css/styles.css">
    <link rel="shortcut icon" href="img/ykuipt_logo.png">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <!-- Подключение библиотеки DaData -->
    <script src="https://cdn.jsdelivr.net/npm/suggestions-jquery@21.12.0/dist/js/jquery.suggestions.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/suggestions-jquery@21.12.0/dist/css/suggestions.min.css" rel="stylesheet" />
    <script src="script/inputmask.min.js"></script>
    <script src="script/script.js" defer></script>

</head>
<body>
    <div class="container my-4">
        <header>
            <div class="blur">
                <img src="img/ykuipt_logo.png" alt="Логотип"><h1>Заявление абитуриента</h1>
            </div>
        </header>
        <form action="save/submit_form.php" method="POST" id="zayav" onsubmit="submitForm(event)">
                <div class="forms_all">
                    <!-- Персональные данные -->
                    <div class="form_block">
                        <div class="mb-3">
                            <label for="surname" class="form-label">Фамилия <sup>*</sup></label>
                            <input type="text" class="form-control input-words" id="surname" name="surname" placeholder="Введите свою фамилию" required>
                        </div>
                        <div class="mb-3">
                            <label for="name" class="form-label">Имя <sup>*</sup></label>
                            <input type="text" class="form-control input-words" id="name" name="name" placeholder="Введите своё имя" required>
                        </div>
                        <div class="mb-3">
                            <label for="patronymic" class="form-label">Отчество <span>(при наличии)</span></label>
                            <input type="text" class="form-control input-words" id="patronymic" placeholder="Введите своё отчество" name="patronymic">
                        </div>
                        <div class="mb-3">
                            <label for="birthdate" class="form-label">Дата рождения <sup>*</sup></label>
                            <input type="date" class="form-control" id="birthdate" name="birthdate" required>
                        </div>
                        <div class="mb-3">
                            <label for="grazhdanstvo" class="form-label">Гражданство <sup>*</sup></label>
                            <input type="text" class="form-control input-words" id="grazhdanstvo" name="grazhdanstvo" placeholder="Напишите ваше гражданство" required>
                        </div>
                        <!-- Адреса -->
                        <div class="mb-3">

                            <!-- Чекбокс -->
                            <label>
                                <div class = "adresses-sovp">
                                    <input type="checkbox" id="sameAddressCheckbox" class = "check-adress"> Адрес проживания совпадает с адресом регистрации
                                </div>
                            </label>
                            <div class="adresses">

                                <div class="adress-registr">
                                    <h3 class="adress-h">Адрес регистрации <sup>*</sup></h3>
                                    <input type="text" id="registr_oblast" name="registr_oblast" class="adress-input" placeholder="Область" required>
                                    <input type="text" id="registr_gorod" name="registr_gorod" class="adress-input" placeholder="Город" required>
                                    <input type="text" id="registr_ulica" name="registr_ulica" class="adress-input" placeholder="Улица, пр-кт и т.д." required>
                                    <input type="text" id="registr_dom" name="registr_dom" class="adress-input" placeholder="Дом" required>
                                    <input type="text" id="registr_korpus" name="registr_korpus" class="adress-input" placeholder="Корпус (при наличии)">
                                    <input type="text" id="registr_kv" name="registr_kv" class="adress-input" placeholder="Квартира (при наличии)">
                                    <input type="text" id="registr_index" name="registr_index" class="adress-input" placeholder="Индекс" maxlength="6" required>
                                </div>
                                <script>
                                    const registrInput = document.getElementById('registr_index');

                                    // Блокируем ввод нецифровых символов
                                    registrInput.addEventListener('keypress', function (e) {
                                        if (!/\d/.test(e.key)) {
                                        e.preventDefault();
                                        }
                                    });

                                    // Удаляем всё, кроме цифр, и обрезаем до 6
                                    registrInput.addEventListener('input', function () {
                                        this.value = this.value.replace(/\D/g, '').substring(0, 6);
                                    });
                                </script>
                                

                                <div class="adress-prozhiv">
                                    <h3 class="adress-h">Адрес проживания <sup>*</sup></h3>
                                    <input type="text" id="prozhiv_oblast" name="prozhiv_oblast" class="adress-input" placeholder="Область" required>
                                    <input type="text" id="prozhiv_gorod" name="prozhiv_gorod" class="adress-input" placeholder="Город" required>
                                    <input type="text" id="prozhiv_ulica" name="prozhiv_ulica" class="adress-input" placeholder="Улица, пр-кт и т.д." required>
                                    <input type="text" id="prozhiv_dom" name="prozhiv_dom" class="adress-input" placeholder="Дом" required>
                                    <input type="text" id="prozhiv_korpus" name="prozhiv_korpus" class="adress-input" placeholder="Корпус (при наличии)">
                                    <input type="text" id="prozhiv_kv" name="prozhiv_kv" class="adress-input" placeholder="Квартира (при наличии)">
                                    <input type="text" id="prozhiv_index" name="prozhiv_index" class="adress-input" placeholder="Индекс" maxlength="6" required>
                                </div>
                                <script>
                                    const prozhivInput = document.getElementById('prozhiv_index');

                                    // Блокируем ввод нецифровых символов
                                    prozhivInput.addEventListener('keypress', function (e) {
                                        if (!/\d/.test(e.key)) {
                                        e.preventDefault();
                                        }
                                    });

                                    // Удаляем всё, кроме цифр, и обрезаем до 6
                                    prozhivInput.addEventListener('input', function () {
                                        this.value = this.value.replace(/\D/g, '').substring(0, 6);
                                    });
                                </script>
                            </div>
                        </div>

                    </div>
                    <div class="form_block">
                        <div class="mb-3">
                            <label class="form-label">Класс окончания обучения в школе: <sup>*</sup></label>
                            <div id="budzhetsContainer">
                                <?php
                                $conn = new mysqli('localhost', 'root', 'root', 'pk_2025');

                                if ($conn->connect_error) {
                                    die("Ошибка подключения: " . $conn->connect_error);
                                }

                                $query = "SELECT id_class, num FROM class";
                                $result = $conn->query($query);

                                if ($result->num_rows > 0) {
                                    while ($row = $result->fetch_assoc()) {
                                        echo "<div class='radio-group'>
                                            <input type='radio' class='radio' name='foreign_class' value='{$row['id_class']}' id='class{$row['id_class']}' required>
                                            <label for='class{$row['id_class']}' class='radio-label'>{$row['num']}</label>
                                        </div>";
                                    }
                                } else {
                                    echo "<p>Нет доступных вариантов бюджета.</p>";
                                }

                                $conn->close();
                                ?>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Желаемая форма обучения в колледже: <sup>*</sup></label>
                            <div id="formsContainer">
                                <?php
                                $conn = new mysqli('localhost', 'root', 'root', 'pk_2025');

                                if ($conn->connect_error) {
                                    die("Ошибка подключения: " . $conn->connect_error);
                                }

                                $query = "SELECT id_form, title FROM forma_obuch";
                                $result = $conn->query($query);

                                if ($result->num_rows > 0) {
                                    while ($row = $result->fetch_assoc()) {
                                        echo "<div class='radio-group'>
                                            <input type='radio' class='radio' name='foreign_forms' value='{$row['id_form']}' id='form{$row['id_form']}' required>
                                            <label for='form{$row['id_form']}' class='radio-label'>{$row['title']}</label>
                                        </div>";
                                    }
                                } else {
                                    echo "<p>Нет доступных форм обучения.</p>";
                                }

                                $conn->close();
                                ?>
                            </div>
                        </div>
                        <!-- Profession and Specialty Selection -->
                        <div class="mb-3">
                            <label for="professionSelect" class="form-label">Выберите специальности и профессии <span>(максимум 5) </span><sup>*</sup></label>
                            <div id="checkboxContainer">
                                <!-- Профессии/специальности будут загружаться через AJAX -->
                            </div>
                        </div>
                        
                        <div id="entranceExamField" class="mb-3" style="display:none;">
                            <label for="entranceExam" class="form-label">Вступительное испытание:</label>
                            <input type="text" class="form-control" id="entranceExam" name="entrance_exam" readonly>
                        </div>
                    </div>
                    <div class="form_block">
                        <div class="mb-3">
                            <label for="katgrazhd" class="form-label dostizh">Категория граждан <span>(сирота, многодетная семья, инвалидность).</span><sup>*</sup><br>При отстуствии - нет</br> </label>
                            <input type="text" class="form-control input-words" id="katgrazhd" name="kat_grazhd" placeholder="Введите свою категорию граждан" required>
                        </div>
                        <div class="mb-3">
                            <label for="perviy" class="form-label dostizh">Наличие права преимущественного или первоочередного приёма <span>(СВО, "ребёнок ветерана боевых действий", целевой набор и прочие)</span> <sup>*</sup><br>При отстуствии - нет</br></label>
                            <input type="text" class="form-control input-words" id="perviy" name="perviy" placeholder="Введите своё право преимущественного или первоочередного приёма" required>
                        </div>
                        <div class="mb-3">
                            <label for="identityDocument" class="form-label">Документ, удостоверяющий личность <sup>*</sup></label>
                            <input type="text" class="form-control input-words" id="identityDocument" name="identity_document" placeholder="Введите название документа" required>
                        </div>
                        <div class="mb-3">
                            <label for="seriesNumber" class="form-label">Серия и номер <sup>*</sup></label>
                            <input type="text" class="form-control" id="seriesNumber" name="series_number" maxlength="11" placeholder="Введите серию и номер документа" required>
                        </div>
                        <script>
                            window.addEventListener("DOMContentLoaded", function () {
                                Inputmask("9999 999999").mask(document.getElementById("seriesNumber"));
                            });
                        </script>
                        <div class="mb-3">
                            <label for="issueDate" class="form-label">Когда выдан <sup>*</sup></label>
                            <input type="date" class="form-control" id="issueDate" name="issue_date" required>
                        </div>
                        <div class="mb-3">
                            <label for="issuedBy" class="form-label">Кем выдан <sup>*</sup></label>
                            <input type="text" class="form-control input-words" id="issuedBy" name="issued_by" placeholder="Введите орган, выдавший документ" required>
                        </div>
                        <div class = "mb-3">
                            <div class="mesto_rozhd">
                                <h3 class="adress-h">Место рождения <sup>*</sup></h3>
                                <input type="text" id="mesto_rozhd_gorod" name="mesto_rozhd_gorod" class="adress-input" placeholder="Город" required>
                                <input type="text" id="mesto_rozhd_oblast" name="mesto_rozhd_oblast" class="adress-input" placeholder="Область" required>
                            </div>
                        </div>
                    </div>
                    <div class="form_block">
                        <div class="mb-3">
                            <label for="snils" class="form-label">СНИЛС:  <sup>*</sup></label>
                            <input type="text" class="form-control mask-snils" id="snils" name="snils" maxlength="14" placeholder="___-___-___ __" required>
                        </div>
                        <script>
                            Inputmask("999-999-999 99").mask(document.getElementById("snils"));
                        </script>
                        <div class="mb-3">
                            <label for="insurancePolicyNumber" class="form-label">Номер медицинского страхового полиса <sup>*</sup></label>
                            <input type="text" class="form-control" id="insurancePolicyNumber" name="insurance_policy_number" placeholder="Введите номер полиса" required>
                        </div>
                        <div class="mb-3">
                            <label for="insuranceCompany" class="form-label">Компания, выдавшая страховой полис <sup>*</sup></label>
                            <input type="text" class="form-control input-words" id="insuranceCompany" name="insurance_company" placeholder="Введите название компании" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Ваш e-mail: <sup>*</sup></label>
                            <input type="email" class="form-control" id="email" name="email" placeholder="Введите e-mail" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Документ об образовании: <sup>*</sup></label>
                            <div id="documentsContainer">
                            <?php
                            $conn = new mysqli('localhost', 'root', 'root', 'pk_2025');

                            if ($conn->connect_error) {
                                die("Ошибка подключения: " . $conn->connect_error);
                            }

                            $query = "SELECT id_doc_obr, title FROM doc_obr";
                            $result = $conn->query($query);

                            if ($result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    echo "<div class='radio-group'>
                                        <input type='radio' class='radio' name='foreign_documents[]' value='{$row['id_doc_obr']}' id='doc{$row['id_doc_obr']}'>
                                        <label for='doc{$row['id_doc_obr']}' class='radio-label'>{$row['title']}</label>
                                    </div>";
                                }
                            } else {
                                echo "<p>Нет доступных иностранных языков.</p>";
                            }

                            $conn->close();
                            ?>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="seriesNumberDocObr" class="form-label">Серия и номер <sup>*</sup></label>
                            <input type="text" class="form-control" id="seriesNumberDocObr" name="seriesNumberDocObr" placeholder="Введите серию и номер документа" required>
                        </div>
                        <div class="mb-3">
                            <label for="issueDateDocObr" class="form-label">Когда выдан <sup>*</sup></label>
                            <input type="date" class="form-control" id="issueDateDocObr" name="issueDateDocObr" required>
                        </div>
                        <div class="mb-3">
                            <label for="issuedByDocObr" class="form-label">Кем выдан <sup>*</sup></label>
                            <input type="text" class="form-control" id="issuedByDocObr" name="issuedByDocObr" placeholder="Введите орган, выдавший документ" required>
                        </div>
                    </div>
                    <div class="form_block">
                        <div class="mb-3">
                            <label for="phone" class="form-label">Телефон <sup>*</sup></label>
                            <input type="tel" class="form-control" id="phone" name="phone" maxlength="18" placeholder="Введите ваш номер телефона" required>
                        </div>
                        <script>
                            Inputmask("+7 (999) 999-99-99").mask(document.getElementById("phone"));

                            </script>
                        <div class="mb-3">
                            <label class="form-label">Выберите основной иностранный язык: <sup>*</sup></label>
                            <div id="languagesContainer">
                                <?php
                                $conn = new mysqli('localhost', 'root', 'root', 'pk_2025');
                                if ($conn->connect_error) {
                                    die("Ошибка подключения: " . $conn->connect_error);
                                }

                                $query = "SELECT id_in_yaz, title FROM in_yaz";
                                $result = $conn->query($query);

                                if ($result->num_rows > 0) {
                                    while ($row = $result->fetch_assoc()) {
                                        echo "<div class='radio-group'>
                                            <input type='radio' class='radio' name='foreign_languages' value='{$row['id_in_yaz']}' id='language{$row['id_in_yaz']}' required>
                                            <label for='language{$row['id_in_yaz']}' class='radio-label'>{$row['title']}</label>
                                        </div>";
                                    }
                                }
                                ?>
                                <!-- Опция "Другой язык" -->
                                <div class='radio-group'>
                                    <input type='radio' class='radio' name='foreign_languages' value='other' id='languageOther' required>
                                    <label for='languageOther' class='radio-label'>Другой язык</label>
                                </div>

                                <!-- Поле для ввода другого языка (скрыто по умолчанию) -->
                                <div id="otherLanguageInput" style="display: none; margin-top: 10px;">
                                    <input type="text" name="other_language" id="other_language" placeholder="Введите другой язык">
                                </div>
                            </div>
                        </div>

                        <script>
                            document.querySelectorAll('input[name="foreign_languages"]').forEach(radio => {
                                radio.addEventListener('change', function() {
                                    let otherInput = document.getElementById('otherLanguageInput');
                                    if (this.value === 'other') {
                                        otherInput.style.display = 'block';
                                        document.getElementById('other_language').setAttribute('required', 'required');
                                    } else {
                                        otherInput.style.display = 'none';
                                        document.getElementById('other_language').removeAttribute('required');
                                    }
                                });
                            });
                        </script>

                    </div>
                    <div class="form_block">
                    <div class="mb-3">
                        <label class="form-label">В создании специальных условий для инвалидов и лиц с ОВЗ при проведении вступительных испытаний:</label><br>
                        <div class="nuzhda chekbox-group">
                            <!-- Уникальный id для чекбокса -->
                            <input type="checkbox" class="checkbox" name="usl_ovz" value="1" id="usl_ovz">
                            <!-- Связь метки с чекбоксом через атрибут for -->
                            <label for="usl_ovz">Нуждаюсь</label>  
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">В общежитии:</label><br>
                        <div class="nuzhda chekbox-group">
                            <!-- Уникальный id для чекбокса -->
                            <input type="checkbox" class="checkbox" name="obzh" value="1" id="obzh"> 
                            <!-- Связь метки с чекбоксом через атрибут for -->
                            <label for="obzh">Нуждаюсь</label>
                        </div>
                    </div>


                    <div class="mb-3">
                        <label for="personalAchievements" class="form-label dostizh">Индивидуальные достижения <span>(медаль, аттестат/диплом "с отличием", победитель всероссийских олимпиад, спортивные достижения, волонтёр и др.)</span></label>
                        <textarea class="form-control" id="personalAchievements" name="personal_achievements" rows="4" 
                                placeholder="Напишите ваши достижения через запятую" 
                                maxlength="100"></textarea>
                        <div class="text-end text-muted small">
                            <span id="charCount">100</span> символов осталось
                        </div>
                    </div>

                    <script>
                        document.getElementById('personalAchievements').addEventListener('input', function() {
                            const maxLength = 100;
                            const remaining = maxLength - this.value.length;
                            document.getElementById('charCount').textContent = remaining;
                        });
                    </script>
                        <div class="mb-3">
                            <label for="averageScore" class="form-label">Средний балл аттестата: <span>(От 2.00 до 5.00, через точку. Например, 4.50)</span> <sup>*</sup></label>
                            <input type="text" class="form-control" id="averageScore" name="average_score" required>
                        </div>

                        <script>
                            Inputmask({
                                mask: "9.9999",
                                placeholder: "0",
                                greedy: false,
                                definitions: {
                                    '9': {
                                        validator: "[0-9]",
                                        cardinality: 1
                                    }
                                },
                                oncomplete: function () {
                                    const val = parseFloat(document.getElementById("averageScore").value);
                                    if (val < 2 || val > 5) {
                                        alert("Допустимое значение от 2.0000 до 5.0000");
                                        document.getElementById("averageScore").value = '';
                                    }
                                }
                            }).mask(document.getElementById("averageScore"));
                        </script>

                        
                        <div class="form-check mb-3 chekbox-group">
                            <input type="checkbox" class="form-check-input checkbox" id="agreementCheckbox" required>
                            <label class="form-check-label" for="agreementCheckbox">Я <a href="https://disk.yandex.ru/i/3gkN9mCDqj1SJw">несовершеннолетний</a>/<a href="https://disk.yandex.ru/i/NlYYLwIlI_5vGw">совершеннолетний</a> согласен на обработку персональных данных</label>
                        </div>
                        <!-- Submit Button -->
                        <button type="submit" class="btn btn-primary">Отправить</button>
                    </div>
                </div>
                

            </form>
    </div>
    <script>
        function showMessage(text, type) {
            let messageBox = document.createElement('div');
            messageBox.textContent = text;
            messageBox.classList.add('message', type);

            document.body.appendChild(messageBox);

            setTimeout(() => {
                messageBox.style.opacity = '1';
            }, 100);

            setTimeout(() => {
                messageBox.style.opacity = '0';
                setTimeout(() => messageBox.remove(), 500);
            }, 3000);
        }

        document.addEventListener("DOMContentLoaded", function() {
            const checkbox = document.getElementById("sameAddressCheckbox");
            const fields = ["oblast", "gorod", "ulica", "dom", "korpus", "kv", "index"];

            function syncAddress() {
                fields.forEach(field => {
                    let regField = document.getElementById("registr_" + field);
                    let prozhField = document.getElementById("prozhiv_" + field);

                    if (checkbox.checked) {
                        prozhField.value = regField.value;
                        prozhField.setAttribute("readonly", true);
                    } else {
                        prozhField.removeAttribute("readonly");
                    }
                });
            }

            // Обновление при изменении полей регистрации
            fields.forEach(field => {
                document.getElementById("registr_" + field).addEventListener("input", syncAddress);
            });

            // Обработчик переключения чекбокса
            checkbox.addEventListener("change", function() {
                if (checkbox.checked) {
                    syncAddress();
                } else {
                    fields.forEach(field => {
                        document.getElementById("prozhiv_" + field).removeAttribute("readonly");
                    });
                }
            });

            // Первоначальная синхронизация
            syncAddress();
        });


        // Валидация дат: корректный формат, существующая дата и не в будущем
        function validateDateNotFuture(value, fieldLabel) {
            if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
                throw new Error(`Поле "${fieldLabel}" заполнено некорректно`);
            }
            const date = new Date(value + "T00:00:00");
            if (isNaN(date.getTime())) {
                throw new Error(`Поле "${fieldLabel}" содержит несуществующую дату`);
            }
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            if (date > today) {
                throw new Error(`Поле "${fieldLabel}" не может быть датой в будущем`);
            }
        }

        function validateDatesOrThrow() {
            const birth = document.getElementById("birthdate").value;
            const issue = document.getElementById("issueDate").value;
            const issueDocObr = document.getElementById("issueDateDocObr").value;

            validateDateNotFuture(birth, "Дата рождения");
            validateDateNotFuture(issue, "Когда выдан (паспорт)");
            validateDateNotFuture(issueDocObr, "Когда выдан (документ об образовании)");
        }

        function submitForm(event) {
        event.preventDefault(); // Останавливаем стандартную отправку формы

        try {
            validateDatesOrThrow();
        } catch (err) {
            showMessage(err.message || "Проверьте корректность введённых дат", "error");
            return;
        }

        // Создаем данные для отправки
        var formData = new FormData(document.getElementById('zayav'));

        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'save/submit_form.php', true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState == 4) {
                if (xhr.status == 200) {
                    // Можно проверить ответ сервера на наличие ошибок (если сервер возвращает JSON)
                    // Например, если сервер возвращает JSON с полем error
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.error) {
                            showMessage("Ошибка: " + response.error, "error");
                        } else {
                            showMessage("Заявление успешно отправлено!", "success");
                            document.getElementById('checkboxContainer').innerHTML = response.html; // пример
                            document.getElementById('zayav').reset();
                            setTimeout(() => location.reload(), 500);
                        }
                    } catch(e) {
                        // Если ответ не JSON, считаем его успешным
                        showMessage("Заявление успешно отправлено!", "success");
                        document.getElementById('checkboxContainer').innerHTML = xhr.responseText;
                        document.getElementById('zayav').reset();
                        setTimeout(() => location.reload(), 500);
                    }
                } else {
                    // Обработка ошибок HTTP
                    showMessage("Ошибка сервера: " + xhr.status, "error");
                }
            }
        };
        xhr.send(formData);


    }
    </script>

</body>
</html>

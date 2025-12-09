<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

include '../config/config.php';

// Определяем $order ДО блока обработки POST
$order = [
    // Абитуриент
    'id_abit',
    'familiya',
    'imya',
    'otchestvo',
    'snils',
    'phone',
    'date_bd',
    'document',
    'ser_num',
    'date_vidachi_doc',
    'kem_vidan_doc',
    'polis',
    'komp_polis',
    'email',
    'id_doc_obr',
    'doc_obr_title',
    'num_doc_obr',
    'date_doc_obr',
    'kem_doc_obr',
    'in_yaz_id',
    'in_yaz_title',
    'dr_in_yaz',
    'usl_ovz',
    'obzh',
    'grazhdanstvo',
    'kat_grazhd',
    'pervooch_priem',
    'dostizheniya',
    'sr_ball_attest',
    // Адрес проживания
    'adr_prozhiv_id',
    'prozh_oblast',
    'prozh_gorod',
    'prozh_ulica',
    'prozh_dom',
    'prozh_korpus',
    'prozh_kv',
    'prozh_indecs',
    // Адрес регистрации
    'adr_reg_id',
    'reg_oblast',
    'reg_gorod',
    'reg_ulica',
    'reg_dom',
    'reg_korpus',
    'reg_kv',
    'reg_indecs',
    // Место рождения
    'mesto_rozhd_id',
    'mr_gorod',
    'mr_oblast',
];

// Справочник документов об образовании
$docOptions = [];
$docRes = $conn->query("SELECT id_doc_obr, title FROM doc_obr");
if ($docRes) {
    while ($row = $docRes->fetch_assoc()) {
        $docOptions[] = $row;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id_abit = intval($_POST['id_abit'] ?? 0);
    if ($id_abit <= 0) {
        echo json_encode(['success' => false, 'message' => 'Некорректный ID абитуриента.']);
        exit;
    }

    // Нормализация дат
    $dateFields = ['date_bd', 'date_vidachi_doc', 'date_doc_obr'];
    foreach ($dateFields as $df) {
        if (isset($_POST[$df]) && $_POST[$df] !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $_POST[$df]);
            if (!$dt) {
                echo json_encode(['success' => false, 'message' => "Некорректный формат даты: $df"]);
                exit;
            }
            $_POST[$df] = $dt->format('Y-m-d');
        } else {
            $_POST[$df] = null;
        }
    }

    // Подготовка данных для таблицы abit
    $abitFields = [
        'familiya','imya','otchestvo','snils','phone','date_bd','document','ser_num',
        'date_vidachi_doc','kem_vidan_doc','polis','komp_polis','email','id_doc_obr',
        'num_doc_obr','date_doc_obr','kem_doc_obr','in_yaz','dr_in_yaz','usl_ovz','obzh',
        'grazhdanstvo','kat_grazhd','pervooch_priem','dostizheniya','sr_ball_attest'
    ];
    $abitParams = [];
    foreach ($abitFields as $f) {
        if (in_array($f, ['usl_ovz','obzh'])) {
            $abitParams[$f] = isset($_POST[$f]) ? 1 : 0;
        } else {
            $abitParams[$f] = $_POST[$f] ?? null;
        }
    }
    // Явно маппим алиас in_yaz_id в поле in_yaz
    if (isset($_POST['in_yaz_id'])) {
        $abitParams['in_yaz'] = $_POST['in_yaz_id'];
    }

    $adrProzhId = intval($_POST['adr_prozhiv_id'] ?? 0);
    $adrRegId = intval($_POST['adr_reg_id'] ?? 0);
    $mestoId = intval($_POST['mesto_rozhd_id'] ?? 0);

    $prozh = [
        'oblast' => $_POST['prozh_oblast'] ?? '',
        'gorod' => $_POST['prozh_gorod'] ?? '',
        'ulica' => $_POST['prozh_ulica'] ?? '',
        'dom' => $_POST['prozh_dom'] ?? '',
        'korpus' => $_POST['prozh_korpus'] ?? null,
        'kv' => $_POST['prozh_kv'] ?? null,
        'indecs' => $_POST['prozh_indecs'] ?? ''
    ];
    $reg = [
        'oblast' => $_POST['reg_oblast'] ?? '',
        'gorod' => $_POST['reg_gorod'] ?? '',
        'ulica' => $_POST['reg_ulica'] ?? '',
        'dom' => $_POST['reg_dom'] ?? '',
        'korpus' => $_POST['reg_korpus'] ?? null,
        'kv' => $_POST['reg_kv'] ?? null,
        'indecs' => $_POST['reg_indecs'] ?? ''
    ];
    $mesto = [
        'gorod' => $_POST['mr_gorod'] ?? '',
        'oblast' => $_POST['mr_oblast'] ?? ''
    ];

    $conn->begin_transaction();
    try {
        // Обновление адресов проживания
        if ($adrProzhId > 0) {
            $stmt = $conn->prepare("UPDATE adress_prozhiv SET oblast=?, gorod=?, ulica=?, dom=?, korpus=?, kv=?, indecs=? WHERE id_adr_prozh=?");
            $stmt->bind_param("sssssssi",
                $prozh['oblast'], $prozh['gorod'], $prozh['ulica'], $prozh['dom'],
                $prozh['korpus'], $prozh['kv'], $prozh['indecs'], $adrProzhId
            );
            $stmt->execute();
            $stmt->close();
        }

        // Обновление адреса регистрации
        if ($adrRegId > 0) {
            $stmt = $conn->prepare("UPDATE adress_registr SET oblast=?, gorod=?, ulica=?, dom=?, korpus=?, kv=?, indecs=? WHERE id_adr_reg=?");
            $stmt->bind_param("sssssssi",
                $reg['oblast'], $reg['gorod'], $reg['ulica'], $reg['dom'],
                $reg['korpus'], $reg['kv'], $reg['indecs'], $adrRegId
            );
            $stmt->execute();
            $stmt->close();
        }

        // Обновление места рождения
        if ($mestoId > 0) {
            $stmt = $conn->prepare("UPDATE mesto_rozhd SET gorod=?, oblast=? WHERE id_mest_rozhd=?");
            $stmt->bind_param("ssi", $mesto['gorod'], $mesto['oblast'], $mestoId);
            $stmt->execute();
            $stmt->close();
        }

        // Обновление abit
        $sqlSet = [];
        $types = "";
        $values = [];
        foreach ($abitParams as $field => $val) {
            $sqlSet[] = "$field = ?";
            // тип: числовые
            if ($field === 'sr_ball_attest') {
                $types .= "d";
            } elseif (in_array($field, ['in_yaz','id_doc_obr'])) {
                $types .= "i";
            } elseif (in_array($field, ['usl_ovz','obzh'])) {
                $types .= "i";
            } else {
                $types .= "s";
            }
            $values[] = $val;
        }
        // foreign keys to address / place
        $sqlSet[] = "adress_prozhiv = ?";
        $types .= "i";
        $values[] = $adrProzhId ?: null;

        $sqlSet[] = "adress_registr = ?";
        $types .= "i";
        $values[] = $adrRegId ?: null;

        $sqlSet[] = "mesto_rozhd = ?";
        $types .= "i";
        $values[] = $mestoId ?: null;

        $types .= "i";
        $values[] = $id_abit;

        $sql_update = "UPDATE abit SET " . implode(", ", $sqlSet) . " WHERE id_abit = ?";
        $stmt = $conn->prepare($sql_update);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Данные успешно сохранены.']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
    }
    exit;
}




$labels = [
    'id_abit' => 'ID абитуриента',
    'familiya' => 'Фамилия',
    'imya' => 'Имя',
    'otchestvo' => 'Отчество',
    'snils' => 'СНИЛС',
    'phone' => 'Телефон',
    'date_bd' => 'Дата рождения',
    'document' => 'Тип документа',
    'ser_num' => 'Серия и номер документа',
    'date_vidachi_doc' => 'Дата выдачи документа',
    'kem_vidan_doc' => 'Кем выдан документ',
    'polis' => 'Номер полиса',
    'komp_polis' => 'Страховая компания',
    'email' => 'Электронная почта',
    'id_doc_obr' => 'ID документа об образовании',
    'doc_obr_title' => 'Тип документа об образовании',
    'num_doc_obr' => 'Номер документа об образовании',
    'date_doc_obr' => 'Дата выдачи документа об образовании',
    'kem_doc_obr' => 'Кем выдан документ об образовании',
    'in_yaz_id' => 'ID иностранного языка',
    'in_yaz_title' => 'Изучаемый иностранный язык',
    'dr_in_yaz' => 'Второй иностранный язык',
    'usl_ovz' => 'Наличие ОВЗ',
    'obzh' => 'Нужда в общежитии',
    'grazhdanstvo' => 'Гражданство',
    'kat_grazhd' => 'Категория гражданства',
    'pervooch_priem' => 'Право на внеочередной приём',
    'dostizheniya' => 'Достижения',
    'sr_ball_attest' => 'Средний балл аттестата',
    'adr_prozhiv_id' => 'ID адреса проживания',
    'prozh_oblast' => 'Область проживания',
    'prozh_gorod' => 'Город проживания',
    'prozh_ulica' => 'Улица проживания',
    'prozh_dom' => 'Дом проживания',
    'prozh_korpus' => 'Корпус проживания',
    'prozh_kv' => 'Квартира проживания',
    'prozh_indecs' => 'Индекс проживания',
    'adr_reg_id' => 'ID адреса регистрации',
    'reg_oblast' => 'Область регистрации',
    'reg_gorod' => 'Город регистрации',
    'reg_ulica' => 'Улица регистрации',
    'reg_dom' => 'Дом регистрации',
    'reg_korpus' => 'Корпус регистрации',
    'reg_kv' => 'Квартира регистрации',
    'reg_indecs' => 'Индекс регистрации',
    'mesto_rozhd_id' => 'ID места рождения',
    'mr_gorod' => 'Город рождения',
    'mr_oblast' => 'Область рождения',
];
if (isset($_GET['id_zayav']) && is_numeric($_GET['id_zayav']) && $_GET['id_zayav'] > 0) {
    $id_zayav = intval($_GET['id_zayav']);

    // Получаем id абитуриента из заявления
    $sql_get_abit = "SELECT id_abitur FROM zayav WHERE id_zayav = $id_zayav LIMIT 1";
    $res = $conn->query($sql_get_abit);

    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $id_abit = $row['id_abitur'];

        // Теперь получаем данные абитуриента по id_abit
        $sql = "
            SELECT 
                abit.*,
                abit.id_doc_obr as id_doc_obr,
                doc_obr.title as doc_obr_title,
                abit.in_yaz as in_yaz_id,
                in_yaz.title as in_yaz_title,
                abit.adress_prozhiv as adr_prozhiv_id,
                ap.oblast as prozh_oblast, ap.gorod as prozh_gorod, ap.ulica as prozh_ulica, ap.dom as prozh_dom, ap.korpus as prozh_korpus, ap.kv as prozh_kv, ap.indecs as prozh_indecs,
                abit.adress_registr as adr_reg_id,
                ar.oblast as reg_oblast, ar.gorod as reg_gorod, ar.ulica as reg_ulica, ar.dom as reg_dom, ar.korpus as reg_korpus, ar.kv as reg_kv, ar.indecs as reg_indecs,
                abit.mesto_rozhd as mesto_rozhd_id,
                mr.gorod as mr_gorod, mr.oblast as mr_oblast
            FROM abit
            LEFT JOIN adress_prozhiv ap ON abit.adress_prozhiv = ap.id_adr_prozh
            LEFT JOIN adress_registr ar ON abit.adress_registr = ar.id_adr_reg
            LEFT JOIN in_yaz ON abit.in_yaz = in_yaz.id_in_yaz
            LEFT JOIN doc_obr ON abit.id_doc_obr =  doc_obr.id_doc_obr
            LEFT JOIN mesto_rozhd mr ON abit.mesto_rozhd = mr.id_mest_rozhd
            WHERE abit.id_abit = $id_abit
            LIMIT 1
        ";

        $result = $conn->query($sql);

        if (!$result) {
            echo "Ошибка SQL: " . $conn->error;
            exit;
        }
        function formatDate($dateStr) {
            if (!$dateStr || $dateStr == '0000-00-00') {
                return '';
            }
            $date = DateTime::createFromFormat('Y-m-d', $dateStr);
            if ($date === false) {
                return $dateStr; // если не дата, вернём как есть
            }
            return $date->format('d.m.Y');
        }
        
        if ($result->num_rows > 0) {
            $data = $result->fetch_assoc();

            echo "<h3>{$data['familiya']} {$data['imya']} {$data['otchestvo']}</h3>";
            echo "<form method='post' id = 'editForm'>";
            echo "<table border='1' cellpadding='5'>";
            foreach ($order as $key) {
                if (isset($data[$key])) {
                    $label = isset($labels[$key]) ? $labels[$key] : $key;
                    $value = $data[$key];

                    // Форматируем даты для вывода в input type="date" (формат Y-m-d)
                    if (in_array($key, ['date_bd', 'date_vidachi_doc', 'date_doc_obr'])) {
                        if ($value && $value != '0000-00-00') {
                            $dateObj = DateTime::createFromFormat('Y-m-d', $value);
                            $value = $dateObj ? $dateObj->format('Y-m-d') : '';
                        } else {
                            $value = '';
                        }
                        echo "<tr><td><strong>$label</strong></td><td><input type='date' name='$key' value='$value'></td></tr>";
                        continue;
                    }

                    // Чекбоксы
                    if (in_array($key, ['usl_ovz', 'obzh'])) {
                        $checked = $value ? 'checked' : '';
                        echo "<tr><td><strong>$label</strong></td><td><input type='checkbox' name='$key' value='1' $checked></td></tr>";
                        continue;
                    }

                    // Выпадающий список для типа документа об образовании
                    if ($key === 'id_doc_obr') {
                        echo "<tr><td><strong>$label</strong></td><td><select name='id_doc_obr'>";
                        foreach ($docOptions as $opt) {
                            $sel = ($opt['id_doc_obr'] == $value) ? "selected" : "";
                            echo "<option value='{$opt['id_doc_obr']}' $sel>" . htmlspecialchars($opt['title'], ENT_QUOTES) . "</option>";
                        }
                        echo "</select></td></tr>";
                        continue;
                    }

                    // Пропускаем лишний вывод названия, оно дублируется
                    if ($key === 'doc_obr_title') {
                        continue;
                    }

                    // ID поля — только просмотр (серым, readonly)
                    if (in_array($key, ['id_abit','in_yaz_id','adr_prozhiv_id','adr_reg_id','mesto_rozhd_id'])) {
                        echo "<tr><td><strong>$label</strong></td><td><input type='number' name='$key' value='" . htmlspecialchars($value, ENT_QUOTES) . "' readonly style='background:#f0f0f0; color:#666;'></td></tr>";
                        continue;
                    }

                    echo "<tr><td><strong>$label</strong></td><td><input type='text' name='$key' value='" . htmlspecialchars($value, ENT_QUOTES) . "'></td></tr>";
                }
            }

            // Скрытое поле с ID
            echo "<input type='hidden' name='id_abit' value='{$data['id_abit']}'>";
            echo '</table><br><button>Сохранить</button></form>';
        } else {
            echo "Абитуриент не найден.";
        }
    } else {
        echo "Заявление не найдено.";
    }
} else {
    echo "Некорректный или отсутствующий ID заявления.";
}
?>

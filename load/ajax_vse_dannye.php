<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

include '../config/config.php';

// Определяем $order ДО блока обработки POST
$order = [
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
    'doc_obr.title',
    'num_doc_obr',
    'date_doc_obr',
    'kem_doc_obr',
    'in_yaz.title',
    'dr_in_yaz',
    'usl_ovz',
    'obzh',
    'grazhdanstvo',
    'kat_grazhd',
    'pervooch_priem',
    'dostizheniya',
    'sr_ball_attest',
    'adress_prozhiv.oblast',
    'adress_prozhiv.gorod',
    'adress_prozhiv.ulica',
    'adress_prozhiv.dom',
    'adress_prozhiv.korpus',
    'adress_prozhiv.kv',
    'adress_prozhiv.indecs',
    'adress_registr.oblast',
    'adress_registr.gorod',
    'adress_registr.ulica',
    'adress_registr.dom',
    'adress_registr.korpus',
    'adress_registr.kv',
    'adress_registr.indecs',
    'mesto_rozhd.gorod',
    'mesto_rozhd.oblast',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id_abit = intval($_POST['id_abit']);
    if ($id_abit <= 0) {
        echo json_encode(['success' => false, 'message' => 'Некорректный ID абитуриента.']);
        exit;
    }

    $fields_to_update = [];
    foreach ($order as $key) {
        if (in_array($key, ['usl_ovz', 'obzh'])) {
            $fields_to_update[$key] = isset($_POST[$key]) ? 1 : 0;
        } else {
            if (isset($_POST[$key])) {
                $fields_to_update[$key] = $_POST[$key];
            }
        }
    }

    $set_parts = [];
    foreach ($fields_to_update as $field => $value) {
        $safe_value = $conn->real_escape_string($value);
        $set_parts[] = "`$field` = '$safe_value'";
    }
    $set_sql = implode(", ", $set_parts);

    $sql_update = "UPDATE `abit` SET $set_sql WHERE id_abit = $id_abit";

    if ($conn->query($sql_update)) {
    echo json_encode(['success' => true, 'message' => 'Данные успешно сохранены.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $conn->error]);
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
    'doc_obr.title' => 'Тип документа об образовании',
    'num_doc_obr' => 'Номер документа об образовании',
    'date_doc_obr' => 'Дата выдачи документа об образовании',
    'kem_doc_obr' => 'Кем выдан документ об образовании',
    
    // Дополнительные поля
    'in_yaz.title' => 'Изучаемый иностранный язык',
    'dr_in_yaz' => 'Второй иностранный язык',

    'usl_ovz' => 'Наличие ОВЗ',
    'obzh' => 'Нужда в общежитии',
    'grazhdanstvo' => 'Гражданство',
    'kat_grazhd' => 'Категория гражданства',
    'pervooch_priem' => 'Право на внеочередной приём',
    'dostizheniya' => 'Достижения',
    'sr_ball_attest' => 'Средний балл аттестата',

    // Адрес проживания
    'adress_prozhiv.oblast' => 'Область проживания',
    'adress_prozhiv.gorod' => 'Город проживания',
    'adress_prozhiv.ulica' => 'Улица проживания',
    'adress_prozhiv.dom' => 'Дом проживания',
    'adress_prozhiv.korpus' => 'Корпус проживания',
    'adress_prozhiv.kv' => 'Квартира проживания',
    'adress_prozhiv.indecs' => 'Индекс проживания',

    // Адрес регистрации
    'adress_registr.oblast' => 'Область регистрации',
    'adress_registr.gorod' => 'Город регистрации',
    'adress_registr.ulica' => 'Улица регистрации',
    'adress_registr.dom' => 'Дом регистрации',
    'adress_registr.korpus' => 'Корпус регистрации',
    'adress_registr.kv' => 'Квартира регистрации',
    'adress_registr.indecs' => 'Индекс регистрации',

    'mesto_rozhd.gorod' => 'Город рождения',
    'mesto_rozhd.oblast' => 'Область рождения',

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
                adress_prozhiv.oblast, adress_prozhiv.gorod, adress_prozhiv.ulica, adress_prozhiv.dom, adress_prozhiv.korpus, adress_prozhiv.kv, adress_prozhiv.indecs,
                adress_registr.oblast, adress_registr.gorod, adress_registr.ulica, adress_registr.dom, adress_registr.korpus, adress_registr.kv, adress_registr.indecs,
                in_yaz.title,
                doc_obr.title,
                mesto_rozhd.gorod, mesto_rozhd.oblast
            FROM abit
            LEFT JOIN adress_prozhiv ON abit.adress_prozhiv = adress_prozhiv.id_adr_prozh
            LEFT JOIN adress_registr ON abit.adress_registr = adress_registr.id_adr_reg
            LEFT JOIN in_yaz ON abit.in_yaz = in_yaz.id_in_yaz
            LEFT JOIN doc_obr ON abit.id_doc_obr =  doc_obr.id_doc_obr
            LEFT JOIN mesto_rozhd ON abit.mesto_rozhd = mesto_rozhd.id_mest_rozhd
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
                        // Преобразуем к формату Y-m-d для input date
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

                    echo "<tr><td><strong>$label</strong></td><td><input type='text' name='$key' value='" . htmlspecialchars($value, ENT_QUOTES) . "'></td></tr>";
                }
            }

            // Скрытое поле с ID
            echo "<input type='hidden' name='id_abit' value='{$data['id_abit']}'>";
            echo '</table><br><button>Сохранить</form>';
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

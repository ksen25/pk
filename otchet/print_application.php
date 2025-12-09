<?php
require_once __DIR__ . '/../../vendor/autoload.php';
use PhpOffice\PhpWord\TemplateProcessor;

if (!class_exists('PhpOffice\PhpWord\TemplateProcessor')) {
    die("Ошибка: PhpWord не установлен!");
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Подключение к базе данных
$host = 'localhost';
$user = 'root';
$password = 'root';
$dbname = 'pk_2025';
$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

// Получаем ID заявления из GET-параметра
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Ошибка: Неверный ID заявления.");
}
$id_zayav = (int)$_GET['id'];

// SQL-запрос для получения данных заявления
$sql = "
SELECT 
    abit.id_abit,
    abit.familiya, 
    abit.imya, 
    abit.otchestvo, 
    abit.snils,
    abit.date_bd, 
    abit.phone,
    abit.document,
    abit.ser_num,
    abit.date_vidachi_doc,
    abit.kem_vidan_doc,
    abit.polis,
    abit.komp_polis,
    abit.email,
    abit.id_doc_obr AS doc_obr,
    abit.num_doc_obr,
    abit.date_doc_obr,
    abit.kem_doc_obr,
    abit.in_yaz,
    abit.dr_in_yaz AS dr_in_yaz_title,
    abit.usl_ovz,
    abit.obzh,
    abit.grazhdanstvo,
    abit.kat_grazhd,
    abit.pervooch_priem,
    abit.dostizheniya,
    abit.sr_ball_attest,
    CONCAT_WS(', ', 
        CONCAT(adress_registr.oblast, ' обл.'),
        CONCAT('г. ', adress_registr.gorod), 
        adress_registr.ulica, 
        CONCAT('д. ', adress_registr.dom),
        CASE 
            WHEN adress_registr.korpus IS NOT NULL THEN CONCAT('к. ', adress_registr.korpus) 
            ELSE NULL 
        END,
        CONCAT('кв. ', adress_registr.kv),
        CONCAT('индекс ', adress_registr.indecs)
    ) AS address_registr,
    zayav.date, 
    profec_spec.id_prof_spec,
    profec_spec.code,
    profec_spec.title AS spec_title,
    profec_spec.ispitanie,
    kat_spec_prof.title AS kategoriya,
    budzhet.title AS budzhet_title,
    profec_spec.forma_obuch,
    profec_spec.socr,
    ispitaniya.title,
    class.num AS class,
    programm.title AS programm,
    CONCAT_WS(', ', 
        CONCAT ('г. ', mesto_rozhd.gorod),
        CONCAT (mesto_rozhd.oblast, ' обл.')
    ) AS mesto_rozhd,
    CONCAT_WS(', ', 
        CONCAT (adress_prozhiv.oblast, ' обл.'),
        CONCAT ('г. ', adress_prozhiv.gorod), 
        adress_prozhiv.ulica, 
        CONCAT('д. ', adress_prozhiv.dom),
        CASE 
            WHEN adress_prozhiv.korpus IS NOT NULL THEN CONCAT('к. ', adress_prozhiv.korpus) 
            ELSE NULL 
        END,
        CONCAT('кв. ', adress_prozhiv.kv),
        CONCAT('индекс ', adress_prozhiv.indecs)
    ) AS address_prozhiv
FROM 
    abit
JOIN 
    zayav ON abit.id_abit = zayav.id_abitur
JOIN 
    profec_spec ON zayav.id_spec_prof = profec_spec.id_prof_spec
JOIN 
    kat_spec_prof ON profec_spec.kategoriya = kat_spec_prof.id_kat
LEFT JOIN
    adress_prozhiv ON abit.adress_prozhiv = adress_prozhiv.id_adr_prozh
LEFT JOIN 
    adress_registr ON abit.adress_registr = adress_registr.id_adr_reg
LEFT JOIN 
    mesto_rozhd ON abit.mesto_rozhd = mesto_rozhd.id_mest_rozhd
LEFT JOIN 
    in_yaz ON abit.in_yaz = in_yaz.id_in_yaz
LEFT JOIN
    class ON profec_spec.class = class.id_class
LEFT JOIN 
    programm ON profec_spec.programm = programm.id_programm
LEFT JOIN
    doc_obr ON abit.id_doc_obr = doc_obr.id_doc_obr
LEFT JOIN
    budzhet ON zayav.budzhet = budzhet.id_bud
LEFT JOIN 
    ispitaniya ON profec_spec.ispitanie = ispitaniya.id_ispit
LEFT JOIN
    forma_obuch ON profec_spec.forma_obuch = forma_obuch.id_form
WHERE 
    zayav.id_zayav = ? 
";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Ошибка подготовки запроса: " . $conn->error);
}
$stmt->bind_param("i", $id_zayav);
$stmt->execute();
$result = $stmt->get_result();

// Проверка на наличие данных
if ($result->num_rows > 0) {
    $data = $result->fetch_assoc();
    $id_abit = $data['id_abit'];
    $socr = $data['socr'];

    // Проверка и формирование имени файла
    if (empty($id_abit) || empty($socr)) {
        die("Ошибка: ID абитуриента или сокращение специальности отсутствуют.");
    }

    $templatePath = __DIR__ . '/../../zayavlenie.docx';


    if (!file_exists($templatePath)) {
        die("Ошибка: файл `$templatePath` не найден! Проверьте путь: $templatePath");
    }

    // Создаем объект TemplateProcessor
    $templateProcessor = new TemplateProcessor($templatePath);

    // Заменяем метки на реальные данные
    $templateProcessor->setValue('id_abit', htmlspecialchars($data['id_abit'] ?? ''));
    $templateProcessor->setValue('familiya', htmlspecialchars($data['familiya'] ?? ''));
    $templateProcessor->setValue('imya', htmlspecialchars($data['imya'] ?? ''));
    $templateProcessor->setValue('otchestvo', htmlspecialchars($data['otchestvo'] ?? ''));
    $templateProcessor->setValue('snils', htmlspecialchars($data['snils'] ?? ''));
    $templateProcessor->setValue('date_bd', date("d.m.Y", strtotime($data['date_bd']) ?? ''));
    $templateProcessor->setValue('phone', htmlspecialchars($data['phone'] ?? ''));
    $templateProcessor->setValue('document', htmlspecialchars($data['document'] ?? ''));
    if (!empty($data['ser_num']) && strlen($data['ser_num']) >= 9) {
        $seriya = substr($data['ser_num'], 0, 4); // Первые 4 цифры — серия
        $nomer = substr($data['ser_num'], 4); // Остальные — номер
        $seriya_nomer = "$seriya № $nomer";
    } else {
        $seriya_nomer = htmlspecialchars($data['ser_num'] ?? ''); // На случай ошибок
    }
    
    $templateProcessor->setValue('seriya_nomer', $seriya_nomer);    
    $templateProcessor->setValue('date_vidachi_doc', date("d.m.Y", strtotime($data['date_vidachi_doc'] ?? '')));
    $templateProcessor->setValue('kem_vidan_doc', htmlspecialchars($data['kem_vidan_doc'] ?? ''));
    $templateProcessor->setValue('polis', htmlspecialchars($data['polis'] ?? ''));
    $templateProcessor->setValue('komp_polis', htmlspecialchars($data['komp_polis'] ?? ''));
    $templateProcessor->setValue('email', htmlspecialchars($data['email'] ?? ''));
    $templateProcessor->setValue('num_doc_obr', htmlspecialchars($data['num_doc_obr'] ?? ''));
    $templateProcessor->setValue('date_doc_obr', date("d.m.Y", strtotime($data['date_doc_obr']) ?? ''));
    $templateProcessor->setValue('kem_doc_obr', htmlspecialchars($data['kem_doc_obr'] ?? ''));
    $templateProcessor->setValue('in_yaz', htmlspecialchars($data['in_yaz'] ?? ''));
    $templateProcessor->setValue('dr_in_yaz', htmlspecialchars($data['dr_in_yaz_title'], ENT_QUOTES, 'UTF-8' ?? ''));
    $templateProcessor->setValue('grazhdanstvo', htmlspecialchars($data['grazhdanstvo'] ?? ''));
    $templateProcessor->setValue('kat_grazhd', htmlspecialchars($data['kat_grazhd'] ?? ''));
    $templateProcessor->setValue('pervooch_priem', htmlspecialchars($data['pervooch_priem'] ?? ''));
    $templateProcessor->setValue('dostizheniya', htmlspecialchars($data['dostizheniya'] ?? ''));
    $templateProcessor->setValue('sr_ball_attest', htmlspecialchars($data['sr_ball_attest'] ?? ''));
    $templateProcessor->setValue('address_prozhiv', htmlspecialchars($data['address_prozhiv'] ?? ''));
    $templateProcessor->setValue('address_registr', htmlspecialchars($data['address_registr'] ?? ''));
    $templateProcessor->setValue('mesto_rozhd', htmlspecialchars($data['mesto_rozhd'] ?? ''));
    $templateProcessor->setValue('code', htmlspecialchars($data['code'] ?? ''));
    $templateProcessor->setValue('spec_title', htmlspecialchars($data['spec_title'] ?? ''));
    $templateProcessor->setValue('kategoriya', htmlspecialchars($data['kategoriya'] ?? ''));
    $templateProcessor->setValue('date', date("d.m.Y", strtotime($data['date'] ?? '')));

    // Обработка чекбоксов
    $templateProcessor->setValue('nuzhdayus_obzh', $data['obzh'] == 1 ? '☑' : '☐');
    $templateProcessor->setValue('ne_nuzhdayus_obzh', $data['obzh'] == 0 ? '☑' : '☐');
    $templateProcessor->setValue('ochnaya', $data['forma_obuch'] == 1 ? '☑' : '☐');
    $templateProcessor->setValue('zaochnaya', $data['forma_obuch'] == 2 ? '☑' : '☐');
    $templateProcessor->setValue('ochno-zaochnaya', $data['forma_obuch'] == 3 ? '☑' : '☐');
    $templateProcessor->setValue('nuzhdayus_ovz', $data['usl_ovz'] == 1 ? 'да' : 'нет');
    $templateProcessor->setValue('ne_nuzhdayus_ovz', $data['usl_ovz'] == 0 ? 'нет' : 'да');
    $templateProcessor->setValue('attestat', $data['doc_obr'] == 1 ? '☑' : '☐');
    $templateProcessor->setValue('diplom', $data['doc_obr'] == 2 ? '☑' : '☐');
    $templateProcessor->setValue('angl', $data['in_yaz'] == 1 ? '☑' : '☐');
    $templateProcessor->setValue('nemec', $data['in_yaz'] == 2 ? '☑' : '☐');
    $templateProcessor->setValue('franc', $data['in_yaz'] == 3 ? '☑' : '☐');
    $templateProcessor->setValue('risunok', $data['ispitanie'] == 1 ? '☑' : '☐');
    $templateProcessor->setValue('test', $data['ispitanie'] == 2 ? '☑' : '☐');
    $templateProcessor->setValue('empty_ispitanie', is_null($data['ispitanie']) ? '✓' : '');
    // Устанавливаем галочки в зависимости от значения class
    $templateProcessor->setValue('osnovnoe_obrazovanie', $data['class'] == 9 ? '☑' : '☐');
    $templateProcessor->setValue('srednee_obrazovanie', $data['class'] == 11 ? '☑' : '☐');

    // Определяем, какое поле отметить в зависимости от id специальности
    if ($data['id_prof_spec'] == 15) { // Если специальность с id = 15
        $templateProcessor->setValue('kontrolnye_cifry', '☐');
        $templateProcessor->setValue('dogovor_platnye', '☑');
    } else { // Для всех остальных специальностей
        $templateProcessor->setValue('kontrolnye_cifry', '☑');
        $templateProcessor->setValue('dogovor_platnye', '☐');
    }

    $templateProcessor->setValue('rabochie', $data['programm'] == 'Рабочие' ? '☑' : '☐');
    $templateProcessor->setValue('spec', $data['programm'] == 'Специалисты' ? '☑' : '☐');

    // Формируем имя файла
    $filename = "Заявление_абитуриента_" . $id_abit . "_" . $socr . ".docx";

    header("Content-Description: File Transfer");
    header("Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document");
    header("Content-Disposition: attachment; filename=" . urlencode($filename));
    header("Cache-Control: max-age=0");

    ob_clean();
    flush();
    $templateProcessor->saveAs('php://output');
    exit;
}
?>

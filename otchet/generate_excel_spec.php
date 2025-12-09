<?php
// Подключение библиотеки PhpSpreadsheet
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Подключение к базе данных
$host = 'localhost';
$user = 'root';
$password = 'root';
$dbname = 'pk_2025';
$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

if (!isset($_GET['spec_id']) || empty($_GET['spec_id'])) {
    die("Не выбрана специальность!");
}

$spec_id = (int) $_GET['spec_id'];

$sqlSpec = "SELECT title, socr FROM profec_spec WHERE id_prof_spec = ?";
$stmtSpec = $conn->prepare($sqlSpec);
$stmtSpec->bind_param("i", $spec_id);
$stmtSpec->execute();
$resultSpec = $stmtSpec->get_result();

if ($resultSpec->num_rows === 0) {
    die("Ошибка: специальность не найдена.");
}

$specData = $resultSpec->fetch_assoc();
$specTitle = $specData['title'];
$specShort = $specData['socr'] ?: "spec_$spec_id";

$sql = "
SELECT 
    abit.id_abit, 
    abit.familiya, 
    abit.imya, 
    abit.otchestvo, 
    abit.phone, 
    abit.date_bd,
    adress_prozhiv.oblast, 
    adress_prozhiv.gorod, 
    adress_prozhiv.ulica, 
    adress_prozhiv.dom, 
    adress_prozhiv.korpus, 
    adress_prozhiv.kv, 
    adress_prozhiv.indecs 
FROM abit
JOIN zayav ON abit.id_abit = zayav.id_abitur
JOIN adress_prozhiv ON abit.adress_prozhiv = adress_prozhiv.id_adr_prozh
WHERE zayav.id_spec_prof = ?
ORDER BY abit.id_abit ASC;
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $spec_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Нет данных по выбранной специальности.");
}

// Создание Excel-файла
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Заголовки таблицы (добавлен "№")
$headers = ['№', 'ID абитуриента', 'Фамилия', 'Имя', 'Отчество', 'Телефон', 'Дата рождения', 'Адрес проживания'];
$sheet->fromArray([$headers], NULL, 'A1');

// Стиль заголовка
$headerStyle = [
    'font' => ['bold' => true],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'FFFF00'], // Желтый цвет
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000'],
        ],
    ],
];

// Применяем стиль к заголовку
$sheet->getStyle('A1:H1')->applyFromArray($headerStyle);

// Заполняем таблицу данными
$rowNum = 2;
$orderNum = 1; // Начинаем нумерацию с 1

while ($row = $result->fetch_assoc()) {
    $addressParts = [];
    if (!empty($row['oblast'])) $addressParts[] = "{$row['oblast']} обл.";
    if (!empty($row['gorod'])) $addressParts[] = "г. {$row['gorod']}";
    if (!empty($row['ulica'])) $addressParts[] = "ул. {$row['ulica']}";
    if (!empty($row['dom'])) $addressParts[] = "д. {$row['dom']}";
    if (!empty($row['korpus'])) $addressParts[] = "корп. {$row['korpus']}";
    if (!empty($row['kv'])) $addressParts[] = "кв. {$row['kv']}";
    if (!empty($row['indecs'])) $addressParts[] = "индекс: {$row['indecs']}";

    $fullAddress = implode(", ", $addressParts);

    // Добавляем порядковый номер
    $data = [
        $orderNum, // Новый столбец "№"
        $row['id_abit'],
        $row['familiya'],
        $row['imya'],
        $row['otchestvo'],
        $row['phone'],
        date('d.m.Y', strtotime($row['date_bd'])),
        $fullAddress
    ];

    $sheet->fromArray([$data], NULL, "A$rowNum");
    $rowNum++;
    $orderNum++; // Увеличиваем порядковый номер
}

// Автоширина колонок
foreach (range('A', 'H') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Добавляем границы ко всей таблице
$lastRow = $rowNum - 1;
$tableStyle = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000'],
        ],
    ],
];
$sheet->getStyle("A1:H$lastRow")->applyFromArray($tableStyle);

// Генерируем имя файла
$filename = "Абитуриенты_{$specShort}.xlsx";

// Устанавливаем заголовки для скачивания
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();
?>

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

// Проверка подключения
if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

// SQL-запрос для получения данных абитуриентов с адресами проживания
$sql = "
SELECT 
    abit.id_abit, 
    abit.familiya, 
    abit.imya, 
    abit.otchestvo, 
    abit.phone, 
    adress_prozhiv.oblast, 
    adress_prozhiv.gorod, 
    adress_prozhiv.ulica, 
    adress_prozhiv.korpus, 
    adress_prozhiv.dom, 
    adress_prozhiv.kv, 
    adress_prozhiv.indecs 
FROM abit
JOIN adress_prozhiv ON abit.adress_prozhiv = adress_prozhiv.id_adr_prozh
ORDER BY abit.id_abit ASC;
";

$result = $conn->query($sql);

if (!$result) {
    die("Ошибка запроса: " . $conn->error);
}

// Создание нового Excel-файла
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Заголовки таблицы с добавленным столбцом №
$headers = ['№', 'ID абитуриента', 'Фамилия', 'Имя', 'Отчество', 'Телефон', 'Адрес проживания'];
$sheet->fromArray([$headers], NULL, 'A1');

// Стиль заголовка
$headerStyle = [
    'font' => [
        'bold' => true,
    ],
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
$sheet->getStyle('A1:G1')->applyFromArray($headerStyle);

// Заполняем таблицу данными из БД
$rowNum = 2;
$index = 1; // Порядковый номер

while ($row = $result->fetch_assoc()) {
    // Формируем полный адрес
    $fullAddress = trim(
        "{$row['oblast']} обл., г. {$row['gorod']}, ул. {$row['ulica']}, "
        . "д. {$row['dom']}, "
        . (!empty($row['korpus']) ? "корп. {$row['korpus']}, " : "")
        . (!empty($row['kv']) ? "кв. {$row['kv']}, " : "")
        . "индекс: {$row['indecs']}"
    );

    $data = [
        $index++, // Добавляем порядковый номер
        $row['id_abit'],
        $row['familiya'],
        $row['imya'],
        $row['otchestvo'],
        $row['phone'],
        $fullAddress
    ];
    $sheet->fromArray([$data], NULL, "A$rowNum");
    $rowNum++;
}

// Устанавливаем автоширину для всех колонок
foreach (range('A', 'G') as $col) {
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
$sheet->getStyle("A1:G$lastRow")->applyFromArray($tableStyle);

// Скачивание Excel-файла
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Абитуриенты общий.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();
?>

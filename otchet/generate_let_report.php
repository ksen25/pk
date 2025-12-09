<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;

$host = 'localhost';
$user = 'root';
$password = 'root';
$dbname = 'pk_2025';
$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0); // Удаляем дефолтный лист

// Заголовки таблицы
$headers = [
    '№', 'ID', 'Фамилия', 'Имя', 'Отчество', 
    'СНИЛС', 'Дата рождения', 'Комментарий',
    'Дата заявления', 'Оригинал', 'Специальность'
];

// Стиль для заголовков (желтая шапка)
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => '000000']
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'FFFF00'] // Желтый цвет
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000']
        ]
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
        'wrapText' => true
    ]
];

// Стиль для оригиналов
$originalStyle = [
    'font' => [
        'bold' => true, 
        'color' => ['rgb' => '00AA00']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER
    ]
];

// Стиль для обычных ячеек
$cellStyle = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000']
        ]
    ],
    'alignment' => [
        'vertical' => Alignment::VERTICAL_CENTER,
        'wrapText' => true
    ]
];

// Запрос для получения специальностей
$sqlSpecs = "
SELECT DISTINCT 
    ps.id_prof_spec,
    ps.socr as sheet_name,
    CONCAT(ps.title, ' (', ps.socr, ')') as spec_title
FROM 
    zayav z
JOIN abit a ON z.id_abitur = a.id_abit
JOIN profec_spec ps ON z.id_spec_prof = ps.id_prof_spec
WHERE 
    z.comment IS NOT NULL 
    AND (
        z.comment LIKE '%лет%' 
        OR z.comment LIKE '%Лет%'
        OR z.comment LIKE '%ЛЕТ%'
    )
ORDER BY 
    ps.socr
";

$resultSpecs = $conn->query($sqlSpecs);

if ($resultSpecs->num_rows > 0) {
    while ($spec = $resultSpecs->fetch_assoc()) {
        // Создаем лист с именем = сокращение специальности
        $sheetName = preg_replace('/[\/\\\?*\[\]:]/', '', $spec['sheet_name']);
        $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet(
            $spreadsheet, 
            substr($sheetName, 0, 31)
        );
        $spreadsheet->addSheet($sheet);
        $sheet = $spreadsheet->getSheetByName($sheet->getTitle());
        
        // Заголовки
        $sheet->fromArray($headers, NULL, 'A1');
        
        // Применяем стиль к заголовкам
        $sheet->getStyle('A1:K1')->applyFromArray($headerStyle);
        
        // Фиксируем шапку
        $sheet->freezePane('A2');

        // Запрос для абитуриентов по специальности
        $sqlAbit = "
        SELECT 
            a.id_abit, 
            a.familiya, 
            a.imya, 
            a.otchestvo,
            a.snils,
            a.date_bd,
            z.comment,
            z.date as zayav_date,
            z.original,
            CONCAT(ps.title, ' (', ps.socr, ')') as spec_title
        FROM 
            zayav z
        JOIN abit a ON z.id_abitur = a.id_abit
        JOIN profec_spec ps ON z.id_spec_prof = ps.id_prof_spec
        WHERE 
            z.comment IS NOT NULL 
            AND (
                z.comment LIKE '%лет%' 
                OR z.comment LIKE '%Лет%'
                OR z.comment LIKE '%ЛЕТ%'
            )
            AND z.id_spec_prof = {$spec['id_prof_spec']}
        ORDER BY 
            a.familiya, a.imya, a.otchestvo, z.date DESC
        ";

        $resultAbit = $conn->query($sqlAbit);
        
        if ($resultAbit && $resultAbit->num_rows > 0) {
            $rowNumber = 2;
            $counter = 1;
            
            while ($row = $resultAbit->fetch_assoc()) {
                $sheet->setCellValue('A'.$rowNumber, $counter);
                $sheet->setCellValue('B'.$rowNumber, $row['id_abit']);
                $sheet->setCellValue('C'.$rowNumber, $row['familiya']);
                $sheet->setCellValue('D'.$rowNumber, $row['imya']);
                $sheet->setCellValue('E'.$rowNumber, $row['otchestvo']);
                $sheet->setCellValue('F'.$rowNumber, $row['snils']);
                $sheet->setCellValue('G'.$rowNumber, date('d.m.Y', strtotime($row['date_bd'])));
                $sheet->setCellValue('H'.$rowNumber, $row['comment']);
                $sheet->setCellValue('I'.$rowNumber, $row['zayav_date'] ? date('d.m.Y', strtotime($row['zayav_date'])) : '');
                $sheet->setCellValue('J'.$rowNumber, $row['original'] ? 'Да' : 'Нет');
                $sheet->setCellValue('K'.$rowNumber, $row['spec_title']);
                
                // Применяем стиль к строке
                $sheet->getStyle('A'.$rowNumber.':K'.$rowNumber)->applyFromArray($cellStyle);
                
                // Подсветка оригиналов
                if ($row['original']) {
                    $sheet->getStyle('J'.$rowNumber)->applyFromArray($originalStyle);
                }
                
                $rowNumber++;
                $counter++;
            }
            
            // Автоподбор ширины столбцов с минимальной шириной
            foreach(range('A','K') as $col) {
                $sheet->getColumnDimension($col)
                    ->setAutoSize(true)
                    ->setWidth(15); // Минимальная ширина
            }
            
            // Особые настройки для некоторых столбцов
            $sheet->getColumnDimension('A')->setWidth(5);  // №
            $sheet->getColumnDimension('B')->setWidth(8);  // ID
            $sheet->getColumnDimension('G')->setWidth(12); // Дата рождения
            $sheet->getColumnDimension('I')->setWidth(12); // Дата заявления
            $sheet->getColumnDimension('J')->setWidth(10); // Оригинал
            
            // Разделитель страниц после каждых 40 строк
            for ($i = 41; $i < $rowNumber; $i += 40) {
                $sheet->setBreak('A'.$i, \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::BREAK_ROW);
            }
            
        } else {
            $sheet->mergeCells('A2:K2');
            $sheet->setCellValue('A2', 'Нет заявлений с комментариями, содержащими "лет"');
            $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
    }
} else {
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle('Отчет');
    $sheet->mergeCells('A1:K1');
    $sheet->setCellValue('A1', 'Нет заявлений с комментариями, содержащими "лет"');
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

// Удаляем первый пустой лист, если он есть
if ($spreadsheet->getSheetCount() > 1 && $spreadsheet->getSheet(0)->getHighestRow() == 1) {
    $spreadsheet->removeSheetByIndex(0);
}

// Отправка файла
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="Отчет_Прошлых_лет_'.date('Y-m-d').'.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
$conn->close();
?>
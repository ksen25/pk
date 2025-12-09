<?php
require 'vendor/autoload.php';

$host = 'localhost';
$user = 'root';
$password = 'root';
$dbname = 'pk_2025';
$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Создаем новый Excel-документ
$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0); // Удаляем дефолтный лист

// Получаем данные для МД9
$md = getPravoohranSimpleData($conn, 'МД9');
createSimpleSheet($spreadsheet, 'МД9', $md);

// Получаем данные для РК
$rk = getPravoohranSimpleData($conn, 'РК');
createSimpleSheet($spreadsheet, 'РК', $rk);

// Отправляем файл пользователю
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="Списки_мд_рк.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

// Функция для получения данных с сортировкой как в PDF
function getPravoohranSimpleData($conn, $type) {
    $sql = "SELECT 
                abit.id_abit, 
                CONCAT(abit.familiya, ' ', abit.imya, ' ', abit.otchestvo) as fio,
                abit.sr_ball_attest as avg_score,
                abit.date_bd as birth_date,
                MAX(zayav.original) as has_original,
                abit.pervooch_priem,
                CASE 
                    WHEN abit.pervooch_priem IS NOT NULL AND 
                         (LOWER(TRIM(BOTH ' ' FROM abit.pervooch_priem)) = 'сво' OR
                          LOWER(TRIM(BOTH ' ' FROM abit.pervooch_priem)) LIKE '%ребенок ветерана%' OR
                          LOWER(TRIM(BOTH ' ' FROM abit.pervooch_priem)) LIKE '%ребёнок ветерана%' OR
                          LOWER(TRIM(BOTH ' ' FROM abit.pervooch_priem)) LIKE '%ветеран боевых действий%') THEN 1
                    ELSE 0
                END AS is_priority
            FROM 
                abit
            JOIN zayav ON abit.id_abit = zayav.id_abitur
            JOIN profec_spec ON zayav.id_spec_prof = profec_spec.id_prof_spec
            WHERE 
                profec_spec.socr = ?
            GROUP BY 
                abit.id_abit, abit.familiya, abit.imya, abit.otchestvo, 
                abit.sr_ball_attest, abit.date_bd, abit.pervooch_priem
            ORDER BY 
                MAX(zayav.original) DESC,
                is_priority DESC,
                abit.familiya ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $type);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Функция для создания листа с сортировкой как в PDF
function createSimpleSheet($spreadsheet, $sheetName, $data) {
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle($sheetName);
    
    // Заголовки
    $sheet->setCellValue('A1', '№');
    $sheet->setCellValue('B1', 'ID');
    $sheet->setCellValue('C1', 'ФИО');
    $sheet->setCellValue('D1', 'Дата рождения');
    $sheet->setCellValue('E1', 'Средний балл');
    $sheet->setCellValue('F1', 'Документы');
    $sheet->setCellValue('G1', 'Льгота (СВО/ветеран)');
    
    // Цвета для оформления
    $originalColor = 'FFFF99'; // Желтый для оригинала
    $priorityColor = 'BFFCFF'; // Голубой для льготников
    
    // Заполняем данными
    $row = 2;
    foreach ($data as $item) {
        $sheet->setCellValue('A'.$row, $row-1);
        $sheet->setCellValue('B'.$row, $item['id_abit']);
        $sheet->setCellValue('C'.$row, $item['fio']);
        
        // Форматируем дату рождения
        $birthDate = !empty($item['birth_date']) ? date('d.m.Y', strtotime($item['birth_date'])) : '';
        $sheet->setCellValue('D'.$row, $birthDate);
        
        $sheet->setCellValue('E'.$row, $item['avg_score'] ? round($item['avg_score'], 2) : 'Нет данных');
        $sheet->setCellValue('F'.$row, $item['has_original'] == 1 ? 'Оригинал' : 'Копия');
        $sheet->setCellValue('G'.$row, $item['is_priority'] == 1 ? '✓' : '');
        
        // Устанавливаем цвет фона для оригинала
        if ($item['has_original'] == 1) {
            $sheet->getStyle('A'.$row.':G'.$row)
                  ->getFill()
                  ->setFillType(Fill::FILL_SOLID)
                  ->getStartColor()
                  ->setARGB($originalColor);
        }
        
        // Устанавливаем цвет фона для льготников (перекрывает цвет оригинала)
        if ($item['is_priority'] == 1) {
            $sheet->getStyle('A'.$row.':G'.$row)
                  ->getFill()
                  ->setFillType(Fill::FILL_SOLID)
                  ->getStartColor()
                  ->setARGB($priorityColor);
        }
        
        $row++;
    }
    
    // Автоширина колонок
    foreach(range('A','G') as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }
    
    // Формат даты для столбца с датой рождения
    $sheet->getStyle('D2:D'.$sheet->getHighestRow())
          ->getNumberFormat()
          ->setFormatCode('dd.mm.yyyy');
    
    // Формат чисел для среднего балла (2 знака после запятой)
    $sheet->getStyle('E2:E'.$sheet->getHighestRow())
          ->getNumberFormat()
          ->setFormatCode('0.00');
    
    // Основные стили для всей таблицы
    $tableStyle = [
        'font' => [
            'size' => 10,
            'name' => 'Arial'
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['argb' => 'FF000000'],
            ],
        ],
        'alignment' => [
            'vertical' => 'center'
        ]
    ];

    $lastRow = count($data) + 1;
    $sheet->getStyle('A1:G'.$lastRow)->applyFromArray($tableStyle);
    
    // Стили для заголовков
    $headerStyle = [
        'font' => [
            'bold' => true,
            'size' => 10
        ],
        'alignment' => [
            'horizontal' => 'center',
            'vertical' => 'center'
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID, 
            'startColor' => ['rgb' => 'D9D9D9']
        ]
    ];
    
    $sheet->getStyle('A1:G1')->applyFromArray($headerStyle);
    
    // Центрирование для числовых полей
    $sheet->getStyle('A2:A'.$lastRow)->getAlignment()->setHorizontal('center');
    $sheet->getStyle('B2:B'.$lastRow)->getAlignment()->setHorizontal('center');
    $sheet->getStyle('E2:G'.$lastRow)->getAlignment()->setHorizontal('center');
    
    // Устанавливаем высоту строки для заголовков
    $sheet->getRowDimension(1)->setRowHeight(20);
    
    // Добавляем красную линию для МД9 после 25 строки
    if ($sheetName === 'МД9') {
        $redLineRow = 27; // 25 строк данных + заголовок + 1
        
        // Устанавливаем стиль для красной линии
        $sheet->getStyle('A'.$redLineRow.':G'.$redLineRow)
              ->getBorders()
              ->getTop()
              ->setBorderStyle(Border::BORDER_THICK)
              ->setColor(new Color(Color::COLOR_RED));
    }
}
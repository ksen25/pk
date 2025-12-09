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

// Создаем новый Excel-документ
$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0); // Удаляем дефолтный лист

// Получаем данные для ПР9 (после 9 класса)
$pr9 = getPravoohranSimpleData($conn, 'ПР9');
createSimpleSheet($spreadsheet, 'ПР9', $pr9);

// Получаем данные для ПР11 (после 11 класса)
$pr11 = getPravoohranSimpleData($conn, 'ПР11');
createSimpleSheet($spreadsheet, 'ПР11', $pr11);

// Отправляем файл пользователю
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="Списки_право.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

// Функция для получения данных с датой рождения
function getPravoohranSimpleData($conn, $type) {
    $sql = "SELECT 
                abit.id_abit, 
                CONCAT(abit.familiya, ' ', abit.imya, ' ', abit.otchestvo) as fio,
                abit.sr_ball_attest as avg_score,
                abit.date_bd as birth_date,
                MAX(zayav.original) as has_original
            FROM 
                abit
            JOIN zayav ON abit.id_abit = zayav.id_abitur
            JOIN profec_spec ON zayav.id_spec_prof = profec_spec.id_prof_spec
            WHERE 
                profec_spec.socr = ?
            GROUP BY 
                abit.id_abit, abit.familiya, abit.imya, abit.otchestvo, abit.sr_ball_attest, abit.date_bd
            ORDER BY 
                MAX(zayav.original) DESC, abit.familiya, abit.imya, abit.otchestvo";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $type);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Функция для создания листа с датой рождения
function createSimpleSheet($spreadsheet, $sheetName, $data) {
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle($sheetName);
    
    // Заголовки (добавлен столбец с датой рождения)
    $sheet->setCellValue('A1', '№');
    $sheet->setCellValue('B1', 'ID');
    $sheet->setCellValue('C1', 'ФИО');
    $sheet->setCellValue('D1', 'Дата рождения');
    $sheet->setCellValue('E1', 'Средний балл');
    $sheet->setCellValue('F1', 'Документы');
    
    // Разделяем данные на оригиналы и копии
    $originals = [];
    $copies = [];
    
    foreach ($data as $item) {
        if ($item['has_original'] == 1) {
            $originals[] = $item;
        } else {
            $copies[] = $item;
        }
    }
    
    // Сортируем обе группы по алфавиту
    usort($originals, function($a, $b) {
        return strcmp($a['fio'], $b['fio']);
    });
    
    usort($copies, function($a, $b) {
        return strcmp($a['fio'], $b['fio']);
    });
    
    // Объединяем массивы - сначала оригиналы, потом копии
    $sortedData = array_merge($originals, $copies);
    
    // Заполняем данными
    $row = 2;
    foreach ($sortedData as $item) {
        $sheet->setCellValue('A'.$row, $row-1); // Порядковый номер
        $sheet->setCellValue('B'.$row, $item['id_abit']); // ID абитуриента
        $sheet->setCellValue('C'.$row, $item['fio']);
        
        // Форматируем дату рождения
        $birthDate = !empty($item['birth_date']) ? date('d.m.Y', strtotime($item['birth_date'])) : '';
        $sheet->setCellValue('D'.$row, $birthDate);
        
        $sheet->setCellValue('E'.$row, $item['avg_score'] ?: 'Нет данных');
        $sheet->setCellValue('F'.$row, $item['has_original'] == 1 ? 'Оригинал' : 'Копия');
        $row++;
    }
    
    // Добавляем разделительную строку между оригиналами и копиями, если есть обе группы
    if (!empty($originals) && !empty($copies)) {
        $dividerRow = count($originals) + 2;
        $sheet->insertNewRowBefore($dividerRow, 1);
        $sheet->mergeCells('A'.$dividerRow.':F'.$dividerRow);
        $sheet->setCellValue('A'.$dividerRow, 'Копии документов');
        $sheet->getStyle('A'.$dividerRow)->getAlignment()->setHorizontal('center');
        $sheet->getStyle('A'.$dividerRow)->getFont()->setBold(true);
        
        // Обновляем нумерацию после вставки разделителя
        $num = 1;
        for ($i = 2; $i <= $sheet->getHighestRow(); $i++) {
            if ($sheet->getCell('C'.$i)->getValue() && $sheet->getCell('A'.$i)->getValue() != 'Копии документов') {
                $sheet->setCellValue('A'.$i, $num++);
            }
        }
    }
    
    // Автоширина колонок
    foreach(range('A','F') as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }
    
    // Формат даты для столбца с датой рождения
    $sheet->getStyle('D2:D'.$sheet->getHighestRow())
          ->getNumberFormat()
          ->setFormatCode('dd.mm.yyyy');
    
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
    
    $lastRow = count($sortedData) + (!empty($originals) && !empty($copies) ? 2 : 1);
    $sheet->getStyle('A1:F'.$lastRow)->applyFromArray($tableStyle);
    
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
            'fillType' => 'solid', 
            'startColor' => ['rgb' => 'D9D9D9']
        ]
    ];
    
    $sheet->getStyle('A1:F1')->applyFromArray($headerStyle);
    
    // Центрирование для числовых полей
    $sheet->getStyle('A2:A'.$lastRow)->getAlignment()->setHorizontal('center');
    $sheet->getStyle('B2:B'.$lastRow)->getAlignment()->setHorizontal('center');
    $sheet->getStyle('E2:F'.$lastRow)->getAlignment()->setHorizontal('center');
    
    // Устанавливаем высоту строки для заголовков
    $sheet->getRowDimension(1)->setRowHeight(20);
    
    // Устанавливаем высоту строки для разделителя
    if (!empty($originals) && !empty($copies)) {
        $sheet->getRowDimension($dividerRow)->setRowHeight(20);
    }
}

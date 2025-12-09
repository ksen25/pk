<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'localhost';
$user = 'root';
$password = 'root';
$dbname = 'pk_2025';

try {
    $conn = new mysqli($host, $user, $password, $dbname);
    if ($conn->connect_error) {
        throw new Exception("Ошибка подключения: " . $conn->connect_error);
    }

    // Создаем документ
    $spreadsheet = new Spreadsheet();
    $spreadsheet->removeSheetByIndex(0); // Удаляем дефолтный лист

    // Заголовки таблицы
    $headers = [
        '№', 'ID', 'Фамилия', 'Имя', 'Отчество', 
        'СНИЛС', 'Дата рождения', 'Тип документа об образовании',
        'Дата заявления', 'Оригинал', 'Специальность'
    ];

    // Стиль для заголовков
    $headerStyle = [
        'font' => ['bold' => true],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'FFFF00']
        ],
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN]
        ]
    ];

    // Получаем список всех специальностей с абитуриентами с дипломом
    $sqlSpecs = "
    SELECT DISTINCT 
        ps.id_prof_spec,
        ps.socr as sheet_name,
        CONCAT(ps.title, ' (', ps.socr, ')') as spec_title
    FROM 
        abit a
    JOIN zayav z ON a.id_abit = z.id_abitur
    JOIN profec_spec ps ON z.id_spec_prof = ps.id_prof_spec
    WHERE 
        a.id_doc_obr = 2  -- Фильтр по диплому
    ORDER BY 
        ps.socr
    ";

    $resultSpecs = $conn->query($sqlSpecs);
    if (!$resultSpecs) {
        throw new Exception("Ошибка запроса специальностей: " . $conn->error);
    }

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
            
            // Записываем заголовки
            $sheet->fromArray($headers, NULL, 'A1');
            $sheet->getStyle('A1:K1')->applyFromArray($headerStyle);

            // Запрос для абитуриентов с дипломом для текущей специальности
            $sqlAbit = "
            SELECT 
                a.id_abit, 
                a.familiya, 
                a.imya, 
                a.otchestvo,
                a.snils,
                a.date_bd,
                'Диплом' as doc_type,
                z.date as zayav_date,
                z.original,
                CONCAT(ps.title, ' (', ps.socr, ')') as spec_title
            FROM 
                abit a
            JOIN zayav z ON a.id_abit = z.id_abitur
            JOIN profec_spec ps ON z.id_spec_prof = ps.id_prof_spec
            WHERE 
                a.id_doc_obr = 2  -- Фильтр по диплому
                AND z.id_spec_prof = {$spec['id_prof_spec']}
            ORDER BY 
                a.familiya, a.imya, a.otchestvo
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
                    $sheet->setCellValue('H'.$rowNumber, $row['doc_type']);
                    $sheet->setCellValue('I'.$rowNumber, $row['zayav_date'] ? date('d.m.Y', strtotime($row['zayav_date'])) : '');
                    $sheet->setCellValue('J'.$rowNumber, $row['original'] ? 'Да' : 'Нет');
                    $sheet->setCellValue('K'.$rowNumber, $row['spec_title']);
                    
                    // Стиль для строк
                    $sheet->getStyle('A'.$rowNumber.':K'.$rowNumber)
                        ->getBorders()
                        ->getAllBorders()
                        ->setBorderStyle(Border::BORDER_THIN);
                        
                    $rowNumber++;
                    $counter++;
                }
                
                // Автоподбор ширины
                foreach(range('A','K') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }
            } else {
                $sheet->mergeCells('A2:K2');
                $sheet->setCellValue('A2', 'Нет абитуриентов с дипломом для этой специальности');
                $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
        }
    } else {
        // Если нет данных
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Отчет');
        $sheet->mergeCells('A1:K1');
        $sheet->setCellValue('A1', 'Нет абитуриентов с дипломом');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    // Устанавливаем первый лист активным
    $spreadsheet->setActiveSheetIndex(0);

    // Отправка файла
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Отчет_по_дипломам_'.date('Y-m-d').'.xlsx"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');

} catch (Exception $e) {
    die("Ошибка: " . $e->getMessage());
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
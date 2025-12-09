<?php
// Включение отображения ошибок и настройка памяти
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(0);

// Убедимся, что нет лишнего вывода
ob_start();

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

// Подключение к базе данных
$host = 'localhost';
$user = 'root';
$password = 'root';
$dbname = 'pk_2025';
$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

// Вспомогательные функции
function getDocumentType($conn, $docId) {
    if (empty($docId)) return '';
    $stmt = $conn->prepare("SELECT title FROM doc_obr WHERE id_doc_obr = ?");
    $stmt->bind_param("i", $docId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc()['title'] ?? '';
}

function checkIssueMark($conn, $abitId) {
    // Ищем отметку "Документы выданы ..." в issue_note
    $stmt = $conn->prepare("SELECT issue_note FROM zayav WHERE id_abitur = ? AND issue_note LIKE 'Документы выданы%' ORDER BY date DESC LIMIT 1");
    $stmt->bind_param("i", $abitId);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    if (!empty($res['issue_note'])) {
        return $res['issue_note'];
    }

    // Фолбэк: если заявлений нет, ставим дефолтную отметку
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM zayav WHERE id_abitur = ?");
    $stmt->bind_param("i", $abitId);
    $stmt->execute();
    return ($stmt->get_result()->fetch_assoc()['cnt'] == 0) ? 'Документы выданы' : '';
}


function filterComment($conn, $abitId) {
    // Берём самое свежее примечание из comment (без отметок о выдаче, т.к. они в issue_note)
    $stmt = $conn->prepare("SELECT comment FROM zayav WHERE id_abitur = ? AND comment IS NOT NULL ORDER BY date DESC LIMIT 1");
    $stmt->bind_param("i", $abitId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row['comment'] ?? '';
}

// Создание Excel-файла
$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0);

// Получаем список всех специальностей
$sqlAllSpecs = "SELECT id_prof_spec, title, socr FROM profec_spec ORDER BY title";
$resultAllSpecs = $conn->query($sqlAllSpecs);

while ($spec = $resultAllSpecs->fetch_assoc()) {
    $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, $spec['socr'] ?: 'spec_'.$spec['id_prof_spec']);
    $spreadsheet->addSheet($sheet);
    $spreadsheet->setActiveSheetIndexByName($spec['socr'] ?: 'spec_'.$spec['id_prof_spec']);
    
    // Настройки страницы
    $sheet->getPageSetup()
        ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(PageSetup::PAPERSIZE_A4);
    
    // Заголовки таблицы
    $headers = [
        '№', '№ абитуриента', 'ФИО', 'Телефон', 'Социальный статус',
        'Образовательное учреждение', 'Год окончания', 'Средний балл',
        'Аттестат/диплом', 'Нужда в общежитии', 'Отметка о выдаче документов', 'Примечание'
    ];
    
    $sheet->fromArray([$headers], NULL, 'A1');
    
    // Стиль заголовков
    $headerStyle = [
        'font' => ['bold' => true, 'size' => 10],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFF00']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'alignment' => ['wrapText' => true, 'vertical' => 'center']
    ];
    $sheet->getStyle('A1:L1')->applyFromArray($headerStyle);
    
    // Получаем данные абитуриентов
    $sql = "SELECT abit.* FROM abit JOIN zayav ON abit.id_abit = zayav.id_abitur WHERE zayav.id_spec_prof = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $spec['id_prof_spec']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $rowNum = 2;
    $orderNum = 1;
    
    while ($row = $result->fetch_assoc()) {
        // Формирование данных строки
        $data = [
            $orderNum,
            $row['id_abit'],
            trim($row['familiya'].' '.$row['imya'].' '.$row['otchestvo']),
            $row['phone'],
            (strtolower($row['kat_grazhd']) == 'нет' && strtolower($row['pervooch_priem']) == 'нет') ? 
                '-' : implode(', ', array_filter([$row['kat_grazhd'], $row['pervooch_priem']])),
            $row['kem_doc_obr'],
            !empty($row['date_doc_obr']) ? date('Y', strtotime($row['date_doc_obr'])) : '',
            $row['sr_ball_attest'],
            getDocumentType($conn, $row['id_doc_obr']),
            $row['obzh'] == 1 ? 'Да' : '',
            checkIssueMark($conn, $row['id_abit']),
            filterComment($conn, $row['id_abit'])
        ];
        
        $sheet->fromArray([$data], NULL, "A$rowNum");
        
        // Стиль для строки
        $sheet->getStyle("A$rowNum:L$rowNum")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'font' => ['size' => 9],
            'alignment' => ['vertical' => 'center']
        ]);
        
        $rowNum++;
        $orderNum++;
    }
    
    // Настройка ширины столбцов
    $sheet->getColumnDimension('A')->setWidth(5);
    $sheet->getColumnDimension('B')->setWidth(10);
    $sheet->getColumnDimension('C')->setWidth(25);
    $sheet->getColumnDimension('D')->setWidth(15);
    $sheet->getColumnDimension('E')->setWidth(20);
    $sheet->getColumnDimension('F')->setWidth(25);
    $sheet->getColumnDimension('G')->setWidth(12);
    $sheet->getColumnDimension('H')->setWidth(12);
    $sheet->getColumnDimension('I')->setWidth(20);
    $sheet->getColumnDimension('J')->setWidth(15);
    $sheet->getColumnDimension('K')->setWidth(15);
    $sheet->getColumnDimension('L')->setWidth(15);
}

// Удаляем первый лист если создались дополнительные
$firstSheet = $spreadsheet->getSheet(0);
if ($firstSheet->getTitle() == 'Worksheet' && $spreadsheet->getSheetCount() > 1) {
    $spreadsheet->removeSheetByIndex(0);
}
// Очищаем буфер и отправляем файл
ob_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Абитуриенты_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
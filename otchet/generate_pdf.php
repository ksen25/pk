<?php
require_once __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php';
// Установка кодировки
mb_internal_encoding('UTF-8');

// Подключение к БД
$host = 'localhost';
$user = 'root';
$password = 'root';
$dbname = 'pk_2025';

$conn = new mysqli($host, $user, $password, $dbname);
if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}
$conn->set_charset('utf8mb4'); // Используем utf8mb4 для полной поддержки Unicode

// Получаем выбранную специальность
if (!isset($_GET['spec_id']) || empty($_GET['spec_id'])) {
    die("Ошибка: специальность не выбрана.");
}
$spec_id = (int)$_GET['spec_id'];

// Запрос названия специальности
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

// Улучшенный запрос данных абитуриентов с сортировкой по фамилии при одинаковом среднем балле
$sql = "
    SELECT 
        abit.familiya, 
        abit.imya, 
        abit.otchestvo, 
        abit.sr_ball_attest, 
        abit.pervooch_priem,
        zayav.original,
        CASE 
            WHEN abit.pervooch_priem IS NOT NULL AND 
                 (LOWER(TRIM(BOTH ' ' FROM abit.pervooch_priem)) = 'сво' OR
                  LOWER(TRIM(BOTH ' ' FROM abit.pervooch_priem)) LIKE '%ребенок ветерана%' OR
                  LOWER(TRIM(BOTH ' ' FROM abit.pervooch_priem)) LIKE '%ребёнок ветерана%' OR
                  LOWER(TRIM(BOTH ' ' FROM abit.pervooch_priem)) LIKE '%ветеран боевых действий%') THEN 1
            ELSE 0
        END AS is_priority
    FROM abit
    JOIN zayav ON abit.id_abit = zayav.id_abitur
    WHERE zayav.id_spec_prof = ?
      AND (zayav.issue_note IS NULL OR zayav.issue_note NOT LIKE 'Документы выданы%')
    ORDER BY 
        zayav.original DESC,
        is_priority DESC,
        abit.sr_ball_attest DESC,
        abit.familiya ASC
";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Ошибка подготовки запроса: " . $conn->error);
}

$stmt->bind_param("i", $spec_id);
if (!$stmt->execute()) {
    die("Ошибка выполнения запроса: " . $stmt->error);
}

$result = $stmt->get_result();
if ($result->num_rows === 0) {
    die("Нет данных для выбранной специальности.");
}

// Создание PDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetTitle("Рейтинг абитуриентов - $specTitle");
$pdf->AddPage();

// Установка шрифта с поддержкой кириллицы
$pdf->SetFont('dejavusans', '', 10);

// Добавляем текущую дату в верхнем левом углу (меньший шрифт и выше)
$pdf->SetFont('dejavusans', '', 8);
$currentDate = date('d.m.Y'); // Формат 28.08.2025
$pdf->SetXY(10, 5); // Подняли выше (8 мм вместо 10)
$pdf->Cell(0, 0, $currentDate, 0, 0, 'L');

// Заголовок специальности (возвращаем нормальный шрифт)
$pdf->SetFont('dejavusans', 'B', 14);
// Добавляем отступ сверху, чтобы не накладывался на дату
$pdf->SetY(15); // Устанавливаем позицию Y явно
$pdf->MultiCell(0, 10, $specTitle . " (" . $specShort . ")", 0, 'C', false, 1, '', '', true, 0, false, true, 0);
$pdf->SetFont('dejavusans', '', 10);

// Получаем дату собрания, дату окончания приема и форму обучения из БД
$sqlMeeting = "SELECT ds.date_sobr, ds.time_sobr, do.date_okonch, ps.forma_obuch 
               FROM profec_spec ps 
               JOIN date_sobr ds ON ps.date_sobr = ds.id 
               LEFT JOIN date_okonch do ON ps.date_okonch = do.id
               WHERE ps.id_prof_spec = ?";
$stmtMeeting = $conn->prepare($sqlMeeting);
$stmtMeeting->bind_param("i", $spec_id);
$stmtMeeting->execute();
$resultMeeting = $stmtMeeting->get_result();

// Форматируем дату и текст собрания и зачисления
$meetingInfo = "";
$infoText = "";

if ($resultMeeting->num_rows > 0) {
    $meetingData = $resultMeeting->fetch_assoc();
    
    // Форматирование даты собрания
    $meetingDate = DateTime::createFromFormat('Y-m-d', $meetingData['date_sobr']);
    $formattedDate = $meetingDate ? $meetingDate->format('d.m.Y') : $meetingData['date_sobr'];
    $meetingTime = $meetingData['time_sobr'];
    
    // Текст собрания в зависимости от формы обучения
    switch($meetingData['forma_obuch']) {
        case 3: // Очно-заочная форма
            $meetingInfo = "Общее собрание студентов первого курса очно-заочной формы обучения состоится $formattedDate в $meetingTime в кабинете №212 (2 этаж)";
            break;
        case 2: // Заочная форма
            $meetingInfo = "Общее собрание студентов первого курса заочного отделения состоится $formattedDate в $meetingTime в актовом зале (1 этаж)";
            break;
        default: // Очная форма (1) и другие случаи
            $meetingInfo = "Собрание для первокурсников и одного из родителей (законного представителя), состоится $formattedDate в $meetingTime - актовый зал";
    }

    // Форматирование даты зачисления (только если date_okonch не NULL)
    if (!is_null($meetingData['date_okonch'])) {
        $okonchDate = DateTime::createFromFormat('Y-m-d', $meetingData['date_okonch']);
        $formattedOkonchDate = $okonchDate ? $okonchDate->format('d.m.Y') : $meetingData['date_okonch'];
        $infoText = "Зачисление на обучение проводится при предоставлении в приемную комиссию оригинала документа об образовании и (или) документа об образовании и о квалификации, а также документа, подтверждающего право преимущественного или первоочередного приёма в соответствии с частью 5.1 статьи 71 - $formattedOkonchDate до 16:00";
    } else {
        // Текст для форм обучения, где нет даты окончания приема
        $infoText = "Зачисление на обучение проводится при предоставлении в приемную комиссию оригинала документа об образовании и (или) документа об образовании и о квалификации, а также документа, подтверждающего право преимущественного или первоочередного приёма в соответствии с частью 5.1 статьи 71";
    }
} else {
    $meetingInfo = "Дата собрания для первокурсников будет объявлена позже";
    $infoText = "Дата зачисления будет объявлена позже";
}

// Настройки блока
$pdf->SetDrawColor(0, 0, 0);
$pdf->SetFillColor(255, 255, 255);
$pdf->SetLineWidth(0.3);

// Рассчитываем высоту блока (увеличена для лучшего отображения)
$blockHeight = 40;

// Рисуем прямоугольник (блок с границами)
$startY = $pdf->GetY();
$pdf->Rect(15, $startY, 180, $blockHeight, 'DF');

// Добавляем текст в блок
$pdf->SetXY(15, $startY + 5);

// Текст о зачислении (обычный цвет)
$pdf->MultiCell(180, 5, $infoText, 0, 'C');

// Текст о собрании (красный цвет)
$pdf->SetTextColor(255, 0, 0);
$pdf->SetX(15);
$pdf->MultiCell(180, 5, $meetingInfo, 0, 'C');
$pdf->SetTextColor(0, 0, 0);

// Перемещаем указатель после блока
$pdf->SetY($startY + $blockHeight + 10);
// Настройка таблицы
// Настройка таблицы (изменены ширины столбцов)
$columnWidths = [20, 70, 30, 35, 50]; // Увеличил последний столбец с 30 до 40
$leftMargin = (210 - array_sum($columnWidths)) / 2;

// Заголовок таблицы
$pdf->SetX($leftMargin);
$pdf->SetFillColor(200, 200, 200);
$pdf->Cell($columnWidths[0], 10, 'Место', 1, 0, 'C', true);
$pdf->Cell($columnWidths[1], 10, 'ФИО абитуриента', 1, 0, 'C', true);
$pdf->Cell($columnWidths[2], 10, 'Средний балл', 1, 0, 'C', true);
$pdf->Cell($columnWidths[3], 10, 'Оригинал/Копия', 1, 0, 'C', true);
$pdf->Cell($columnWidths[4], 10, 'Первоочерёдный приём', 1, 1, 'C', true);

// Цвета
$originalColor = [255, 255, 153]; // Желтый для оригинала
$svoColor = [191, 252, 255];     // Голубой для СВО

// ... предыдущий код без изменений до заполнения данных ...

// ... (предыдущий код без изменений до заполнения данных) ...

// Заполнение данных
$place = 1;
$lineDrawn = false;
$isMD9 = ($specShort === 'МД9'); // Проверяем, что это специальность МД9

while ($row = $result->fetch_assoc()) {
    $fio = $row['familiya'] . ' ' . $row['imya'] . ' ' . $row['otchestvo'];
    $original = $row['original'] == 1 ? 'Оригинал' : 'Копия';
    $isPriority = $row['is_priority'] == 1;
    
    // Установка цвета для оригинала (желтый)
    $fillColor = $row['original'] == 1 ? $originalColor : [255, 255, 255];
    
    $pdf->SetX($leftMargin);
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetFillColor($fillColor[0], $fillColor[1], $fillColor[2]);
    
    // Место
    $pdf->Cell($columnWidths[0], 10, $place, 1, 0, 'C', true);
    
    // ФИО
    $pdf->Cell($columnWidths[1], 10, $fio, 1, 0, 'L', true);
    
    // Средний балл
    $pdf->Cell($columnWidths[2], 10, $row['sr_ball_attest'], 1, 0, 'C', true);
    
    // Оригинал/Копия
    $pdf->Cell($columnWidths[3], 10, $original, 1, 0, 'C', true);
    
    // Льгота СВО
    $pdf->SetFillColor($svoColor[0], $svoColor[1], $svoColor[2]);
    $pdf->Cell($columnWidths[4], 10, $isPriority ? '✓' : '', 1, 1, 'C', true);

    // Для МД9: рисуем красную черту после 25 места (только один раз)
    if ($isMD9 && $place == 25 && !$lineDrawn) {
        $currentY = $pdf->GetY();
        $pdf->SetDrawColor(255, 0, 0); // Красный цвет
        $pdf->SetLineWidth(2); // Толщина линии
        $pdf->Line(
            $leftMargin, 
            $currentY, 
            $leftMargin + array_sum($columnWidths), 
            $currentY
        );
        $pdf->SetDrawColor(0, 0, 0); // Возвращаем черный цвет
        $pdf->SetLineWidth(0.3); // Стандартная толщина
        $lineDrawn = true; // Чтобы линия не рисовалась повторно
    }
    
    $place++;
}

// ... (остальной код без изменений) ...

// Генерация имени файла
$currentDate = date('d-m-Y');
$filename = "Рейтинг_{$specShort}_$currentDate.pdf";

// Отправка PDF
header('Content-Type: application/pdf');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
$pdf->Output($filename, 'D');
exit;
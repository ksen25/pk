<?php
require_once __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php';
require_once __DIR__ . '/../../vendor/autoload.php'; // Для работы с ZipArchive

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
$conn->set_charset('utf8mb4');

// Получаем все специальности
$sql = "SELECT id_prof_spec, title, socr FROM profec_spec ORDER BY title";
$result = $conn->query($sql);

// Создаем временную директорию
$tempDir = sys_get_temp_dir() . '/ratings_' . uniqid();
mkdir($tempDir);

$files = [];

// Для каждой специальности создаем отдельный PDF
while ($spec = $result->fetch_assoc()) {
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    
    $specTitle = $spec['title'];
    $specShort = $spec['socr'] ?: "spec_{$spec['id_prof_spec']}";
    // Очищаем название от недопустимых символов для имени файла
    $cleanTitle = preg_replace('/[^a-zA-Zа-яА-Я0-9_\-]/u', '_', $specTitle);
    $cleanShort = preg_replace('/[^a-zA-Zа-яА-Я0-9_\-]/u', '_', $specShort);

    // Формируем имя файла
    $filename = "{$cleanTitle}_{$cleanShort}.pdf";
    
    $pdf->SetTitle("Рейтинг абитуриентов - {$specTitle}");
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

    // Получаем данные о собрании и форме обучения
    $sqlMeeting = "SELECT ds.date_sobr, ds.time_sobr, do.date_okonch, ps.forma_obuch 
                 FROM profec_spec ps 
                 LEFT JOIN date_sobr ds ON ps.date_sobr = ds.id 
                 LEFT JOIN date_okonch do ON ps.date_okonch = do.id
                 WHERE ps.id_prof_spec = ?";
    $stmtMeeting = $conn->prepare($sqlMeeting);
    $stmtMeeting->bind_param("i", $spec['id_prof_spec']);
    $stmtMeeting->execute();
    $resultMeeting = $stmtMeeting->get_result();

    $meetingInfo = "";
    $infoText = "";

    if ($resultMeeting->num_rows > 0) {
        $meetingData = $resultMeeting->fetch_assoc();
        
        // Форматирование даты собрания (только если данные есть)
        if (!empty($meetingData['date_sobr']) && !empty($meetingData['time_sobr'])) {
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
        } else {
            $meetingInfo = "Дата собрания для первокурсников будет объявлена позже";
        }

        // Форматирование даты зачисления (только если данные есть)
        if (!empty($meetingData['date_okonch'])) {
            $okonchDate = DateTime::createFromFormat('Y-m-d', $meetingData['date_okonch']);
            $formattedOkonchDate = $okonchDate ? $okonchDate->format('d.m.Y') : $meetingData['date_okonch'];
            $infoText = "Зачисление на обучение проводится при предоставлении в приемную комиссию оригинала документа об образовании и (или) документа об образовании и о квалификации, а также документа, подтверждающего право преимущественного или первоочередного приёма в соответствии с частью 5.1 статьи 71 - $formattedOkonchDate до 16:00";
        } else {
            $infoText = "Зачисление на обучение проводится при предоставлении в приемную комиссию оригинала документа об образовании и (или) документа об образовании и о квалификации, а также документа, подтверждающего право преимущественного или первоочередного приёма в соответствии с частью 5.1 статьи 71";
        }
    } else {
        $meetingInfo = "Дата собрания для первокурсников будет объявлена позже";
        $infoText = "Дата зачисления будет объявлена позже";
    }

    // Настройки блока
    $pdf->SetDrawColor(0, 0, 0); // Черный цвет границы
    $pdf->SetFillColor(255, 255, 255); // Белый фон
    $pdf->SetLineWidth(0.3); // Толщина линии

    // Рассчитываем высоту блока (примерно 4 строки)
    $blockHeight = 35;

    // Рисуем прямоугольник (блок с границами)
    $startY = $pdf->GetY();
    $pdf->Rect(15, $startY, 180, $blockHeight, 'DF');

    // Добавляем текст в блок
    $pdf->SetXY(15, $startY + 5);

    // Текст о зачислении (обычный цвет)
    $pdf->MultiCell(180, 5, $infoText, 0, 'C');

    // Текст о собрании (красный цвет)
    $pdf->SetTextColor(255, 0, 0); // Красный цвет
    $pdf->SetX(15);
    $pdf->MultiCell(180, 5, $meetingInfo, 0, 'C');
    $pdf->SetTextColor(0, 0, 0); // Возвращаем черный цвет

    // Перемещаем указатель после блока
    $pdf->SetY($startY + $blockHeight + 10); // +10 для отступа

// ... (предыдущий код без изменений до SQL-запроса абитуриентов)

    // Получаем абитуриентов для этой специальности с сортировкой по фамилии при одинаковом балле
    $sqlAbit = "
        SELECT 
            abit.familiya, abit.imya, abit.otchestvo, 
            abit.sr_ball_attest, abit.pervooch_priem,
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
        ORDER BY 
            zayav.original DESC,
            is_priority DESC,
            abit.sr_ball_attest DESC,
            abit.familiya ASC
    ";
    
    $stmt = $conn->prepare($sqlAbit);
    $stmt->bind_param("i", $spec['id_prof_spec']);
    $stmt->execute();
    $abitResult = $stmt->get_result();

    // Настройка таблицы
    $columnWidths = [20, 70, 30, 35, 50]; // Увеличил последний столбец с 30 до 40
    $leftMargin = (210 - array_sum($columnWidths)) / 2;

    // Заголовок таблицы
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->SetX($leftMargin);
    $pdf->SetFillColor(200, 200, 200);
    $pdf->Cell($columnWidths[0], 10, 'Место', 1, 0, 'C', true);
    $pdf->Cell($columnWidths[1], 10, 'ФИО абитуриента', 1, 0, 'C', true);
    $pdf->Cell($columnWidths[2], 10, 'Средний балл', 1, 0, 'C', true);
    $pdf->Cell($columnWidths[3], 10, 'Документ', 1, 0, 'C', true);
    $pdf->Cell($columnWidths[4], 10, 'Первоочерёдный приём', 1, 1, 'C', true);

    // Цвета
    $originalColor = [255, 255, 153]; // Желтый для оригинала
    $priorityColor = [191, 252, 255]; // Голубой для приоритета

// ... (предыдущий код без изменений до заполнения данных абитуриентов) ...

    // Заполнение данных
    $pdf->SetFont('dejavusans', '', 8); // Уменьшил шрифт данных
    $place = 1;
    $isMD9 = ($specShort === 'МД9'); // Проверяем, что это специальность МД9
    $lineDrawn = false; // Флаг для отрисовки линии

    while ($row = $abitResult->fetch_assoc()) {
        $fio = $row['familiya'] . ' ' . $row['imya'] . ' ' . $row['otchestvo'];
        $original = $row['original'] == 1 ? 'Оригинал' : 'Копия';
        $isPriority = $row['is_priority'] == 1;
        
        $fillColor = $row['original'] == 1 ? $originalColor : [255, 255, 255];
        
        $pdf->SetX($leftMargin);
        $pdf->SetFillColor($fillColor[0], $fillColor[1], $fillColor[2]);
        
        $pdf->Cell($columnWidths[0], 10, $place, 1, 0, 'C', true);
        $pdf->Cell($columnWidths[1], 10, $fio, 1, 0, 'L', true);
        $pdf->Cell($columnWidths[2], 10, $row['sr_ball_attest'], 1, 0, 'C', true);
        $pdf->Cell($columnWidths[3], 10, $original, 1, 0, 'C', true);
        
        $pdf->SetFillColor($priorityColor[0], $priorityColor[1], $priorityColor[2]);
        $pdf->Cell($columnWidths[4], 10, $isPriority ? '✓' : '', 1, 1, 'C', true);

        // Для МД9 рисуем красную черту после 25 места
        if ($isMD9 && $place == 25 && !$lineDrawn) {
            $currentY = $pdf->GetY();
            $pdf->SetDrawColor(255, 0, 0); // Красный цвет
            $pdf->SetLineWidth(1); // Толщина линии
            $pdf->Line(
                $leftMargin, 
                $currentY, 
                $leftMargin + array_sum($columnWidths), 
                $currentY
            );
            $pdf->SetDrawColor(0, 0, 0); // Возвращаем черный цвет
            $pdf->SetLineWidth(0.3); // Возвращаем стандартную толщину
            $lineDrawn = true;
        }
        
        $place++;
    }
    $pdf->SetFont('dejavusans', '', 10); // Возвращаем стандартный шрифт

// ... (остальной код без изменений) ...

// ... (остальной код без изменений)
    // Сохраняем PDF во временную директорию
    $filePath = $tempDir . '/' . $filename;
    $pdf->Output($filePath, 'F');
    $files[] = $filePath;
}

// Создаем ZIP-архив
$zipFilename = 'Все_рейтинги_' . date('Y-m-d') . '.zip';
$zipPath = $tempDir . '/' . $zipFilename;

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
    die("Невозможно создать ZIP-архив");
}

foreach ($files as $file) {
    $zip->addFile($file, basename($file));
}
$zip->close();

// Отправляем архив пользователю
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
header('Content-Length: ' . filesize($zipPath));
readfile($zipPath);

// Удаляем временные файлы
foreach ($files as $file) {
    unlink($file);
}
unlink($zipPath);
rmdir($tempDir);

exit;
<?php
require_once __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php';
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

// SQL-запрос для получения количества заявлений по каждой области
$sql = "
SELECT adress_registr.oblast, COUNT(zayav.id_zayav) AS total_applications
FROM adress_registr 
JOIN abit ON adress_registr.id_adr_reg = abit.adress_registr
JOIN zayav ON abit.id_abit = zayav.id_abitur
GROUP BY adress_registr.oblast
ORDER BY total_applications DESC;
";

$result = $conn->query($sql);

if (!$result) {
    die("Ошибка запроса: " . $conn->error);
}

// Проверка, была ли нажата кнопка для скачивания отчета в PDF
if (isset($_GET['download_report'])) {
    // Создаем новый объект TCPDF
    $pdf = new TCPDF();
    
    // Устанавливаем информацию о документе
    $pdf->SetCreator('TCPDF');
    $pdf->SetTitle('Отчет по областям');
    $pdf->SetSubject('Количество заявлений по областям');

    // Добавляем страницу
    $pdf->AddPage();

    // Заголовок отчета
    $pdf->SetFont('dejavusans', 'B', 16);
    $pdf->Cell(0, 10, 'Отчет по количеству заявлений по областям', 0, 1, 'C');

    // Делаем отступ
    $pdf->Ln(10);

    // Заголовки таблицы
    $pdf->SetFont('dejavusans', 'B', 12);
    $pdf->Cell(100, 10, 'Область', 1, 0, 'C');
    $pdf->Cell(90, 10, 'Количество заявлений', 1, 1, 'C');
    
    // Вывод данных из базы данных
    $pdf->SetFont('dejavusans', '', 12);
    while ($row = $result->fetch_assoc()) {
        $pdf->Cell(100, 10, htmlspecialchars($row['oblast']), 1, 0, 'C');
        $pdf->Cell(90, 10, $row['total_applications'], 1, 1, 'C');
    }

    // Выводим PDF
    $pdf->Output('report.pdf', 'D'); // 'D' для скачивания
    exit();
}

// Закрытие соединения с БД
$conn->close();
?>


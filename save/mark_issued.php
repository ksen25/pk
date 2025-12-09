<?php
header('Content-Type: application/json');

if (
    empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

$zayavId = isset($_POST['id_zayav']) ? (int)$_POST['id_zayav'] : 0;
$issueDateRaw = isset($_POST['issue_date']) ? trim($_POST['issue_date']) : '';

if ($zayavId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Некорректный идентификатор заявления']);
    exit;
}

// Валидируем дату, если пришла из клиента; иначе берем текущую
$issueDate = date('d.m.Y');
if ($issueDateRaw !== '') {
    $parsed = DateTime::createFromFormat('d.m.Y', $issueDateRaw);
    if ($parsed) {
        $issueDate = $parsed->format('d.m.Y');
    }
}
$mark = "Документы выданы {$issueDate}";

$host = 'localhost';
$user = 'root';
$password = 'root';
$dbname = 'pk_2025';

$conn = new mysqli($host, $user, $password, $dbname);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Ошибка подключения к БД']);
    exit;
}
$conn->set_charset('utf8mb4');

// Гарантируем наличие поля issue_note
$colCheckIssue = $conn->query("SHOW COLUMNS FROM zayav LIKE 'issue_note'");
if ($colCheckIssue && $colCheckIssue->num_rows === 0) {
    $conn->query("ALTER TABLE zayav ADD COLUMN issue_note TEXT NULL");
}

// Получаем id абитуриента
$stmt = $conn->prepare("SELECT id_abitur FROM zayav WHERE id_zayav = ?");
$stmt->bind_param("i", $zayavId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Заявление не найдено']);
    $conn->close();
    exit;
}

$abitId = (int)$row['id_abitur'];

// Получаем все заявления абитуриента
$stmtAll = $conn->prepare("SELECT id_zayav, comment, issue_note FROM zayav WHERE id_abitur = ?");
$stmtAll->bind_param("i", $abitId);
$stmtAll->execute();
$all = $stmtAll->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtAll->close();

// Готовим update: отдельная отметка issue_note, очищенный comment без дублирования отметки
$update = $conn->prepare("UPDATE zayav SET comment = ?, issue_note = ? WHERE id_zayav = ?");

foreach ($all as $item) {
    $currentComment = $item['comment'] ?? '';

    // Убираем старые отметки "Документы выданы ..." из comment
    $cleanComment = preg_replace('/Документы выданы[^;]*;?\s*/ui', '', $currentComment);
    $cleanComment = trim($cleanComment, " ;\t\n\r\0\x0B");

    // comment оставляем очищенным текстом (без отметки) или NULL
    $newComment = $cleanComment !== '' ? $cleanComment : NULL;

    // issue_note содержит только отметку "Документы выданы [дата]"
    $newIssue = $mark;

    $update->bind_param("ssi", $newComment, $newIssue, $item['id_zayav']);
    $update->execute();
}

$update->close();

echo json_encode(['success' => true, 'message' => $mark]);
$conn->close();
?>


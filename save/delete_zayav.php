<?php
header('Content-Type: application/json');

// Разрешаем только AJAX-запросы
if (
    empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
    exit;
}

$zayavId = isset($_POST['id_zayav']) ? (int)$_POST['id_zayav'] : 0;
if ($zayavId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Некорректный идентификатор заявления']);
    exit;
}

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

$stmt = $conn->prepare("DELETE FROM zayav WHERE id_zayav = ?");
$stmt->bind_param("i", $zayavId);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'Заявление удалено']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Заявление не найдено']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Ошибка при удалении: ' . $conn->error]);
}

$stmt->close();
$conn->close();
?>


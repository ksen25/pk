<?php
require_once '../config/config.php';

$spec_id = $_GET['spec_id'] ?? 0;
$class = $_GET['class'] ?? 0;
$forma = $_GET['forma'] ?? 0;

// Отладочная информация
error_log("get_groups.php вызван с параметрами: spec_id=$spec_id, class=$class, forma=$forma");

// Проверяем подключение к базе
if (!$conn) {
    die(json_encode(['groups' => [], 'error' => 'Нет подключения к базе данных']));
}

// Проверяем, что параметры переданы
if (!$spec_id || $class === '' || $forma === '') {
    error_log("Параметры: spec_id=$spec_id, class=$class, forma=$forma");
    echo json_encode(['groups' => [], 'error' => 'Не указаны все параметры']);
    exit;
}

// Преобразуем параметры к целым числам
$class = (int)$class;
$forma = (int)$forma;

// Получаем группы для выбранной специальности, класса и формы обучения
$stmt = $conn->prepare("SELECT id, name FROM `groups` WHERE id_spec_prof = ? AND class = ? AND forma_obuch = ? ORDER BY name");
if (!$stmt) {
    error_log("Ошибка подготовки запроса: " . $conn->error);
    echo json_encode(['groups' => [], 'error' => 'Ошибка базы данных']);
    exit;
}

$stmt->bind_param("iii", $spec_id, $class, $forma);
if (!$stmt->execute()) {
    error_log("Ошибка выполнения запроса: " . $stmt->error);
    echo json_encode(['groups' => [], 'error' => 'Ошибка выполнения запроса']);
    exit;
}

$result = $stmt->get_result();
$groups = [];

while ($row = $result->fetch_assoc()) {
    $groups[] = $row;
}

$stmt->close();

error_log("Найдено групп: " . count($groups));

echo json_encode(['groups' => $groups]);

?>

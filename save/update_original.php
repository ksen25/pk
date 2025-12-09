<?php
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

// Получаем данные из запроса
$data = json_decode(file_get_contents('php://input'), true);

if ($data && isset($data['changes'])) {
    // Проходим по изменениям и выполняем обновление для каждой записи
    foreach ($data['changes'] as $change) {
        $zayavId = (int)$change['zayavId'];
        $originalValue = (int)$change['originalValue'];

        // Обновление значения в базе данных
        $sql = "UPDATE zayav SET original = ? WHERE id_zayav = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $originalValue, $zayavId);

        if (!$stmt->execute()) {
            // Если ошибка в запросе, выводим сообщение
            echo json_encode(['success' => false, 'message' => 'Ошибка при обновлении записи с ID ' . $zayavId]);
            exit;
        }
    }

    // Если все прошло успешно
    echo json_encode(['success' => true]);
} else {
    // Если данные не переданы или некорректны
    echo json_encode(['success' => false, 'message' => 'Некорректные данные']);
}

$conn->close();
?>

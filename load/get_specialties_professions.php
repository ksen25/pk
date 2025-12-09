<?php
$host = 'localhost';
$user = 'root';
$password = 'root';
$dbname = 'pk_2025';

$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    die(json_encode(['error' => 'Ошибка подключения: ' . $conn->connect_error]));
}

// Функция для получения данных с добавлением сокращений
function getData($conn, $category) {
    $sql = "SELECT id_prof_spec AS id, title, socr FROM profec_spec WHERE kategoriya = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $category);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $row['title'] = !empty($row['socr']) ? "{$row['title']} ({$row['socr']})" : $row['title'];
        unset($row['socr']); // Убираем ненужное поле из ответа
        $data[] = $row;
    }

    return !empty($data) ? $data : [['id' => '', 'title' => '-- Выбрать --']];
}

// Получаем списки
$specialties = getData($conn, '1');
$professions = getData($conn, '2');

echo json_encode([
    'specialties' => $specialties,
    'professions' => $professions
]);

$conn->close();
?>

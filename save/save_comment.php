<?php
// Подключение к базе данных
$host = 'localhost';
$user = 'root';
$password = 'root';
$dbname = 'pk_2025';
$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Ошибка подключения: ' . $conn->connect_error]));
}

// Получаем данные
$abitId = isset($_POST['abit_id']) ? (int)$_POST['abit_id'] : 0;
$comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';

if ($abitId <= 0) {
    die(json_encode(['success' => false, 'message' => 'Неверный ID абитуриента']));
}

// Находим последнее заявление с комментарием для этого абитуриента
$sql = "SELECT id_zayav FROM zayav WHERE id_abitur = ? AND comment IS NOT NULL ORDER BY date DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $abitId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $zayavId = $row['id_zayav'];
    
    if (!empty($comment)) {
        // Обновляем существующий комментарий
        $sql = "UPDATE zayav SET comment = ? WHERE id_zayav = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $comment, $zayavId);
    } else {
        // Удаляем комментарий
        $sql = "UPDATE zayav SET comment = NULL WHERE id_zayav = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $zayavId);
    }
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Комментарий обновлен', 'comment' => $comment]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Ошибка при обновлении комментария']);
    }
} else if (!empty($comment)) {
    // Если нет заявлений с комментариями, но комментарий не пустой - добавляем к последнему заявлению
    $sql = "SELECT id_zayav FROM zayav WHERE id_abitur = ? ORDER BY date DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $abitId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $zayavId = $row['id_zayav'];
        
        $sql = "UPDATE zayav SET comment = ? WHERE id_zayav = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $comment, $zayavId);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Комментарий добавлен', 'comment' => $comment]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Ошибка при добавлении комментария']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Нет заявлений для абитуриента']);
    }
} else {
    // Нет заявлений с комментариями и комментарий пустой - ничего не делаем
    echo json_encode(['success' => true, 'message' => 'Нет комментариев для удаления']);
}

$conn->close();
?>
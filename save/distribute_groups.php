<?php
require_once '../config/config.php';

header('Content-Type: application/json');

$spec_id = $_POST['spec_id'] ?? 0;
$class = $_POST['class'] ?? 0;
$forma = $_POST['forma'] ?? 0;
$existing_group = $_POST['existing_group'] ?? 0;
$new_group = trim($_POST['new_group'] ?? '');
$applicants = json_decode($_POST['applicants'] ?? '[]', true);

if (!$spec_id || !$class || !$forma) {
    echo json_encode(['success' => false, 'message' => 'Не указана специальность, класс или форма обучения']);
    exit;
}

if (!$existing_group && !$new_group) {
    echo json_encode(['success' => false, 'message' => 'Не указана группа']);
    exit;
}

if (empty($applicants)) {
    echo json_encode(['success' => false, 'message' => 'Не выбраны абитуриенты']);
    exit;
}

$conn->begin_transaction();

try {
    // Определяем ID группы
    $group_id = $existing_group;
    
    // Если указана новая группа, создаем ее
    if ($new_group) {
        // Проверяем, нет ли уже такой группы для этой специальности
        $checkStmt = $conn->prepare("SELECT id FROM `groups` WHERE name = ? AND id_spec_prof = ? AND class = ? AND forma_obuch = ?");
        $checkStmt->bind_param("siii", $new_group, $spec_id, $class, $forma);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows > 0) {
            $row = $checkResult->fetch_assoc();
            $group_id = $row['id'];
        } else {
            $insertStmt = $conn->prepare("INSERT INTO `groups` (name, id_spec_prof, class, forma_obuch) VALUES (?, ?, ?, ?)");
            $insertStmt->bind_param("siii", $new_group, $spec_id, $class, $forma);
            $insertStmt->execute();
            $group_id = $conn->insert_id;
        }
    }
    
    // Обновляем заявления выбранных абитуриентов
    $placeholders = implode(',', array_fill(0, count($applicants), '?'));
    $types = str_repeat('i', count($applicants) + 1);
    
    $sql = "UPDATE zayav SET group_id = ? WHERE id_zayav IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    
    // Собираем параметры: сначала group_id, потом все id_zayav
    $params = array_merge([$group_id], $applicants);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    
    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Группа успешно распределена']);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Ошибка базы данных: ' . $e->getMessage()]);
}
?>
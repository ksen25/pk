<?php
// Проверка, что скрипт вызван через AJAX
if(empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    die(json_encode(['success' => false, 'message' => 'Доступ запрещен']));
}

// Остальной код...
header('Content-Type: application/json');

// Включим отладку
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'localhost';
$user = 'root';
$password = 'root';
$dbname = 'pk_2025';
$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Ошибка подключения: ' . $conn->connect_error]));
}

// Логируем полученные данные
file_put_contents('change_spec.log', print_r($_POST, true), FILE_APPEND);

$zayavId = isset($_POST['zayav_id']) ? (int)$_POST['zayav_id'] : 0;
$newSpecId = isset($_POST['new_spec_id']) ? (int)$_POST['new_spec_id'] : 0;

file_put_contents('change_spec.log', "zayavId: $zayavId, newSpecId: $newSpecId\n", FILE_APPEND);

if ($zayavId <= 0 || $newSpecId <= 0) {
    file_put_contents('change_spec.log', "Неверные параметры запроса\n", FILE_APPEND);
    echo json_encode(['success' => false, 'message' => 'Неверные параметры запроса']);
    exit;
}

// Проверяем существование заявления
$checkStmt = $conn->prepare("SELECT id_zayav FROM zayav WHERE id_zayav = ?");
$checkStmt->bind_param("i", $zayavId);
$checkStmt->execute();
$checkStmt->store_result();

if ($checkStmt->num_rows === 0) {
    file_put_contents('change_spec.log', "Заявление не найдено\n", FILE_APPEND);
    echo json_encode(['success' => false, 'message' => 'Заявление не найдено']);
    exit;
}
$checkStmt->close();

// Проверяем существование новой специальности
$checkSpecStmt = $conn->prepare("SELECT id_prof_spec FROM profec_spec WHERE id_prof_spec = ?");
$checkSpecStmt->bind_param("i", $newSpecId);
$checkSpecStmt->execute();
$checkSpecStmt->store_result();

if ($checkSpecStmt->num_rows === 0) {
    file_put_contents('change_spec.log', "Специальность не найдена\n", FILE_APPEND);
    echo json_encode(['success' => false, 'message' => 'Специальность не найдена']);
    exit;
}
$checkSpecStmt->close();

// Обновляем специальность
$updateStmt = $conn->prepare("UPDATE zayav SET id_spec_prof = ? WHERE id_zayav = ?");
$updateStmt->bind_param("ii", $newSpecId, $zayavId);

if ($updateStmt->execute()) {
    file_put_contents('change_spec.log', "Специальность успешно изменена\n", FILE_APPEND);
    echo json_encode(['success' => true, 'message' => 'Специальность успешно изменена']);
} else {
    $error = $conn->error;
    file_put_contents('change_spec.log', "Ошибка: $error\n", FILE_APPEND);
    echo json_encode(['success' => false, 'message' => 'Ошибка при обновлении специальности: ' . $error]);
}

$updateStmt->close();
$conn->close();
?>

<?php
$conn = new mysqli('localhost', 'root', 'root', 'pk_2025');
if ($conn->connect_error) {
    die(json_encode(["status" => "error", "message" => "Ошибка подключения: " . $conn->connect_error]));
}

$abit_id = $_POST['abit_id']; // Получаем ID абитуриента
$selected_language = $_POST['foreign_languages'];

if ($selected_language === 'other' && !empty($_POST['other_language'])) {
    $other_language = $conn->real_escape_string($_POST['other_language']);

    // Добавляем "другой язык" в таблицу dr_in_yaz
    $insertQuery = "INSERT INTO dr_in_yaz (title) VALUES ('$other_language')";
    if ($conn->query($insertQuery)) {
        $new_language_id = $conn->insert_id; // Получаем ID нового языка

        // Обновляем запись абитуриента: in_yaz = NULL, dr_in_yaz = новый ID
        $updateAbit = "UPDATE abit SET in_yaz = NULL, dr_in_yaz = ? WHERE id_abit = ?";
        $stmt = $conn->prepare($updateAbit);
        $stmt->bind_param("ii", $new_language_id, $abit_id);
        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "Другой язык добавлен и обновлен"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Ошибка обновления абитуриента"]);
        }
        $stmt->close();
    } else {
        echo json_encode(["status" => "error", "message" => "Ошибка добавления языка"]);
    }
} else {
    // Если выбран язык из списка
    $language_id = (int)$selected_language; // Преобразуем строку в число
    $updateAbit = "UPDATE abit SET in_yaz = ?, dr_in_yaz = NULL WHERE id_abit = ?";
    $stmt = $conn->prepare($updateAbit);
    $stmt->bind_param("ii", $language_id, $abit_id);
    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Язык обновлен"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Ошибка обновления абитуриента"]);
    }
    $stmt->close();
}

$conn->close();
?>

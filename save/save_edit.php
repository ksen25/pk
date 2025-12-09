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

// Проверяем, были ли отправлены данные
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Получаем данные из формы
    $id_zayav = $_POST['zayav_id']; // ID заявления
    $id_abit = $_POST['id_abit']; // ID абитуриента

    // Данные абитуриента
    $familiya = $_POST['familiya'];
    $imya = $_POST['imya'];
    $otchestvo = $_POST['otchestvo'];
    $snils = $_POST['snils'];
    $date_bd = $_POST['date_bd'];

    // ID выбранной специальности и профессии
    $spec_title_id = $_POST['spec_title'];  // ID специальности
    $profession_id = $_POST['profession'];  // ID профессии
    $socr = $_POST['socr'];  // Сокращение
    $date = $_POST['date'];

    // Обновление данных абитуриента в таблице abit
    $abit_sql = "UPDATE abit SET 
                familiya = ?, 
                imya = ?, 
                otchestvo = ?, 
                snils = ?, 
                date_bd = ? 
            WHERE id_abit = ?";

    $stmt = $conn->prepare($abit_sql);
    $stmt->bind_param('ssssssi', $familiya, $imya, $otchestvo, $snils, $date_bd, $id_abit);

    if (!$stmt->execute()) {
        die("Ошибка при обновлении данных абитуриента: " . $conn->error);
    }

    // Обновление данных о заявлении в таблице zayav
    $zayav_sql = "UPDATE zayav SET 
                id_spec_prof = ?,  -- Изменяем на id_spec_prof
                profession = ?, 
                socr = ?, 
                date = ? 
            WHERE id_zayav = ?";

    $stmt = $conn->prepare($zayav_sql);
    $stmt->bind_param('isssi', $spec_title_id, $profession_id, $socr, $date, $id_zayav);

    if ($stmt->execute()) {
        echo "Данные успешно обновлены.";
    } else {
        echo "Ошибка при обновлении данных заявления: " . $conn->error;
    }

    // Закрытие подготовленных запросов
    $stmt->close();
    $conn->close();
} else {
    echo "Нет данных для обработки.";
}
?>

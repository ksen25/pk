<?php
// Подключение к базе данных
$host = 'localhost';
$user = 'root';
$password = 'root';
$dbname = 'pk_2025';
$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

// Получаем ID заявки из GET-параметра
$idZayav = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Запрос для получения данных по заявлению
$sql = "SELECT
            zayav.id_zayav,
            abit.familiya,
            abit.imya,
            abit.otchestvo,
            abit.snils,
            abit.date_bd,
            abit.phone,
            abit.document,
            abit.ser_num,
            abit.adress_prozhiv,
            abit.adress_registr,
            abit.mesto_rozhd,
            SUBSTRING_INDEX(profec_spec.title, ' - ', 1) AS profession, -- Первая часть (профессия)
            SUBSTRING_INDEX(profec_spec.title, ' - ', -1) AS specialty, -- Вторая часть (специальность)
            zayav.id_spec_prof,
            zayav.date AS date_podachi
        FROM 
            zayav
        JOIN 
            abit ON zayav.id_abitur = abit.id_abit
        JOIN 
            profec_spec ON zayav.id_spec_prof = profec_spec.id_prof_spec
        WHERE 
            zayav.id_zayav = $idZayav";

$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode($row);
} else {
    echo json_encode(['error' => 'Заявление не найдено']);
}

$conn->close();
?>

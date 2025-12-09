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

$id_zayav = $_GET['id_zayav']; // Получаем ID заявления

// SQL-запрос для получения данных
$sql = "SELECT 
            abit.id_abit,
            abit.familiya,
            abit.imya,
            abit.otchestvo,
            abit.snils,
            abit.date_bd,
            zayav.id_zayav,
            zayav.date AS zayav_date,
            profec_spec.title AS spec_title,
            profec_spec.socr,
            kat_spec_prof.title AS profession
        FROM 
            abit
        JOIN 
            zayav ON abit.id_abit = zayav.id_abitur
        JOIN 
            profec_spec ON zayav.id_spec_prof = profec_spec.id_prof_spec
        JOIN 
            kat_spec_prof ON profec_spec.kategoriya = kat_spec_prof.id_kat
        WHERE
            zayav.id_zayav = ?"; // Используем подготовленный запрос для предотвращения SQL-инъекций

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id_zayav); // Привязываем параметр
$stmt->execute();
$result = $stmt->get_result();

// Проверка наличия данных
if ($result->num_rows > 0) {
    $data = $result->fetch_assoc();
    echo json_encode($data); // Отправляем данные в формате JSON
} else {
    echo json_encode(["error" => "Данные не найдены"]);
}

$stmt->close();
$conn->close();
?>

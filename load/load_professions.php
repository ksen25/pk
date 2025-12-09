<?php
// Подключение к базе данных
$conn = new mysqli('localhost', 'root', 'root', 'pk_2025');

if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

// Получение параметров из GET-запроса
$classId = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$formId = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;

// Запрос на выбор профессий/специальностей
$query = "SELECT 
            profec_spec.id_prof_spec, 
            profec_spec.title AS profession_title, 
            profec_spec.ispitanie, 
            kat_spec_prof.title AS category_title, 
            ispitaniya.title AS exam_title
          FROM profec_spec
          JOIN kat_spec_prof ON profec_spec.kategoriya = kat_spec_prof.id_kat
          LEFT JOIN ispitaniya ON profec_spec.ispitanie = ispitaniya.id_ispit
          WHERE profec_spec.class = $classId AND profec_spec.forma_obuch = $formId";

$result = $conn->query($query);

// Генерация HTML для чекбоксов
$currentCategory = '';
if ($result->num_rows > 0) {
    echo '<div>';
    while ($row = $result->fetch_assoc()) {
        if ($currentCategory !== $row['category_title']) {
            if ($currentCategory !== '') {
                echo '</div>'; // Закрыть предыдущую категорию
            }
            $currentCategory = $row['category_title'];
            echo "<div><h5>{$currentCategory}</h5>";
        }

        // Проверяем, есть ли вступительный экзамен
        $examNote = $row['exam_title'] ? " <span style='color: red;'>(Вступительное испытание: {$row['exam_title']})</span>" : "";

        // Генерация чекбокса с id для связывания с меткой
        $checkboxId = "checkbox-{$row['id_prof_spec']}";  // Уникальный id для каждого чекбокса

        echo "<div class='chekbox-group'>
                <input type='checkbox' class='checkbox profession-checkbox' 
                    name='profession_specialty[]' 
                    value='{$row['id_prof_spec']}' 
                    id='{$checkboxId}' 
                    data-exam-title='" . htmlspecialchars($row['exam_title'] ?? '') . "'>
                <label for='{$checkboxId}'>{$row['profession_title']}{$examNote}</label>
              </div>";
    }
    echo '</div>';
} else {
    echo "<p>Нет доступных профессий/специальностей для выбранных условий.</p>";
}

$conn->close();
?>


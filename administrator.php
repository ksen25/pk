<?php
$host = 'localhost';
$user = 'root';
$password = 'root';
$dbname = 'pk_2025';
$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

// Обработка сброса фильтра
if (isset($_GET['reset_filter'])) {
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// Получение фильтров
$specTitleFilter = isset($_GET['spec_title_filter']) ? array_map('intval', $_GET['spec_title_filter']) : [];
$professionFilter = isset($_GET['profession_filter']) ? array_map('intval', $_GET['profession_filter']) : [];
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Пагинация
$limit = 5;
$page = isset($_GET['page']) ? max((int)$_GET['page'], 1) : 1;
$offset = ($page - 1) * $limit;

// Условия фильтрации
$where = [];
if ($search !== '') {
    $escapedSearch = $conn->real_escape_string(mb_strtolower($search));
    // Разбиваем поисковую строку на части
    $searchParts = preg_split('/\s+/', $escapedSearch);
    $searchConditions = [];

    // Базовые условия поиска (как были)
    $searchConditions[] = "LOWER(abit.familiya) LIKE '%$escapedSearch%'";
    $searchConditions[] = "LOWER(abit.imya) LIKE '%$escapedSearch%'";
    $searchConditions[] = "LOWER(abit.otchestvo) LIKE '%$escapedSearch%'";
    $searchConditions[] = "abit.snils LIKE '%$escapedSearch%'";

    // Если в поиске несколько слов, добавляем условия для комбинаций
    if (count($searchParts) > 1) {
        // Комбинация фамилия + имя
        $searchConditions[] = "(LOWER(abit.familiya) LIKE '%{$searchParts[0]}%' AND LOWER(abit.imya) LIKE '%{$searchParts[1]}%')";
        
        // Комбинация имя + фамилия (на случай, если пользователь ввел наоборот)
        $searchConditions[] = "(LOWER(abit.imya) LIKE '%{$searchParts[0]}%' AND LOWER(abit.familiya) LIKE '%{$searchParts[1]}%')";
        
        // Если есть третье слово (отчество)
        if (count($searchParts) > 2) {
            $searchConditions[] = "(LOWER(abit.familiya) LIKE '%{$searchParts[0]}%' AND LOWER(abit.imya) LIKE '%{$searchParts[1]}%' AND LOWER(abit.otchestvo) LIKE '%{$searchParts[2]}%')";
        }
    }

    $where[] = "(" . implode(" OR ", $searchConditions) . ")";
}
if (!empty($specTitleFilter)) {
    $where[] = "zayav.id_spec_prof IN (" . implode(",", $specTitleFilter) . ")";
}
if (!empty($professionFilter)) {
    $where[] = "zayav.id_spec_prof IN (" . implode(",", $professionFilter) . ")";
}

$sqlWhere = '';
if (!empty($where)) {
    $sqlWhere = " AND (" . implode(" OR ", $where) . ")";
}

// Запрос на выборку данных с сортировкой по дате
$sql = "
SELECT 
    abit.id_abit, 
    abit.familiya, 
    abit.imya, 
    abit.otchestvo, 
    abit.snils,
    abit.date_bd,
    MAX(zayav.date) as latest_date,
    (SELECT comment FROM zayav WHERE id_abitur = abit.id_abit AND comment IS NOT NULL ORDER BY date DESC LIMIT 1) as last_comment
FROM 
    abit
JOIN zayav ON abit.id_abit = zayav.id_abitur
JOIN profec_spec ON zayav.id_spec_prof = profec_spec.id_prof_spec
JOIN kat_spec_prof ON profec_spec.kategoriya = kat_spec_prof.id_kat
WHERE 1 $sqlWhere
GROUP BY abit.id_abit
ORDER BY latest_date DESC
LIMIT ? OFFSET ?
";


$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

// Подсчёт общего количества записей
$sqlCount = "
SELECT COUNT(DISTINCT abit.id_abit) AS total
FROM abit
JOIN zayav ON abit.id_abit = zayav.id_abitur
JOIN profec_spec ON zayav.id_spec_prof = profec_spec.id_prof_spec
JOIN kat_spec_prof ON profec_spec.kategoriya = kat_spec_prof.id_kat
WHERE 1 $sqlWhere
";

$resultCount = $conn->query($sqlCount);
$totalAbit = $resultCount->fetch_assoc()['total'];
$totalPages = ceil($totalAbit / $limit);
$abitIds = array_column($result->fetch_all(MYSQLI_ASSOC), 'id_abit');

if (!empty($abitIds)) {
    $idsIn = implode(",", array_map('intval', $abitIds));
    $zayavQuery = "
    SELECT 
        abit.id_abit,
        abit.familiya,
        abit.imya,
        abit.otchestvo,
        abit.snils,
        abit.date_bd,
        zayav.id_zayav, 
        zayav.date, 
        zayav.original,
        zayav.comment,    
        profec_spec.code,
        profec_spec.title AS spec_title,
        profec_spec.socr,
        kat_spec_prof.title AS kategoriya,
        (SELECT comment FROM zayav WHERE id_abitur = abit.id_abit AND comment IS NOT NULL ORDER BY date DESC LIMIT 1) as last_comment
    FROM 
        zayav
    JOIN abit ON abit.id_abit = zayav.id_abitur
    JOIN profec_spec ON zayav.id_spec_prof = profec_spec.id_prof_spec
    JOIN kat_spec_prof ON profec_spec.kategoriya = kat_spec_prof.id_kat
    WHERE abit.id_abit IN ($idsIn)
    ORDER BY zayav.date DESC
    ";


    $zayavResult = $conn->query($zayavQuery);
    $abitData = [];

    while ($row = $zayavResult->fetch_assoc()) {
        $idAbit = $row['id_abit'];

        if (!isset($abitData[$idAbit])) {
            $abitData[$idAbit]['familiya'] = $row['familiya'];
            $abitData[$idAbit]['imya'] = $row['imya'];
            $abitData[$idAbit]['otchestvo'] = $row['otchestvo'];
            $abitData[$idAbit]['snils'] = $row['snils'];
            $abitData[$idAbit]['date_bd'] = $row['date_bd'];
            $abitData[$idAbit]['last_comment'] = $row['last_comment']; // Добавляем эту строку
        }

        $abitData[$idAbit]['zayavleniya'][] = [
            'code' => $row['code'],
            'spec_title' => $row['kategoriya'] === 'Специальность' ? $row['spec_title'] : '-',
            'profession' => $row['kategoriya'] === 'Профессия' ? $row['spec_title'] : '-',
            'socr' => $row['socr'],
            'date' => $row['date'],
            'id_zayav' => $row['id_zayav'],
            'original' => $row['original'],
            'comment' => $row['comment']     
        ];
        
    }
}
// Получение списка специальностей
$specialties = [];
$res = $conn->query("SELECT id_prof_spec, code, title, socr FROM profec_spec");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $specialties[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Заявления абитуриентов</title>
    <link rel="shortcut icon" href="img/ykuipt_logo.png">
    <link rel="stylesheet" href="css/styles.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
<header>
    <div class="blur">
        <img src="img/ykuipt_logo.png" alt="Логотип"><h1>Заявления абитуриентов</h1>
    </div>
</header>

<div class = "search-and-filter">
    <div class = "filter-otchet">
        <!-- Кнопка для открытия фильтра -->
        <div class="filter-cont-btn">
            <button id="openFilter" class="filter-btn">Фильтр</button>
            <!-- Кнопка для активации флажков -->
            <button id="activateCheckboxes" class="button">Активировать флажки оригиналов</button>

            <!-- Кнопка "Сохранить", которая появится после активации флажков -->
            <button id="saveChanges" class="button" style="display:none;">Сохранить изменения</button>
            <?php if (!empty($_GET['spec_title_filter']) || !empty($_GET['profession_filter'])): ?>
                <form method="GET">
                    <button type="submit" name="reset_filter" class = "unfilter" value="1">Сбросить фильтр</button>
                </form>
            <?php endif; ?>
        </div>
        <div class = "otchet">
<!-- Выпадающий список отчетов -->
    <label for="reportSelector">Выберите отчет:</label>

    <select id="reportSelector" onchange="showReport()">
        <option value="">-- Выберите отчет --</option>
        <option value="specReport">Отчет по специальностям</option>
        <option value="regionReport">Отчет по областям</option>
        <option value="applicantsReport">Отчет по подавшим заявления</option>
        <option value="allRatingsReport">Общие отчеты по специальностям</option>
        <option value="svoReport">Отчет по СВО и дети ветеранов</option> <!-- Новая опция -->
        <option value="letReport">Отчет по примечаниям (лет)</option> <!-- Новая опция -->
        <option value="diplomaReport">Отчет по абитуриентам с дипломом</option>
        <option value="pravoohranSimpleReport">Списки правоохранители</option>
        <option value="pictureSimpleReport">Списки МД и РК</option>
        <option value="originalSortedReport">Списки с оригиналами (по среднему баллу)</option>
    </select>

    <br><br>
    <div id="originalSortedReport" class="report-block" style="display:none;">
        <form method="get" action="otchet/generate_original_sorted_excel.php">
            <button type="submit" class="btn btn-primary">Скачать списки с оригиналами</button>
        </form>
    </div>
    <div id="pravoohranSimpleReport" class="report-block" style="display:none;">
        <form method="get" action="otchet/generate_pravoohran_simple_excel.php">
            <button type="submit" class="btn btn-primary">Скачать списки</button>
        </form>
    </div>
    <div id="pictureSimpleReport" class="report-block" style="display:none;">
        <form method="get" action="otchet/generate_picture_excel.php">
            <button type="submit" class="btn btn-primary">Скачать списки</button>
        </form>
    </div>
 <!-- Блок: Отчет по примечаниям СВО -->
    <div id="svoReport" class="report-block" style="display:none;">
        <form method="get" action="otchet/generate_svo_report.php">
            <input type="hidden" name="keyword" value="сво">
            <button type="submit" class="btn btn-primary">Скачать отчет по СВО и дети ветеранов</button>
        </form>
    </div>

    <!-- Блок: Отчет по примечаниям "лет" -->
    <div id="letReport" class="report-block" style="display:none;">
        <form method="get" action="otchet/generate_let_report.php">
            <input type="hidden" name="keyword" value="лет">
            <button type="submit" class="btn btn-primary">Скачать отчет по примечаниям (лет)</button>
        </form>
    </div>
    <div id="diplomaReport" class="report-block" style="display:none;">
        <form method="get" action="otchet/generate_diploma_report.php">
            <button type="submit" class="btn btn-primary">Скачать отчет по дипломам</button>
        </form>
    </div>
    <!-- Блок: Отчет по специальностям -->
    <div id="specReport" class="report-block" style="display:none;">
        <form action="" method="GET" class="reit" id="reportForm">
            <label for="spec">Выберите специальность/профессию:</label>
            <select name="spec_id" id="spec" required>
                <option value="">-- Выберите --</option>
                <?php foreach ($specialties as $row): ?>
                    <option value="<?= $row['id_prof_spec'] ?>">
                        <?= $row['title'] ?> (<?= $row['socr'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="action" value="pdf" class="btn btn-danger">Скачать рейтинг (PDF)</button>
            <button type="submit" name="action" value="excel" class="btn btn-success">Скачать отчет (Excel)</button>
        </form>
    </div>

    <!-- Блок: Отчет по областям -->
    <div id="regionReport" class="report-block" style="display:none;">
        <form method="get" action="otchet/generate_otchet_obl.php">
            <button type="submit" name="download_report" class="btn btn-primary">Скачать отчет по областям</button>
        </form>
    </div>

    <!-- Блок: Отчет по подавшим заявления -->
    <div id="applicantsReport" class="report-block" style="display:none;">
        <form method="get" action="otchet/generate_excel.php">
            <button type="submit" class="btn btn-success">Скачать отчет по подавшим заявления</button>
        </form>
    </div>
    <!-- Блок: Все рейтинги -->
    <div id="allRatingsReport" class="report-block" style="display:none;">
        <form method="get" class="reit">
            <button type="submit" formaction="otchet/generate_all_ratings_pdf.php" class="btn btn-danger">Скачать все рейтинги (PDF)</button>
            <button type="submit" formaction="otchet/generate_all_ratings_excel.php" class="btn btn-success">Скачать отчет по специальностям (Excel)</button>
        </form>
    </div>
            <script>
                document.getElementById('reportForm').addEventListener('submit', function(event) {
                    const form = event.target;
                    const action = event.submitter.value; // Определяем, какая кнопка нажата

                    if (action === 'pdf') {
                        form.action = "otchet/generate_pdf.php";
                    } else if (action === 'excel') {
                        form.action = "otchet/generate_excel_spec.php";
                    }
                });
            </script>
                    </form>  

        </div>
    </div>
    <script>
        function showReport() {
            let selectedReport = document.getElementById("reportSelector").value;
            let reportBlocks = document.querySelectorAll(".report-block");

            reportBlocks.forEach(block => {
                block.style.display = "none"; // Скрываем все блоки
            });

            if (selectedReport) {
                document.getElementById(selectedReport).style.display = "block"; // Показываем выбранный
            }
        }
    </script>
<div id = "searchContainer">
    <form id="searchForm" method="GET" action="">
        <input type="text" name="search" id="searchInput" placeholder="Поиск по фамилии и имени..." 
            value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
        <input type="submit" style="display: none;"> <!-- Скрытая кнопка submit -->
    </form>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const searchForm = document.getElementById("searchForm");
    const searchInput = document.getElementById("searchInput");
    
    // Обработчик отправки формы (Enter)
    searchForm.addEventListener("submit", function(e) {
        e.preventDefault();
        const searchQuery = searchInput.value.trim();
        
        // Получаем текущие параметры URL
        const urlParams = new URLSearchParams(window.location.search);
        
        // Обновляем параметр поиска
        if (searchQuery) {
            urlParams.set("search", searchQuery);
        } else {
            urlParams.delete("search");
        }
        
        // Сбрасываем номер страницы на 1 при новом поиске
        urlParams.set("page", "1");
        
        // Перенаправляем с новыми параметрами
        window.location.href = window.location.pathname + "?" + urlParams.toString();
    });
    
    // Опционально: обработка ввода с задержкой
    let searchTimer;
    searchInput.addEventListener("input", function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            searchForm.dispatchEvent(new Event("submit"));
        }, 1000);
    });
});
</script>





<!-- Пагинация -->
<div class="pagination-filter">
    <?php
    // Сохраняем все GET-параметры
    $params = $_GET;
    // Удаляем параметр page, чтобы не дублировать его в ссылках
    unset($params['page']);
    
    // Собираем строку параметров
    $queryString = http_build_query($params);
    
    // Функция генерации ссылки с сохранением параметров
    function pageLink($pageNum, $currentPage, $queryString) {
        $active = $pageNum == $currentPage ? 'active' : '';
        $link = '?page=' . $pageNum . ($queryString ? '&' . $queryString : '');
        return '<a href="' . htmlspecialchars($link) . '" class="' . $active . '">' . htmlspecialchars($pageNum) . '</a>';
    }

    // Предыдущая страница
    if ($page > 1) {
        echo '<a href="?page=' . ($page - 1) . ($queryString ? '&' . $queryString : '') . '">« Предыдущая</a>';
    }

    // Первая страница
    if ($page > 3) {
        echo pageLink(1, $page, $queryString);
        echo '<span>...</span>';
    }

    // Промежуточные страницы
    for ($i = max(1, $page - 1); $i <= min($totalPages, $page + 1); $i++) {
        echo pageLink($i, $page, $queryString);
    }

    // Последняя страница
    if ($page < $totalPages - 1) {
        if ($page + 1 < $totalPages - 1) {
            echo '<span>...</span>';
        }
        echo pageLink($totalPages, $page, $queryString);
    }

    // Следующая страница
    if ($page < $totalPages) {
        echo '<a href="?page=' . ($page + 1) . ($queryString ? '&' . $queryString : '') . '">Следующая »</a>';
    }
    ?>
</div>

</div>




<!-- Всплывающее окно фильтра -->
<div id="filterPopup" class="filter-popup">
    <div class="filter-content">
        <span id="closeFilter" class="close">&times;</span>
        <h3>Фильтр</h3>

        <form id="filterForm" method="GET">
            <!-- Поле поиска для всех категорий -->
            <input type="text" id="searchFilter" class="filter-search" placeholder="Поиск...">

            <div class="checkbox-group-filter">
                <h4>Специальности</h4>
                <?php
                $sql = "SELECT id_prof_spec, code, title, socr FROM profec_spec WHERE kategoriya = '1'";
                $result = $conn->query($sql);
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $checked = in_array($row['id_prof_spec'], $specTitleFilter) ? 'checked' : '';
                        echo "<label class='filter-label'>
                                <input type='checkbox' class='filter-checkbox' name='spec_title_filter[]' value='" . $row['id_prof_spec'] . "' $checked> 
                                <span class='filter-text'>" . htmlspecialchars($row['title']) . " (" . htmlspecialchars($row['socr']) . ")</span>
                            </label>";
                    }
                }
                ?>
            </div>

            <div class="checkbox-group-filter">
                <h4>Профессии</h4>
                <?php
                $sql = "SELECT id_prof_spec, code, title, socr FROM profec_spec WHERE kategoriya = '2'";
                $result = $conn->query($sql);
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $checked = in_array($row['id_prof_spec'], $professionFilter) ? 'checked' : '';
                        echo "<label class='filter-label'>
                                <input type='checkbox' class='filter-checkbox' name='profession_filter[]' value='" . $row['id_prof_spec'] . "' $checked> 
                                <span class='filter-text'>" . htmlspecialchars($row['title']) . " (" . htmlspecialchars($row['socr']) . ")</span>
                            </label>";
                    }
                }
                ?>
            </div>

            <button type="submit" class="apply-filter">Применить</button>
        </form>
    </div>
</div>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        const searchInput = document.getElementById("searchFilter");
        const checkboxes = document.querySelectorAll(".filter-label");

        searchInput.addEventListener("input", function () {
            const query = this.value.toLowerCase();

            checkboxes.forEach(label => {
                const text = label.querySelector(".filter-text").textContent.toLowerCase();
                label.style.display = text.includes(query) ? "block" : "none";
            });
        });
    });
    const openFilter = document.getElementById('openFilter'); // Кнопка открытия фильтра
    const closeFilter = document.getElementById('closeFilter'); // Кнопка закрытия
    const filterPopup = document.getElementById('filterPopup'); // Всплывающее окно

    // Открытие фильтра
    openFilter.addEventListener('click', function () {
        filterPopup.style.display = 'flex'; // Показать окно фильтра
    });

    // Закрытие фильтра
    closeFilter.addEventListener('click', function () {
        filterPopup.style.display = 'none'; // Скрыть окно фильтра
    });

    // Закрытие при клике вне окна
    window.addEventListener('click', function (event) {
        if (event.target === filterPopup) {
            filterPopup.style.display = 'none'; // Скрыть окно, если кликнули вне его
        }
    });
</script>
    <?php if (empty($abitData)) : ?>
    <div class = "no-zayav">
        <p>Нет заявлений для отображения</p>
    </div>
    <?php else : ?>

    <table id = "table_zayav">
    <thead>
        <tr>
            <th class="th1">№ абитуриента</th>
            <th>Фамилия</th>
            <th>Имя</th>
            <th>Отчество</th>
            <th>Дата рождения</th>
            <th class="th2">СНИЛС</th>
            <th>Специальность</th>
            <th>Профессия</th>
            <th>Сокращение</th>
            <th>Дата и время подачи</th>
            <th>Оригинал</th> <!-- Новый столбец для флажков -->
            <th>Действия</th> <!-- Column for buttons -->
        </tr>
    </thead>
    <tbody>
    <?php
foreach ($abitData as $idAbit => $data) {
    $rowCount = count($data['zayavleniya']);
    $info = $data['info'];

    // Выводим обёртку для абитуриента
    echo "<tr class='abit-border-top'><td colspan='12'></td></tr>";

    // Первая строка (абитуриент + первое заявление)
    $firstZayav = array_shift($data['zayavleniya']);

    echo "<tr>";
        echo "<td rowspan='$rowCount' class='td-id'>
            <div class='td-id-content'>" . htmlspecialchars($idAbit) . "
            <textarea 
                class='notes-textarea' 
                data-abit-id='" . $idAbit . "' 
                placeholder='Примечания...'>" . htmlspecialchars($data['last_comment'] ?? '') . "</textarea>
            </div>
        </td>";
    


    echo "<td rowspan='$rowCount'>" . htmlspecialchars($data['familiya']) . "</td>";
    echo "<td rowspan='$rowCount'>" . htmlspecialchars($data['imya']) . "</td>";
    echo "<td rowspan='$rowCount'>" . htmlspecialchars($data['otchestvo']) . "</td>";
    echo "<td rowspan='$rowCount'>" . date("d.m.Y", strtotime($data['date_bd'])) . "</td>";
    echo "<td rowspan='$rowCount'>" . htmlspecialchars($data['snils']) . "</td>";

    echo "<td>" . $firstZayav['spec_title'] . "</td>";
    echo "<td>" . $firstZayav['profession'] . "</td>";
    echo "<td>" . htmlspecialchars($firstZayav['socr']) . "</td>";
    echo "<td>" . date("d.m.Y H:i:s", strtotime($firstZayav['date'])) . "</td>";

    $checked = $firstZayav['original'] == 1 ? 'checked' : '';
    echo "<td><input type='checkbox' class='checkbox-original' data-id='" . $firstZayav['id_zayav'] . "' data-abit-id='" . $idAbit . "' $checked disabled></td>";

    echo "<td>";
    echo '<button class="btn btn-info view-details" data-id="' . $firstZayav['id_zayav'] . '">Редактировать</button>';
    echo '<button class="btn btn-warning change-spec" data-id="' . $firstZayav['id_zayav'] . '">Изменить специальность</button>';
    echo "<a href='otchet/print_application.php?id=" . $firstZayav['id_zayav'] . "' class='button-print'>Печать</a>";
    echo "</td>";
    echo "</tr>";

    // Остальные заявления
    foreach ($data['zayavleniya'] as $zayav) {
        echo "<tr>";
        echo "<td>" . $zayav['spec_title'] . "</td>";
        echo "<td>" . $zayav['profession'] . "</td>";
        echo "<td>" . htmlspecialchars($zayav['socr']) . "</td>";
        echo "<td>" . date("d.m.Y H:i:s", strtotime($zayav['date'])) . "</td>";

        $checked = $zayav['original'] == 1 ? 'checked' : '';
        echo "<td><input type='checkbox' class='checkbox-original' data-id='" . $zayav['id_zayav'] . "' data-abit-id='" . $idAbit . "' $checked disabled></td>";

        echo "<td>";
        echo '<button class="btn btn-info view-details" data-id="' . $zayav['id_zayav'] . '">Редактировать</button>';
        echo '<button class="btn btn-warning change-spec" data-id="' . $zayav['id_zayav'] . '">Изменить специальность</button>';
        echo "<a href='otchet/print_application.php?id=" . $zayav['id_zayav'] . "' class='button-print'>Печать</a>";
        echo "</td>";
        echo "</tr>";
    }

    // Закрывающая граница
    echo "<tr class='abit-border-bottom'><td colspan='12'></td></tr>";
}
?>
    </tbody>
</table>
<!-- Модальное окно -->
<div id="modal" class="modal">
  <div class="modal-content">
    <span id="closeData" class="close">&times;</span>
    <div id="modal-content">Загрузка...</div>
  </div>
</div>


<script>
$(document).on('click', '.view-details', function () {
    const id = $(this).data('id');
    $('#modal').show();
    $('#modal-content').html('Загрузка...');

    $.ajax({
        url: 'load/ajax_vse_dannye.php',
        type: 'GET',
        data: { id_zayav: id },
        success: function (data) {
            $('#modal-content').html(data);
        },
        error: function () {
            $('#modal-content').html('Ошибка загрузки данных.');
        }
    });
});

$(document).on('submit', '#editForm', function (e) {
    e.preventDefault();
    const form = $(this);
    const formData = form.serialize();

    $.ajax({
        url: 'load/ajax_vse_dannye.php',
        type: 'POST',
        data: formData,
        dataType: 'json', // ожидаем JSON
        success: function (response) {
            if (response.success) {
                $('#modal').hide();
                showMessage(response.message || 'Данные успешно обновлены!', 'success');
                // Обновляем страницу через 1 секунду (1000 мс)
                setTimeout(() => location.reload(), 500);
            } else {
                showMessage(response.message || 'Ошибка при обновлении данных', 'error');
            }
        },
        error: function () {
            showMessage('Ошибка соединения с сервером', 'error');
        }
    });
});



document.getElementById('closeData').addEventListener('click', function () {
    document.getElementById('modal').style.display = 'none';
});
// Обработчик для кнопки "Изменить специальность"
$(document).on('click', '.change-spec', function() {
    const zayavId = $(this).data('id');
    $('#zayavIdForChange').val(zayavId);
    
    // Очищаем предыдущий выбор
    $('#newSpec').val('');
    
    // Показываем модальное окно
    $('#changeSpecModal').css('display', 'flex');
});

// Закрытие модального окна
$('#closeChangeSpec').on('click', function() {
    $('#changeSpecModal').hide();
});

// Закрытие при клике вне окна
$(window).on('click', function(event) {
    if ($(event.target).is('#changeSpecModal')) {
        $('#changeSpecModal').hide();
    }
});

// Обработчик для кнопки "Изменить специальность"
$(document).on('click', '.change-spec', function() {
    const zayavId = $(this).data('id');
    console.log('Изменение специальности для заявления:', zayavId);
    
    // Проверяем, что модальное окно существует
    if ($('#changeSpecModal').length === 0) {
        console.error('Модальное окно для изменения специальности не найдено!');
        return;
    }
    
    $('#zayavIdForChange').val(zayavId);
    $('#newSpec').val(''); // Сбрасываем выбор
    
    // Показываем модальное окно
    $('#changeSpecModal').css({
        'display': 'flex',
        'opacity': 0
    }).animate({'opacity': 1}, 200);
});

// Закрытие модального окна
$(document).on('click', '#closeChangeSpec', function(e) {
    e.preventDefault();
    $('#changeSpecModal').animate({'opacity': 0}, 200, function() {
        $(this).css('display', 'none');
    });
});

// Закрытие при клике вне окна
$(document).on('click', function(e) {
    if ($(e.target).is('#changeSpecModal')) {
        $('#changeSpecModal').animate({'opacity': 0}, 200, function() {
            $(this).css('display', 'none');
        });
    }
});

// Обработка формы изменения специальности
$(document).on('submit', '#changeSpecForm', function(e) {
    e.preventDefault();
    
    const zayavId = $('#zayavIdForChange').val();
    const newSpecId = $('#newSpec').val();

    if (!newSpecId) {
        showMessage('Пожалуйста, выберите специальность', 'error');
        return;
    }

    console.log("Отправка данных на сервер:", { 
        zayav_id: zayavId, 
        new_spec_id: newSpecId 
    });

    // Блокируем кнопку отправки
    $('#changeSpecForm button[type="submit"]')
        .prop('disabled', true)
        .text('Сохранение...');

    $.ajax({
        url: 'save/change_spec.php',
        type: 'POST',
        data: {
            zayav_id: zayavId,
            new_spec_id: newSpecId
        },
        dataType: 'json',
        success: function(response) {
            console.log("Ответ сервера:", response);
            
            if (response.success) {
                showMessage('Специальность успешно изменена!', 'success');
                $('#changeSpecModal').hide();
                
                // Обновляем страницу через 1 секунду
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                showMessage(response.message || 'Ошибка при изменении специальности', 'error');
            }
        },
        error: function(xhr, status, error) {
            console.error("Ошибка AJAX:", status, error);
            showMessage('Ошибка соединения с сервером: ' + error, 'error');
        },
        complete: function() {
            // Разблокируем кнопку в любом случае
            $('#changeSpecForm button[type="submit"]')
                .prop('disabled', false)
                .text('Сохранить изменения');
        }
    });
});
</script>


<?php endif; ?>

<script>
document.querySelectorAll('.notes-textarea').forEach(textarea => {
    textarea.addEventListener('input', function() {
        const abitId = this.dataset.abitId;
        const textareaElement = this;
        const originalValue = this.value; // Сохраняем исходное значение без trim()
        
        // Добавляем задержку перед сохранением
        clearTimeout(this.timer);
        this.timer = setTimeout(() => {
            fetch('save/save_comment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `abit_id=${encodeURIComponent(abitId)}&comment=${encodeURIComponent(originalValue)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Обновляем только если значение не изменилось за время запроса
                    if (textareaElement.value === originalValue) {
                        textareaElement.value = data.comment || originalValue;
                    }
                    showMessage('Примечание сохранено', 'success');
                } else {
                    console.error('Ошибка:', data.message);
                    showMessage('Ошибка при сохранении: ' + (data.message || 'неизвестная ошибка'), 'error');
                }
            })
            .catch(error => {
                console.error('Ошибка сети:', error);
                showMessage('Ошибка сети', 'error');
            });
        }, 2000); // 2 секунды задержки
    });
});

// Функция для отображения сообщений
function showMessage(text, type) {
    let messageBox = document.createElement('div');
    messageBox.textContent = text;
    messageBox.classList.add('message', type);

    document.body.appendChild(messageBox);

    setTimeout(() => {
        messageBox.style.opacity = '1';
    }, 100);

    setTimeout(() => {
        messageBox.style.opacity = '0';
        setTimeout(() => messageBox.remove(), 500);
    }, 3000);
}

document.addEventListener('DOMContentLoaded', function() {
    // Обработчик для активации флажков
    document.getElementById('activateCheckboxes').addEventListener('click', function () {
        // Находим все флажки
        const checkboxes = document.querySelectorAll('.checkbox-original');
        checkboxes.forEach(checkbox => {
            checkbox.disabled = false; // Разблокировать флажки
        });

        // Показать кнопку сохранения
        document.getElementById('saveChanges').style.display = 'inline-block';

        // Навешиваем обработчики на флажки
        updateOriginalCheckboxes();
    });

    // Функция для обновления флажков
    function updateOriginalCheckboxes() {
    const checkboxes = document.querySelectorAll('.checkbox-original');

    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function () {
            const abitId = this.getAttribute('data-abit-id');

            if (this.checked) {
                // Найти только чекбоксы с тем же абитуриентом
                const sameAbitCheckboxes = document.querySelectorAll(
                    `.checkbox-original[data-abit-id="${abitId}"]`
                );
                sameAbitCheckboxes.forEach(cb => {
                    if (cb !== this) cb.checked = false;
                });
            }
        });
    });
}


    // Обработчик для кнопки сохранения
    document.getElementById('saveChanges').addEventListener('click', function () {
        const checkboxes = document.querySelectorAll('.checkbox-original');
        const changes = [];

        checkboxes.forEach(checkbox => {
            const zayavId = checkbox.getAttribute('data-id');
            const originalValue = checkbox.checked ? 1 : 0; // Получаем состояние флажка

            // Добавляем изменения в массив
            changes.push({ zayavId, originalValue });
        });

        // Отправляем данные на сервер
        fetch('save/update_original.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ changes })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showMessage('Изменения успешно сохранены!', 'success');

                checkboxes.forEach(checkbox => {
                    checkbox.disabled = true;
                });

                document.getElementById('saveChanges').style.display = 'none';
            } else {
                showMessage('Ошибка при сохранении изменений.', 'error');
            }
        })
        .catch(error => {
            console.error('Ошибка:', error);
            showMessage('Произошла ошибка при отправке запроса.', 'error');
        });
        });
    });

// JavaScript для отправки запроса на генерацию рейтинга
document.getElementById('generateRating').addEventListener('click', function() {
    fetch('zayavleniya.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'generate_rating=1'
    })
    .then(response => response.blob())
    .then(data => {
        // Загружаем PDF
        const link = document.createElement('a');
        link.href = URL.createObjectURL(data);
        link.download = 'otchet/rating.pdf';
        link.click();
    })
    .catch(error => console.error('Ошибка при генерации рейтинга:', error));
});
</script>
<!-- Модальное окно для изменения специальности -->
<div id="changeSpecModal" class="modal" style="display: none;">
  <div class="modal-content">
    <span id="closeChangeSpec" class="close">&times;</span>
    <h3>Изменение специальности</h3>
    <div id="changeSpecContent">
      <form id="changeSpecForm">
        <input type="hidden" id="zayavIdForChange" value="">
        <div class="form-group">
          <label for="newSpec">Выберите новую специальность:</label>
          <select id="newSpec" class="form-control" required>
            <option value="">-- Выберите специальность --</option>
            <?php foreach ($specialties as $row): ?>
              <option value="<?= $row['id_prof_spec'] ?>">
                <?= $row['title'] ?> (<?= $row['socr'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">Сохранить изменения</button>
      </form>
    </div>
  </div>
</div>

</body>
</html>


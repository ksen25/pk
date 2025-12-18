<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Установка кодировки UTF-8 для PHP
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

$host = 'localhost';
$user = 'root';
$password = 'root';
$dbname = 'pk_2025';
$conn = new mysqli($host, $user, $password, $dbname);

// Установка кодировки UTF-8 для соединения с БД
$conn->set_charset("utf8");

if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

// Гарантируем наличие поля issue_note для отметок "Документы выданы"
$colCheckIssue = $conn->query("SHOW COLUMNS FROM zayav LIKE 'issue_note'");
if ($colCheckIssue && $colCheckIssue->num_rows === 0) {
    $conn->query("ALTER TABLE zayav ADD COLUMN issue_note TEXT NULL");
}

// В начало файла, после обработки reset_filter
if (isset($_GET['reset_all'])) {
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// Получение фильтров
$specTitleFilter = isset($_GET['spec_title_filter']) ? array_map('intval', $_GET['spec_title_filter']) : [];
$professionFilter = isset($_GET['profession_filter']) ? array_map('intval', $_GET['profession_filter']) : [];
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

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
// Фильтр по дате подачи заявления
if ($dateFrom !== '' && $dateTo !== '') {
    $zayavWhere[] = "DATE(zayav.date) BETWEEN '$dateFrom' AND '$dateTo'";
} elseif ($dateFrom !== '') {
    $zayavWhere[] = "DATE(zayav.date) >= '$dateFrom'";
} elseif ($dateTo !== '') {
    $zayavWhere[] = "DATE(zayav.date) <= '$dateTo'";
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

// Добавляем условия для заявлений
$zayavWhereClause = '';
if (!empty($zayavWhere)) {
    $zayavWhereClause = " AND (" . implode(" AND ", $zayavWhere) . ")";
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
// Запрос на выборку данных с фильтрацией по заявлениям
// Вместо группировки по абитуриентам, получаем все заявления с фильтрами
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
WHERE 1
" . (!empty($specTitleFilter) ? " AND zayav.id_spec_prof IN (" . implode(",", $specTitleFilter) . ")" : "") . 
(!empty($professionFilter) ? " AND zayav.id_spec_prof IN (" . implode(",", $professionFilter) . ")" : "") . 
(!empty($dateFrom) ? " AND DATE(zayav.date) >= '$dateFrom'" : "") . 
(!empty($dateTo) ? " AND DATE(zayav.date) <= '$dateTo'" : "") . "
GROUP BY abit.id_abit, abit.familiya, abit.imya, abit.otchestvo, abit.snils, abit.date_bd
ORDER BY latest_date DESC
LIMIT ? OFFSET ?
";

// Тогда каждая строка - отдельное заявление, а не абитуриент
// Но нужно переделать вывод таблицы


$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

// Подсчёт общего количества записей С УЧЁТОМ ФИЛЬТРОВ
$sqlCount = "
SELECT COUNT(DISTINCT abit.id_abit) AS total
FROM abit
JOIN zayav ON abit.id_abit = zayav.id_abitur
JOIN profec_spec ON zayav.id_spec_prof = profec_spec.id_prof_spec
JOIN kat_spec_prof ON profec_spec.kategoriya = kat_spec_prof.id_kat
WHERE 1 $sqlWhere $zayavWhereClause
";

$resultCount = $conn->query($sqlCount);
$totalAbit = $resultCount ? $resultCount->fetch_assoc()['total'] : 0;
$totalPages = ceil($totalAbit / $limit);
$abitIds = array_column($result->fetch_all(MYSQLI_ASSOC), 'id_abit');

if (!empty($abitIds)) {
    $idsIn = implode(",", array_map('intval', $abitIds));
    
    // Используем те же условия фильтрации, что и в основном запросе
    $zayavWhereQuery = '';
    if (!empty($zayavWhere)) {
        $zayavWhereQuery = " AND (" . implode(" AND ", $zayavWhere) . ")";
    }
    
    // Условия для специальностей/профессий
    $specWhereQuery = '';
    if (!empty($specTitleFilter) && !empty($professionFilter)) {
        $specWhereQuery = " AND (zayav.id_spec_prof IN (" . implode(",", $specTitleFilter) . ") OR zayav.id_spec_prof IN (" . implode(",", $professionFilter) . "))";
    } elseif (!empty($specTitleFilter)) {
        $specWhereQuery = " AND zayav.id_spec_prof IN (" . implode(",", $specTitleFilter) . ")";
    } elseif (!empty($professionFilter)) {
        $specWhereQuery = " AND zayav.id_spec_prof IN (" . implode(",", $professionFilter) . ")";
    }
    
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
        zayav.issue_note,   
        zayav.group_id,
        groups.name as group_name,
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
    LEFT JOIN `groups` ON zayav.group_id = groups.id
    WHERE abit.id_abit IN ($idsIn) $zayavWhereQuery $specWhereQuery
    ORDER BY zayav.date DESC
    ";
    
    $zayavResult = $conn->query($zayavQuery);
    if (!$zayavResult) {
        die("Ошибка запроса: " . $conn->error);
    }

    $abitData = [];

    while ($row = $zayavResult->fetch_assoc()) {
        $idAbit = $row['id_abit'];
        
        if (!isset($abitData[$idAbit])) {
            $abitData[$idAbit] = [
                'familiya' => $row['familiya'],
                'imya' => $row['imya'],
                'otchestvo' => $row['otchestvo'],
                'snils' => $row['snils'],
                'date_bd' => $row['date_bd'],
                'last_comment' => $row['last_comment'],
                'group_name' => $row['group_name'], // Добавляем группу для абитуриента
                'zayavleniya' => []
            ];
        }
        
        $abitData[$idAbit]['zayavleniya'][] = [
            'code' => $row['code'],
            'spec_title' => $row['kategoriya'] === 'Специальность' ? $row['spec_title'] : '-',
            'profession' => $row['kategoriya'] === 'Профессия' ? $row['spec_title'] : '-',
            'socr' => $row['socr'],
            'date' => $row['date'],
            'id_zayav' => $row['id_zayav'],
            'original' => $row['original'],
            'comment' => $row['comment'],
            'issue_note' => $row['issue_note'],
            'group_id' => $row['group_id'],
            'group_name' => $row['group_name']
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
        <img src="img/ykuipt_logo.png" alt="Логотип"><h1>Админ панель</h1>
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
            <!-- Кнопка "Сбросить все фильтры" (будет показываться при наличии любых фильтров) -->
            <?php 
            // Проверяем, есть ли активные фильтры
            $hasActiveFilters = (
                !empty($specTitleFilter) || 
                !empty($professionFilter) || 
                !empty($dateFrom) || 
                !empty($dateTo) || 
                !empty($search)
            );
            
            if ($hasActiveFilters): ?>
                <a href="administrator.php" class="button-reset" style="background: #dc3545; text-decoration: none; color: white; padding: 8px 12px; border-radius: 4px; display: inline-flex; align-items: center; gap: 5px;">
                    <span style="font-size: 18px; color: white;">×</span> Сбросить все фильтры
                </a>
            <?php endif; ?>
        </div>
        <div class = "otchet">
<!-- Кнопка импорта заявлений -->
    <div style="margin-bottom: 20px;">
        <button id="importBtn" class="btn btn-primary">Импорт заявлений из Word</button>
    </div>
    <!-- Добавьте рядом с другими кнопками -->
    <button id="distributeGroups" class="button">Распределить по группам</button>
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
        <option value="dailyReport">Ежедневный отчет</option>
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
    <!-- Добавьте этот блок в раздел отчетов -->
    <div id="dailyReport" class="report-block" style="display:none;">
        <form method="get" action="otchet/generate_daily_excel.php">
            <button type="submit" class="btn btn-primary">Скачать ежедневный отчет</button>
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
<div id="searchContainer">
    <input type="text"
           id="searchInput"
           placeholder="Поиск по фамилии и имени...">
</div>


<!-- Модальное окно распределения по группам -->
<div id="distributeModal" class="modal" style="display: none;">
  <div class="modal-content" style="max-width: 900px;">
    <span class="close" onclick="document.getElementById('distributeModal').style.display='none'">&times;</span>
    <h2>Распределение по группам</h2>
    <form id="distributeForm">
      <!-- Выбор специальности -->
      <div class="form-group">
        <label for="specForGroup">Специальность:</label>
        <select id="specForGroup" name="spec_id" class="form-control" required>
          <option value="">-- Выберите специальность --</option>
          <?php
          $sql = "SELECT ps.id_prof_spec, ps.code, ps.title, ps.socr, ps.class, ps.forma_obuch 
                  FROM profec_spec ps 
                  ORDER BY ps.code";
          $result = $conn->query($sql);
          while ($row = $result->fetch_assoc()) {
            $formaText = $row['forma_obuch'] == 1 ? 'очная' : 'заочная';
            $classText = $row['class'] == 1 ? '9 классов' : '11 классов';
            echo '<option value="' . $row['id_prof_spec'] . '" data-class="' . $row['class'] . '" data-forma="' . $row['forma_obuch'] . '">' . 
                 htmlspecialchars($row['socr']) . ' (' . $row['code'] . ') - ' . $classText . ', ' . $formaText . '</option>';
          }
          ?>
        </select>
      </div>
      
      <!-- Поле для выбора/ввода группы -->
      <div class="form-group">
        <label>Группа:</label>
        <div style="display: flex; gap: 10px; align-items: center;">
          <select id="existingGroup" name="existing_group" class="form-control" style="flex: 1;">
            <option value="">-- Выберите существующую группу --</option>
            <!-- Группы будут загружены через AJAX -->
          </select>
          <span>или</span>
          <input type="text" id="newGroup" name="new_group" class="form-control" style="flex: 1;" 
                 placeholder="Введите новую группу (например, ИП1-11)">
        </div>
        <small class="form-text text-muted">
          Если ввести название существующей группы, будет использована существующая. Если название новое - создастся новая группа.
        </small>
      </div>
      
      <!-- Список абитуриентов -->
      <div class="form-group">
        <h3>Абитуриенты для распределения</h3>
        <div id="applicantsList" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">
          <p>Выберите специальность, чтобы увидеть список абитуриентов с оригиналами</p>
        </div>
        <div style="margin-top: 10px;">
          <label class = "all_raspr_lbl"><input type="checkbox" id="selectAllApplicants" class = "all_raspr"> Выбрать всех</label>
        </div>
      </div>
      
      <button type="submit" class="btn btn-primary">Распределить</button>
      <button type="button" class="btn btn-secondary" onclick="document.getElementById('distributeModal').style.display='none'">Отмена</button>
    </form>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const searchInput = document.getElementById("searchInput");

    searchInput.addEventListener("input", function () {
        const query = this.value.toLowerCase().trim();
  
  // ОТП АВКА AJAX ЗАПРОСА НА СЕРВЕР
  const urlParams = new URLSearchParams(window.location.search);
  if (this.value.trim() !== '') {
    urlParams.set('search', this.value.trim());
  } else {
    urlParams.delete('search');
  }
  urlParams.set('page', '1'); // Сбрасываем на первую страницу
  
  // Выполняем AJAX запрос
  fetch('administrator.php?' + urlParams.toString() + '&ajax=1')
    .then(response => response.text())
    .then(html => {
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');
      
      // Обновляем таблицу
      const newTable = doc.querySelector('table#table_zayav');
      const oldTable = document.querySelector('table#table_zayav');
      if (newTable && oldTable) {
        oldTable.innerHTML = newTable.innerHTML;
      }
      
      // Обновляем пагинацию
      const newPagination = doc.
                
      // Сохраняем значение поля поиска
      const searchValue = searchInput.value;querySelector('.pagination-filter');
      const oldPagination = document.querySelector('.pagination-filter');
      if (newPagination && oldPagination) {
        oldPagination.innerHTML = newPagination.innerHTML;
      }
    })
    .catch(error => console.error('Error:', error));          
  // Основное исправление: сброс пагинации на 1 страницу
  const paginationItems = document.querySelectorAll('.pagination-filter a, .pagination-filter button');
  paginationItems.forEach(item => {
    const href = item.getAttribute('href');
    if ((item.textContent.trim() === '1' && !item.classList.contains('active')) || (href && href.includes('page=1'))) {
      item.click();
    }
  });
              
      // Восстанавливаем значение поля поиска после обновления
      const newSearchInput = document.getElementById('searchInput');
      if (newSearchInput) {
        newSearchInput.value = searchValue;
      }
        
        const rows = document.querySelectorAll("#table_zayav tbody tr");
        let currentBlock = [];
        let currentFio = "";

        rows.forEach(row => {

            // Начало блока абитуриента
            if (row.classList.contains("abit-border-top")) {
                currentBlock = [row];
                currentFio = "";
                return;
            }

            // Основная строка абитуриента
            if (row.classList.contains("abit-row")) {
                currentFio = row.dataset.fio || "";
                currentBlock.push(row);
                return;
            }

            // Конец блока
            if (row.classList.contains("abit-border-bottom")) {
                currentBlock.push(row);

                const show = currentFio.includes(query);

                currentBlock.forEach(r => {
                    r.style.display = show ? "" : "none";
                });

                currentBlock = [];
                currentFio = "";
                return;
            }

            // Промежуточные строки (заявления)
            currentBlock.push(row);
        });
    });
});

// Открытие модального окна распределения
    document.getElementById('distributeGroups').addEventListener('click', function() {
      document.getElementById('distributeModal').style.display = 'flex';
      // Очищаем поля при открытии
      document.getElementById('specForGroup').value = '';
      document.getElementById('existingGroup').innerHTML = '<option value="">-- Выберите существующую группу --</option>';
      document.getElementById('newGroup').value = '';
      document.getElementById('applicantsList').innerHTML = '<p>Выберите специальность, чтобы увидеть список абитуриентов с оригиналами</p>';
    });

    // При выборе специальности загружаем группы и абитуриентов
    document.getElementById('specForGroup').addEventListener('change', function() {
      const specId = this.value;
      const selectedOption = this.options[this.selectedIndex];
      const classNum = selectedOption ? selectedOption.getAttribute('data-class') : '';
      const forma = selectedOption ? selectedOption.getAttribute('data-forma') : '';
      
      console.log('Загрузка данных для:', {
        specId: specId,
        classNum: classNum,
        forma: forma
      });
      
      if (!specId) return;
      
      // Загружаем существующие группы для выбранной специальности
      fetch('load/get_groups.php?spec_id=' + specId + '&class=' + classNum + '&forma=' + forma)
        .then(response => response.json())
        .then(data => {
          console.log('Получены группы:', data);
          const groupSelect = document.getElementById('existingGroup');
          groupSelect.innerHTML = '<option value="">-- Выберите существующую группу --</option>';
          
          if (data.groups && data.groups.length > 0) {
            data.groups.forEach(group => {
              const option = document.createElement('option');
              option.value = group.id;
              option.textContent = group.name;
              groupSelect.appendChild(option);
            });
          } else {
            console.log('Нет групп для этой специальности');
          }
        })
        .catch(error => {
          console.error('Ошибка загрузки групп:', error);
        });
      
      // Загружаем абитуриентов для выбранной специальности
      fetch('load/get_applicants_for_group.php?spec_id=' + specId + '&class=' + classNum + '&forma=' + forma)
        .then(response => response.json())
        .then(data => {
          console.log('Получены абитуриенты:', data);
          const container = document.getElementById('applicantsList');
          if (data.applicants.length === 0) {
            container.innerHTML = '<p>Нет абитуриентов с оригиналами для этой специальности.</p>';
          } else {
            let html = '<div class="applicants-grid">';
            data.applicants.forEach(applicant => {
              const checked = applicant.group_id ? 'checked disabled' : '';
              const groupInfo = applicant.group_name ? ` (в группе: ${applicant.group_name})` : '';
              html += `
                <label style="display: block; padding: 5px; border-bottom: 1px solid #eee;">
                  <input type="checkbox" name="applicants[]" value="${applicant.id_zayav}" ${checked} class="applicant-checkbox">
                  ${applicant.familiya} ${applicant.imya} ${applicant.otchestvo} 
                  <small style="color: #666;">СНИЛС: ${applicant.snils}${groupInfo}</small>
                </label>
              `;
            });
            html += '</div>';
            container.innerHTML = html;
          }
        })
        .catch(error => {
          console.error('Ошибка загрузки абитуриентов:', error);
        });
    });

    // Синхронизация полей выбора и ввода группы
    document.getElementById('existingGroup').addEventListener('change', function() {
      if (this.value) {
        // При выборе существующей группы - очищаем поле ввода новой
        document.getElementById('newGroup').value = '';
      }
    });

    document.getElementById('newGroup').addEventListener('input', function() {
      if (this.value.trim()) {
        // При вводе новой группы - сбрасываем выбор существующей
        document.getElementById('existingGroup').value = '';
      }
    });

    // Выбрать всех абитуриентов
    document.getElementById('selectAllApplicants').addEventListener('change', function() {
      const checkboxes = document.querySelectorAll('.applicant-checkbox:not(:disabled)');
      checkboxes.forEach(checkbox => {
        checkbox.checked = this.checked;
      });
    });

    // Обработка отправки формы распределения
    document.getElementById('distributeForm').addEventListener('submit', function(e) {
      e.preventDefault();
      console.log('Форма распределения отправлена');
      
      const specSelect = document.getElementById('specForGroup');
      const specId = specSelect.value;
      console.log('specId:', specId);
      
      if (!specId) {
        alert('Выберите специальность');
        return;
      }
      
      const selectedOption = specSelect.options[specSelect.selectedIndex];
      const classNum = selectedOption ? selectedOption.getAttribute('data-class') || '' : '';
      const forma = selectedOption ? selectedOption.getAttribute('data-forma') || '' : '';
      console.log('class:', classNum, 'forma:', forma);
      
      const existingGroup = document.getElementById('existingGroup').value;
      const newGroup = document.getElementById('newGroup').value.trim();
      console.log('existingGroup:', existingGroup, 'newGroup:', newGroup);
      
      // Проверяем, что указана либо существующая группа, либо новое название
      if (!existingGroup && !newGroup) {
        alert('Выберите существующую группу или введите новую');
        return;
      }
      
      const applicants = [];
      const checkboxes = document.querySelectorAll('input[name="applicants[]"]:checked:not(:disabled)');
      console.log('Найдено чекбоксов:', checkboxes.length);
      
      checkboxes.forEach(checkbox => {
        applicants.push(checkbox.value);
      });
      
      if (applicants.length === 0) {
        alert('Выберите хотя бы одного абитуриента');
        return;
      }
      
      console.log('Выбранные абитуриенты:', applicants);
      
      const formData = new FormData();
      formData.append('spec_id', specId);
      formData.append('class', classNum);
      formData.append('forma', forma);
      formData.append('existing_group', existingGroup);
      formData.append('new_group', newGroup);
      
      applicants.forEach(applicantId => {
        formData.append('applicants[]', applicantId);
      });
      
      const submitBtn = this.querySelector('button[type="submit"]');
      const originalText = submitBtn.textContent;
      submitBtn.textContent = 'Распределение...';
      submitBtn.disabled = true;
      
      console.log('Отправка запроса на save/distribute_groups.php');
      
      fetch('save/distribute_groups.php', {
        method: 'POST',
        body: formData
      })
      .then(response => {
        console.log('Получен ответ, статус:', response.status, response.statusText);
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
      })
      .then(text => {
        console.log('Полный ответ сервера:', text);
        try {
          const data = JSON.parse(text);
          console.log('Распарсенный JSON:', data);
          
          if (data.success) {
            showMessage('Распределение успешно сохранено', 'success');
            document.getElementById('distributeModal').style.display = 'none';
            setTimeout(() => location.reload(), 1000);
          } else {
            showMessage('Ошибка: ' + (data.message || 'Неизвестная ошибка'), 'error');
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
          }
        } catch (e) {
          console.error('Ошибка парсинга JSON:', e);
          console.error('Ответ был:', text);
          showMessage('Ошибка: неверный формат ответа сервера', 'error');
          submitBtn.textContent = originalText;
          submitBtn.disabled = false;
        }
      })
      .catch(error => {
        console.error('Ошибка сети:', error);
        showMessage('Ошибка сети: ' + error.message, 'error');
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
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
    
    // Удаляем параметр reset_filter, если он есть
    if (isset($params['reset_filter'])) {
        unset($params['reset_filter']);
    }
    
    // Собираем строку параметров
    $queryString = '';
    if (!empty($params)) {
        $queryString = http_build_query($params);
    }
    
    // Функция генерации ссылки с сохранением параметров
    function pageLink($pageNum, $currentPage, $queryString) {
        $active = $pageNum == $currentPage ? 'active' : '';
        if ($queryString) {
            $link = '?page=' . $pageNum . '&' . $queryString;
        } else {
            $link = '?page=' . $pageNum;
        }
        return '<a href="' . htmlspecialchars($link) . '" class="' . $active . '">' . htmlspecialchars($pageNum) . '</a>';
    }

    // Если нет данных для пагинации, не показываем её
    if ($totalPages <= 0) {
        echo '<div style="display:none"></div>';
    } else {
        // Предыдущая страница
        if ($page > 1) {
            if ($queryString) {
                echo '<a href="?page=' . ($page - 1) . '&' . $queryString . '">« Предыдущая</a>';
            } else {
                echo '<a href="?page=' . ($page - 1) . '">« Предыдущая</a>';
            }
        }

        // Показываем ограниченное количество страниц вокруг текущей
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        
        // Первая страница
        if ($startPage > 1) {
            echo pageLink(1, $page, $queryString);
            if ($startPage > 2) {
                echo '<span>...</span>';
            }
        }

        // Промежуточные страницы
        for ($i = $startPage; $i <= $endPage; $i++) {
            echo pageLink($i, $page, $queryString);
        }

        // Последняя страница
        if ($endPage < $totalPages) {
            if ($endPage < $totalPages - 1) {
                echo '<span>...</span>';
            }
            echo pageLink($totalPages, $page, $queryString);
        }

        // Следующая страница
        if ($page < $totalPages) {
            if ($queryString) {
                echo '<a href="?page=' . ($page + 1) . '&' . $queryString . '">Следующая »</a>';
            } else {
                echo '<a href="?page=' . ($page + 1) . '">Следующая »</a>';
            }
        }
    }
    ?>
</div>

</div>

<!-- Всплывающее окно фильтра -->
<div id="filterPopup" class="filter-popup">
    <div class="filter-content" style="width: auto; max-width: 1200px; padding: 20px;">
        <span id="closeFilter" class="close">&times;</span>
        <h3 style="margin-bottom: 20px;">Фильтр заявлений</h3>

        <form id="filterForm" method="GET">
            <div style="display: flex; gap: 30px; min-width: 900px;">
                
                <!-- Колонка 1: Специальности -->
                <div style="flex: 0 0 300px;">
                    <div class="checkbox-group-filter" style="max-height: 400px; overflow-y: auto; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <h4 style="margin: 0;">Специальности</h4>
                            <label style="font-size: 12px;">
                                <input type="checkbox" class="select-all-spec"> Выбрать все
                            </label>
                        </div>
                        
                        <!-- Поиск по специальностям -->
                        <div style="margin-bottom: 10px;">
                            <input type="text" class="spec-search" placeholder="Поиск в специальностях..." 
                                   style="width: 100%; padding: 6px; border: 1px solid #ccc; border-radius: 3px; font-size: 13px;">
                        </div>
                        
                        <div id="specList">
                            <?php
                            $sql = "SELECT id_prof_spec, code, title, socr FROM profec_spec WHERE kategoriya = '1' ORDER BY socr";
                            $result = $conn->query($sql);
                            if ($result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    $checked = in_array($row['id_prof_spec'], $specTitleFilter) ? 'checked' : '';
                                    $displayText = htmlspecialchars($row['socr'] . ' (' . $row['code'] . ')');
                                    echo "<label class='filter-label' style='display: flex; align-items: center; padding: 4px 0; cursor: pointer;'>
                                            <input type='checkbox' class='filter-checkbox spec-checkbox' name='spec_title_filter[]' value='" . $row['id_prof_spec'] . "' $checked style='margin-right: 8px;'>
                                            <span class='filter-text' style='font-size: 13px;'>" . $displayText . "</span>
                                          </label>";
                                }
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <!-- Колонка 2: Профессии -->
                <div style="flex: 0 0 300px;">
                    <div class="checkbox-group-filter" style="max-height: 400px; overflow-y: auto; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <h4 style="margin: 0;">Профессии</h4>
                            <label style="font-size: 12px;">
                                <input type="checkbox" class="select-all-prof"> Выбрать все
                            </label>
                        </div>
                        
                        <!-- Поиск по профессиям -->
                        <div style="margin-bottom: 10px;">
                            <input type="text" class="prof-search" placeholder="Поиск в профессиях..." 
                                   style="width: 100%; padding: 6px; border: 1px solid #ccc; border-radius: 3px; font-size: 13px;">
                        </div>
                        
                        <div id="profList">
                            <?php
                            $sql = "SELECT id_prof_spec, code, title, socr FROM profec_spec WHERE kategoriya = '2' ORDER BY socr";
                            $result = $conn->query($sql);
                            if ($result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    $checked = in_array($row['id_prof_spec'], $professionFilter) ? 'checked' : '';
                                    $displayText = htmlspecialchars($row['socr'] . ' (' . $row['code'] . ')');
                                    echo "<label class='filter-label' style='display: flex; align-items: center; padding: 4px 0; cursor: pointer;'>
                                            <input type='checkbox' class='filter-checkbox prof-checkbox' name='profession_filter[]' value='" . $row['id_prof_spec'] . "' $checked style='margin-right: 8px;'>
                                            <span class='filter-text' style='font-size: 13px;'>" . $displayText . "</span>
                                          </label>";
                                }
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <!-- Колонка 3: Фильтры по дате -->
                <div style="flex: 0 0 250px;">
                    <div style="padding: 10px; border: 1px solid #ddd; border-radius: 5px; height: 100%;">
                        <h4 style="margin-bottom: 15px;">Фильтр по дате подачи</h4>
                        
                        <!-- Период дат -->
                        <div style="margin-bottom: 20px;">
                            <div style="margin-bottom: 10px;">
                                <label style="display: block; margin-bottom: 5px; font-size: 13px; font-weight: bold;">С:</label>
                                <input type="date" name="date_from" value="<?= isset($_GET['date_from']) ? htmlspecialchars($_GET['date_from']) : '' ?>" 
                                       style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px;">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 5px; font-size: 13px; font-weight: bold;">По:</label>
                                <input type="date" name="date_to" value="<?= isset($_GET['date_to']) ? htmlspecialchars($_GET['date_to']) : '' ?>" 
                                       style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px;">
                            </div>

                            <!-- Добавьте в колонку с фильтрами по дате: -->
                            <div style="margin-top: 10px;">
                                <button type="button" id="resetDates" 
                                        style="padding: 6px 12px; background: #5081c6; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                    Сбросить даты
                                </button>
                            </div>
                        </div>
                        
                        <!-- Кнопки управления -->
                        <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 30px;">
                            <button type="submit" class="apply-filter" 
                                    style="padding: 10px; background: #5081c6; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: bold;">
                                Применить фильтр
                            </button>
                            <button type="button" id="resetFilter" class="btn btn-secondary" 
                                    style="padding: 10px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px;">
                                Сбросить все
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const specSearch = document.querySelector('.spec-search');
    const profSearch = document.querySelector('.prof-search');
    const selectAllSpec = document.querySelector('.select-all-spec');
    const selectAllProf = document.querySelector('.select-all-prof');
    const resetFilterBtn = document.getElementById('resetFilter');
    
    // Поиск по специальностям
    if (specSearch) {
        specSearch.addEventListener("input", function () {
            const query = this.value.toLowerCase();
            const specLabels = document.querySelectorAll('#specList .filter-label');
            
            specLabels.forEach(label => {
                const text = label.querySelector(".filter-text").textContent.toLowerCase();
                if (text.includes(query)) {
                    label.style.display = "flex";
                } else {
                    label.style.display = "none";
                }
            });
        });
    }
    
    // Поиск по профессиям
    if (profSearch) {
        profSearch.addEventListener("input", function () {
            const query = this.value.toLowerCase();
            const profLabels = document.querySelectorAll('#profList .filter-label');
            
            profLabels.forEach(label => {
                const text = label.querySelector(".filter-text").textContent.toLowerCase();
                if (text.includes(query)) {
                    label.style.display = "flex";
                } else {
                    label.style.display = "none";
                }
            });
        });
    }
    
    // Выбрать все специальности
    if (selectAllSpec) {
        selectAllSpec.addEventListener('change', function() {
            const specCheckboxes = document.querySelectorAll('#specList .spec-checkbox');
            specCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
        
        // Обновление состояния "Выбрать все" при изменении отдельных чекбоксов
        document.querySelectorAll('#specList .spec-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allSpecs = document.querySelectorAll('#specList .spec-checkbox');
                const checkedSpecs = document.querySelectorAll('#specList .spec-checkbox:checked');
                selectAllSpec.checked = allSpecs.length === checkedSpecs.length;
                selectAllSpec.indeterminate = checkedSpecs.length > 0 && checkedSpecs.length < allSpecs.length;
            });
        });
    }
    
    // Выбрать все профессии
    if (selectAllProf) {
        selectAllProf.addEventListener('change', function() {
            const profCheckboxes = document.querySelectorAll('#profList .prof-checkbox');
            profCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
        
        // Обновление состояния "Выбрать все" при изменении отдельных чекбоксов
        document.querySelectorAll('#profList .prof-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allProfs = document.querySelectorAll('#profList .prof-checkbox');
                const checkedProfs = document.querySelectorAll('#profList .prof-checkbox:checked');
                selectAllProf.checked = allProfs.length === checkedProfs.length;
                selectAllProf.indeterminate = checkedProfs.length > 0 && checkedProfs.length < allProfs.length;
            });
        });
    }
    
    // Инициализация состояний "Выбрать все" при загрузке
    if (selectAllSpec) {
        const checkedSpecs = document.querySelectorAll('#specList .spec-checkbox:checked');
        const allSpecs = document.querySelectorAll('#specList .spec-checkbox');
        if (allSpecs.length > 0) {
            selectAllSpec.checked = allSpecs.length === checkedSpecs.length;
            selectAllSpec.indeterminate = checkedSpecs.length > 0 && checkedSpecs.length < allSpecs.length;
        }
    }
    
    if (selectAllProf) {
        const checkedProfs = document.querySelectorAll('#profList .prof-checkbox:checked');
        const allProfs = document.querySelectorAll('#profList .prof-checkbox');
        if (allProfs.length > 0) {
            selectAllProf.checked = allProfs.length === checkedProfs.length;
            selectAllProf.indeterminate = checkedProfs.length > 0 && checkedProfs.length < allProfs.length;
        }
    }
    
    // Сброс всех фильтров в модальном окне
    if (resetFilterBtn) {
        resetFilterBtn.addEventListener('click', function(e) {
            e.preventDefault(); // Предотвращаем стандартное действие
            
            // Очищаем все чекбоксы
            document.querySelectorAll('.filter-checkbox').forEach(cb => {
                cb.checked = false;
            });
            
            // Очищаем даты
            document.querySelectorAll('input[type="date"]').forEach(input => {
                input.value = '';
            });
            
            // Очищаем поле поиска (если оно есть в форме)
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.value = '';
            }
            
            // Сбрасываем "Выбрать все"
            if (selectAllSpec) selectAllSpec.checked = false;
            if (selectAllProf) selectAllProf.checked = false;
            
            // Очищаем поля поиска внутри списков
            const specSearch = document.querySelector('.spec-search');
            const profSearch = document.querySelector('.prof-search');
            if (specSearch) specSearch.value = '';
            if (profSearch) profSearch.value = '';
            
            // Показываем все элементы (если они были скрыты поиском)
            document.querySelectorAll('.filter-label').forEach(label => {
                label.style.display = 'flex';
            });
            
            // Отправляем форму
            document.getElementById('filterForm').submit();
        });
    }
    
    // Обработка дат - устанавливаем максимальную дату "по" равной сегодняшней
    const dateFrom = document.querySelector('input[name="date_from"]');
    const dateTo = document.querySelector('input[name="date_to"]');
    
    if (dateFrom && dateTo) {
        // Устанавливаем максимальную дату для "по"
        const today = new Date().toISOString().split('T')[0];
        dateTo.max = today;
        
        // Если выбрана дата "с", ограничиваем дату "по"
        dateFrom.addEventListener('change', function() {
            dateTo.min = this.value;
            if (this.value && dateTo.value && dateTo.value < this.value) {
                dateTo.value = this.value;
            }
        });
        
        // Если выбрана дата "по", ограничиваем дату "с"
        dateTo.addEventListener('change', function() {
            if (dateFrom.value && this.value < dateFrom.value) {
                dateFrom.value = this.value;
            }
        });
    }
    document.getElementById('resetDates').addEventListener('click', function() {
    document.querySelectorAll('input[type="date"]').forEach(input => {
        input.value = '';
    });
    // Не отправляем форму, чтобы можно было изменить другие фильтры
});
});

// Управление открытием/закрытием модального окна
const openFilter = document.getElementById('openFilter');
const closeFilter = document.getElementById('closeFilter');
const filterPopup = document.getElementById('filterPopup');

if (openFilter) {
    openFilter.addEventListener('click', function () {
        filterPopup.style.display = 'flex';
        document.body.style.overflow = 'hidden'; // Блокируем скролл страницы
    });
}

if (closeFilter) {
    closeFilter.addEventListener('click', function () {
        filterPopup.style.display = 'none';
        document.body.style.overflow = ''; // Восстанавливаем скролл
    });
}

// Закрытие при клике вне окна
if (filterPopup) {
    window.addEventListener('click', function (event) {
        if (event.target === filterPopup) {
            filterPopup.style.display = 'none';
            document.body.style.overflow = ''; // Восстанавливаем скролл
        }
    });
    
    // Закрытие по клавише ESC
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && filterPopup.style.display === 'flex') {
            filterPopup.style.display = 'none';
            document.body.style.overflow = ''; // Восстанавливаем скролл
        }
    });
}
</script>
    <?php if ($totalAbit <= 0) : ?>
    <div class = "no-zayav">
        <p>Нет заявлений для отображения</p>
    </div>
    <?php else : ?>

    <div class="issued-legend">
        Заявления, по которым стоит отметка "Документы выданы", подсвечены цветом и исключаются из рейтингов. Новые заявления после выдачи документов отображаются без подсветки.
    </div>

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
            <th>Оригинал</th>
            <th>Действия</th>
        </tr>
    </thead>
    <tbody>
    <?php
    foreach ($abitData as $idAbit => $data) {
        $rowCount = isset($data['zayavleniya']) ? count($data['zayavleniya']) : 0;
        if ($rowCount === 0) {
            continue;
        }
        
        // Сортируем заявления по ID заявления по возрастанию
        if (isset($data['zayavleniya'])) {
            usort($data['zayavleniya'], function($a, $b) {
                return $a['id_zayav'] - $b['id_zayav'];
            });
        }
        
        // Выводим обёртку для абитуриента
        echo "<tr class='abit-border-top'><td colspan='11'></td></tr>";
        
        // Первая строка (абитуриент + первое заявление)
        $firstZayav = array_shift($data['zayavleniya']);
        
        $isIssued = isset($firstZayav['issue_note']) && $firstZayav['issue_note'] && strpos($firstZayav['issue_note'], 'Документы выданы') === 0;
        $issueNote = $isIssued ? htmlspecialchars($firstZayav['issue_note']) : '';
        $issueLabel = $issueNote ? "<div class='issued-label'>{$issueNote}</div>" : '';
        $issuedCellClass = $issueNote ? " class='issued-cell'" : '';
        $applyToSpec = $firstZayav['spec_title'] !== '-';
        
        $specContent = $applyToSpec ? $issueLabel . $firstZayav['spec_title'] : $firstZayav['spec_title'];
        $profContent = !$applyToSpec ? $issueLabel . $firstZayav['profession'] : $firstZayav['profession'];
        
        echo "<tr class='abit-row' data-fio='" . htmlspecialchars(
            mb_strtolower($data['familiya'] . ' ' . $data['imya'], 'UTF-8'),
            ENT_QUOTES
        ) . "'>";

        echo "<td rowspan='" . ($rowCount) . "' class='td-id'>
            <div class='td-id-content'>
                <div class='abit-number'>" . htmlspecialchars($idAbit) . "</div>";

        // Собираем группы для всех заявлений этого абитуриента
        $allZayavs = array_merge([$firstZayav], $data['zayavleniya']);
        $groups = [];
        foreach ($allZayavs as $z) {
            if (!empty($z['group_name'])) {
                $key = $z['group_name'] . '|' . $z['socr'];
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'group_name' => $z['group_name'],
                        'socr' => $z['socr']
                    ];
                }
            }
        }

        // Выводим группы в первом столбце
        if (!empty($groups)) {
            echo "<div class='groups-container'>";
            foreach ($groups as $group) {
                echo "<div class='group-badge'>
                        <span class='group-name'>" . htmlspecialchars($group['group_name']) . "</span>
                    </div>";
            }
            echo "</div>";
            
            // Кнопка Удалить/перераспределить - показываем только если есть группы
            // Берем первую группу для данных
            $firstGroup = reset($groups);
            echo '<button class="btn btn-warning redistribute-btn" 
                data-abit-id="' . $idAbit . '"
                data-group-name="' . htmlspecialchars($firstGroup['group_name']) . '"
                data-fio="' . htmlspecialchars($data['familiya'] . ' ' . $data['imya'] . ' ' . $data['otchestvo']) . '"
                title="Удалить или перераспределить из группы">
                Удалить/перераспределить
            </button>';
        }

        echo "<textarea 
            class='notes-textarea' 
            data-abit-id='" . $idAbit . "' 
            placeholder='Примечания...'>" . htmlspecialchars($data['last_comment'] ?? '') . "</textarea>";

        echo "<button class='btn btn-secondary hide-from-rating' style='margin-top:6px; width:100%;' data-id='" . $firstZayav['id_zayav'] . "' data-name='" . htmlspecialchars($data['familiya'] . ' ' . $data['imya'], ENT_QUOTES) . "'>Убрать из рейтингов</button>";
        echo "</div>
        </td>";
        
        echo "<td rowspan='" . ($rowCount) . "'>" . htmlspecialchars($data['familiya']) . "</td>";
        echo "<td rowspan='" . ($rowCount) . "'>" . htmlspecialchars($data['imya']) . "</td>";
        echo "<td rowspan='" . ($rowCount) . "'>" . htmlspecialchars($data['otchestvo']) . "</td>";
        echo "<td rowspan='" . ($rowCount) . "'>" . date("d.m.Y", strtotime($data['date_bd'])) . "</td>";
        echo "<td rowspan='" . ($rowCount) . "'>" . htmlspecialchars($data['snils']) . "</td>";
        
        echo "<td{$issuedCellClass}>{$specContent}</td>";
        echo "<td{$issuedCellClass}>{$profContent}</td>";
        echo "<td{$issuedCellClass}>" . htmlspecialchars($firstZayav['socr']) . "</td>";
        echo "<td{$issuedCellClass}>" . date("d.m.Y H:i:s", strtotime($firstZayav['date'])) . "</td>";
        
        $checked = $firstZayav['original'] == 1 ? 'checked' : '';
        echo "<td{$issuedCellClass}><input type='checkbox' class='checkbox-original' data-id='" . $firstZayav['id_zayav'] . "' data-abit-id='" . $idAbit . "' $checked disabled></td>";
        
        echo "<td{$issuedCellClass}>";
        echo '<button class="btn btn-info view-details" data-id="' . $firstZayav['id_zayav'] . '">Редактировать</button>';
        echo '<button class="btn btn-warning change-spec" data-id="' . $firstZayav['id_zayav'] . '">Изменить специальность</button>';
        echo "<a href='otchet/print_application.php?id=" . $firstZayav['id_zayav'] . "' class='button-print'>Печать</a>";
        echo '<button class="btn btn-danger delete-zayav" style="margin-top:6px;" data-id="' . $firstZayav['id_zayav'] . '" data-name="' . htmlspecialchars($data['familiya'] . ' ' . $data['imya'], ENT_QUOTES) . '" data-socr="' . htmlspecialchars($firstZayav['socr'], ENT_QUOTES) . '">Удалить</button>';
        echo "</td>";
        echo "</tr>";
        
        // Остальные заявления
        foreach ($data['zayavleniya'] as $zayav) {
            $isIssuedRow = isset($zayav['issue_note']) && $zayav['issue_note'] && strpos($zayav['issue_note'], 'Документы выданы') === 0;
            $issueNoteRow = $isIssuedRow ? htmlspecialchars($zayav['issue_note']) : '';
            $issueLabelRow = $issueNoteRow ? "<div class='issued-label'>{$issueNoteRow}</div>" : '';
            $issuedCellClassRow = $issueNoteRow ? " class='issued-cell'" : '';
            $applyToSpecRow = $zayav['spec_title'] !== '-';
            
            $specContentRow = $applyToSpecRow ? $issueLabelRow . $zayav['spec_title'] : $zayav['spec_title'];
            $profContentRow = !$applyToSpecRow ? $issueLabelRow . $zayav['profession'] : $zayav['profession'];
            
            echo "<tr>";
            echo "<td{$issuedCellClassRow}>{$specContentRow}</td>";
            echo "<td{$issuedCellClassRow}>{$profContentRow}</td>";
            echo "<td{$issuedCellClassRow}>" . htmlspecialchars($zayav['socr']) . "</td>";
            echo "<td{$issuedCellClassRow}>" . date("d.m.Y H:i:s", strtotime($zayav['date'])) . "</td>";
            
            $checked = $zayav['original'] == 1 ? 'checked' : '';
            echo "<td{$issuedCellClassRow}><input type='checkbox' class='checkbox-original' data-id='" . $zayav['id_zayav'] . "' data-abit-id='" . $idAbit . "' $checked disabled></td>";
            
            echo "<td{$issuedCellClassRow}>";
            echo '<button class="btn btn-info view-details" data-id="' . $zayav['id_zayav'] . '">Редактировать</button>';
            echo '<button class="btn btn-warning change-spec" data-id="' . $zayav['id_zayav'] . '">Изменить специальность</button>';
            echo "<a href='otchet/print_application.php?id=" . $zayav['id_zayav'] . "' class='button-print'>Печать</a>";
            echo '<button class="btn btn-danger delete-zayav" style="margin-top:6px;" data-id="' . $zayav['id_zayav'] . '" data-name="' . htmlspecialchars($data['familiya'] . ' ' . $data['imya'], ENT_QUOTES) . '" data-socr="' . htmlspecialchars($zayav['socr'], ENT_QUOTES) . '">Удалить</button>';
            echo "</td>";
            echo "</tr>";
        }
        
        // Закрывающая граница
        echo "<tr class='abit-border-bottom'><td colspan='11'></td></tr>";
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

// Удаление заявления с подтверждением
$(document).on('click', '.delete-zayav', function () {
    const id = $(this).data('id');
    const fio = $(this).data('name') || 'это заявление';
    const socr = $(this).data('socr') || '';

    const suffix = socr ? `, специальность: ${socr}` : '';

    if (!confirm(`Удалить заявление (${fio}${suffix})?`)) {
        return;
    }

    fetch('save/delete_zayav.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: `id_zayav=${encodeURIComponent(id)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('Заявление удалено', 'success');
            setTimeout(() => location.reload(), 500);
        } else {
            showMessage(data.message || 'Ошибка при удалении', 'error');
        }
    })
    .catch(() => showMessage('Ошибка соединения с сервером', 'error'));
});

// Убрать из рейтингов (отметка "Документы выданы [дата]")
$(document).on('click', '.hide-from-rating', function () {
    const id = $(this).data('id');
    const fio = $(this).data('name') || 'абитуриент';
    const today = new Date().toLocaleDateString('ru-RU');

    if (!confirm(`Убрать из рейтингов (${fio})?\nБудет добавлена отметка "Документы выданы ${today}".`)) {
        return;
    }

    fetch('save/mark_issued.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: `id_zayav=${encodeURIComponent(id)}&issue_date=${encodeURIComponent(today)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message || 'Отметка добавлена', 'success');
            setTimeout(() => location.reload(), 500);
        } else {
            showMessage(data.message || 'Ошибка при добавлении отметки', 'error');
        }
    })
    .catch(() => showMessage('Ошибка соединения с сервером', 'error'));
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
// Добавьте это в самое начало вашего script блока, перед document.addEventListener
if (typeof showMessage === 'undefined') {
  function showMessage(text, type) {
    console.log(type.toUpperCase() + ':', text);
    
    // Создаем стилизованное сообщение
    const message = document.createElement('div');
    message.textContent = text;
    message.style.cssText = `
      position: fixed;
      top: 20px;
      right: 20px;
      padding: 15px 20px;
      border-radius: 5px;
      color: white;
      font-weight: bold;
      z-index: 10000;
      box-shadow: 0 4px 6px rgba(0,0,0,0.1);
      transition: opacity 0.3s;
    `;
    
    if (type === 'success') {
      message.style.backgroundColor = '#28a745';
    } else if (type === 'error') {
      message.style.backgroundColor = '#dc3545';
    } else {
      message.style.backgroundColor = '#007bff';
    }
    
    document.body.appendChild(message);
    
    // Удаляем сообщение через 3 секунды
    setTimeout(() => {
      message.style.opacity = '0';
      setTimeout(() => {
        if (message.parentNode) {
          message.parentNode.removeChild(message);
        }
      }, 300);
    }, 3000);
  }
}

// Оборачиваем весь код в DOMContentLoaded для безопасности
document.addEventListener('DOMContentLoaded', function() {
    
    // Обработчик для textarea с комментариями
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

    // Обработка импорта заявлений
    const importBtn = document.getElementById('importBtn');
    const importForm = document.getElementById('importForm');
    
    if (importBtn) {
        importBtn.addEventListener('click', function() {
            console.log('Кнопка импорта нажата');
            const importModal = document.getElementById('importModal');
            if (importModal) {
                importModal.style.display = 'flex';
                console.log('Модальное окно открыто');
            } else {
                console.error('Модальное окно не найдено');
            }
        });
    } else {
        console.error('Кнопка импорта не найдена!');
    }
    
    if (importForm) {
        importForm.addEventListener('submit', function(e) {
            e.preventDefault();
            console.log('Форма импорта отправлена');
            
            const fileInput = document.getElementById('importFile');
            const file = fileInput ? fileInput.files[0] : null;
            
            console.log('Файл выбран:', !!file, file ? file.name : 'нет');
            
            if (!file) {
                showMessage('Выберите файл для импорта', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('importFile', file);
            const srBallInput = document.getElementById('srBallManual');
            if (srBallInput && srBallInput.value.trim() !== '') {
                formData.append('sr_ball_attest', srBallInput.value.trim());
            }
            
            const importProgress = document.getElementById('importProgress');
            const importResult = document.getElementById('importResult');
            
            if (importProgress) importProgress.style.display = 'block';
            if (importResult) importResult.innerHTML = '';
            
            console.log('Отправка запроса на сервер...');
            
            fetch('save/import_applications.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log('Получен ответ от сервера, статус:', response.status);
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                return response.text();
            })
            .then(text => {
                console.log('Ответ сервера получен, длина:', text.length);
                console.log('Первые 500 символов ответа:', text.substring(0, 500));
                try {
                    const data = JSON.parse(text);
                    console.log('JSON распарсен успешно:', data);
                    if (importProgress) importProgress.style.display = 'none';
                    
                    if (data.success) {
                        if (importResult) {
                            importResult.innerHTML = 
                                '<div style="color: green; padding: 10px; background: #d4edda; border-radius: 5px;">' +
                                '<strong>Успешно!</strong><br>' +
                                'Импортировано абитуриентов: ' + (data.imported_abit || 0) + '<br>' +
                                'Импортировано заявлений: ' + (data.imported_zayav || 0) + '<br>' +
                                (data.errors && data.errors.length > 0 ? 
                                    '<br><strong>Ошибки:</strong><br>' + data.errors.join('<br>') : '') +
                                '</div>';
                        }
                        showMessage('Импорт завершен успешно', 'success');
                        setTimeout(() => {
                            location.reload();
                        }, 2000);
                    } else {
                        if (importResult) {
                            importResult.innerHTML = 
                                '<div style="color: red; padding: 10px; background: #f8d7da; border-radius: 5px;">' +
                                '<strong>Ошибка:</strong> ' + (data.error || 'Неизвестная ошибка') +
                                '</div>';
                        }
                        showMessage('Ошибка при импорте: ' + (data.error || 'Неизвестная ошибка'), 'error');
                    }
                } catch (e) {
                    console.error('Ошибка парсинга JSON:', e);
                    console.error('Ответ был:', text);
                    if (importProgress) importProgress.style.display = 'none';
                    if (importResult) {
                        importResult.innerHTML = 
                            '<div style="color: red; padding: 10px; background: #f8d7da; border-radius: 5px;">' +
                            '<strong>Ошибка:</strong> Не удалось обработать ответ сервера. Проверьте консоль браузера.<br>' +
                            '<small>Ответ: ' + text.substring(0, 200) + '</small>' +
                            '</div>';
                    }
                    showMessage('Ошибка при обработке ответа сервера', 'error');
                }
            })
            .catch(error => {
                console.error('Ошибка сети:', error);
                if (importProgress) importProgress.style.display = 'none';
                if (importResult) {
                    importResult.innerHTML = 
                        '<div style="color: red; padding: 10px; background: #f8d7da; border-radius: 5px;">' +
                        '<strong>Ошибка сети:</strong> ' + error.message +
                        '</div>';
                }
                showMessage('Ошибка сети: ' + error.message, 'error');
            });
        });
    } else {
        console.error('Форма импорта не найдена!');
    }
    
    // Обработчик для активации флажков (с проверкой)
    const activateCheckboxesBtn = document.getElementById('activateCheckboxes');
    if (activateCheckboxesBtn) {
        activateCheckboxesBtn.addEventListener('click', function () {
            // Находим все флажки
            const checkboxes = document.querySelectorAll('.checkbox-original');
            checkboxes.forEach(checkbox => {
                checkbox.disabled = false; // Разблокировать флажки
            });

            // Показать кнопку сохранения
            const saveChangesBtn = document.getElementById('saveChanges');
            if (saveChangesBtn) {
                saveChangesBtn.style.display = 'inline-block';
            }

            // Навешиваем обработчики на флажки
            updateOriginalCheckboxes();
        });
    }

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

    // Обработчик для кнопки сохранения (с проверкой)
    const saveChangesBtn = document.getElementById('saveChanges');
    if (saveChangesBtn) {
        saveChangesBtn.addEventListener('click', function () {
            const checkboxes = document.querySelectorAll('.checkbox-original');
            const changes = [];
            checkboxes.forEach(checkbox => {
                const zayavId = checkbox.getAttribute('data-id');
                const originalValue = checkbox.checked ? 1 : 0;
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
                    const saveChangesBtn = document.getElementById('saveChanges');
                    if (saveChangesBtn) {
                        saveChangesBtn.style.display = 'none';
                    }
                } else {
                    showMessage('Ошибка при сохранении изменений.', 'error');
                }
            })
            .catch(error => {
                console.error('Ошибка:', error);
                showMessage('Произошла ошибка при отправке запроса.', 'error');
            });
        });
    }
});

// Обработчики для кнопок перераспределения (используем jQuery)
$(document).ready(function() {
    // Текущие данные для работы с модальными окнами
    let currentActionData = {};

    // Открытие модального окна выбора действия
    $(document).on('click', '.redistribute-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        console.log('Кнопка нажата!', $(this).data());
        
        currentActionData = {
            abitId: $(this).data('abit-id'),
            groupName: $(this).data('group-name'),
            fio: $(this).data('fio')
        };
        
        console.log('Данные для модального окна:', currentActionData);
        
        if (!currentActionData.abitId) {
            console.error('Ошибка: нет abitId');
            return;
        }
        
        $('#actionFio').text(currentActionData.fio || 'Не указано');
        $('#actionGroupName').text(currentActionData.groupName || 'Не указано');
        
        // Показываем модальное окно
        $('#groupActionModal').css({
            'display': 'flex',
            'opacity': 0
        }).animate({'opacity': 1}, 200);
        
        console.log('Модальное окно должно быть открыто');
    });

    // Закрытие модального окна выбора действия
    window.closeGroupActionModal = function() {
        $('#groupActionModal').hide();
        currentActionData = {};
    };

    // Кнопка "Удалить из группы"
    $('#removeFromGroupBtn').on('click', function() {
        $('#groupActionModal').hide();
        $('#confirmFio').text(currentActionData.fio);
        $('#confirmGroup').text(currentActionData.groupName);
        $('#confirmRemoveModal').css('display', 'flex');
    });

    // Закрытие окна подтверждения удаления
    window.closeConfirmRemoveModal = function() {
        $('#confirmRemoveModal').hide();
        $('#groupActionModal').css('display', 'flex');
    };

    document.getElementById('confirmRemoveBtn').addEventListener('click', function() {
        const abitId = currentActionData.abitId;
        const confirmBtn = this;
        const originalText = confirmBtn.textContent;
        
        confirmBtn.textContent = 'Удаление...';
        confirmBtn.disabled = true;
        
        console.log('Отправка запроса на удаление из группы, abitId:', abitId);
        
        fetch('save/remove_from_group.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `abit_id=${abitId}`
        })
        .then(response => {
            console.log('Статус ответа:', response.status, response.statusText);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text();
        })
        .then(text => {
            console.log('Полный ответ сервера:', text);
            try {
                const data = JSON.parse(text);
                console.log('Распарсенный JSON:', data);
                
                if (data.success) {
                    showMessage(data.message || 'Абитуриент успешно удален из группы', 'success');
                    document.getElementById('confirmRemoveModal').style.display = 'none';
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showMessage('Ошибка: ' + (data.message || 'Неизвестная ошибка'), 'error');
                    confirmBtn.textContent = originalText;
                    confirmBtn.disabled = false;
                }
            } catch (e) {
                console.error('Ошибка парсинга JSON:', e);
                console.error('Ответ был:', text.substring(0, 200));
                showMessage('Ошибка: неверный формат ответа сервера', 'error');
                confirmBtn.textContent = originalText;
                confirmBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Ошибка сети:', error);
            showMessage('Ошибка сети: ' + error.message, 'error');
            confirmBtn.textContent = originalText;
            confirmBtn.disabled = false;
        });
    });

    // Кнопка "Перераспределить в другую группу"
    $('#redistributeToGroupBtn').on('click', function() {
        $('#groupActionModal').hide();
        
        // Заполняем скрытые поля
        $('#redistributeAbitId').val(currentActionData.abitId);
        
        console.log('Загрузка специальностей для абитуриента:', currentActionData.abitId);
        
        fetch('load/get_applicant_specs.php?abit_id=' + currentActionData.abitId)
            .then(response => {
                console.log('Статус ответа:', response.status);
                // Сначала получаем текст для отладки
                return response.text();
            })
            .then(text => {
                console.log('Полный ответ сервера:', text);
                
                try {
                    const data = JSON.parse(text);
                    console.log('Распарсенный JSON:', data);
                    
                    if (data.error) {
                        showMessage('Ошибка: ' + data.error, 'error');
                        $('#groupActionModal').css('display', 'flex');
                        return;
                    }
                    
                    if (!data.specialties || data.specialties.length === 0) {
                        showMessage('У абитуриента нет заявлений или нет заявлений с оригиналами для перераспределения', 'error');
                        $('#groupActionModal').css('display', 'flex');
                        return;
                    }
                    
                    const specSelect = $('#redistributeSpec');
                    specSelect.empty();
                    specSelect.append('<option value="">-- Выберите специальность --</option>');
                    
                    data.specialties.forEach(spec => {
                        const option = new Option(
                            `${spec.socr} (${spec.code}) - ${spec.class_text}, ${spec.forma_text} (заявлений: ${spec.total_zayav}, оригиналов: ${spec.original_count})`,
                            spec.id_prof_spec
                        );
                        option.dataset.class = spec.class;
                        option.dataset.forma = spec.forma_obuch;
                        specSelect.append(option);
                    });
                    
                    // Показываем информацию об абитуриенте
                    $('#redistributeApplicantInfo').html(`
                        <strong>${currentActionData.fio}</strong><br>
                        Текущая группа: ${currentActionData.groupName}
                    `);
                    
                    // Выбираем первую специальность по умолчанию
                    if (data.specialties.length > 0) {
                        specSelect.val(data.specialties[0].id_prof_spec).trigger('change');
                    }
                    
                    $('#redistributeModal').css('display', 'flex');
                } catch (e) {
                    console.error('Ошибка парсинга JSON:', e);
                    console.error('Ответ был:', text.substring(0, 200));
                    showMessage('Ошибка: неверный формат ответа сервера. Проверьте консоль браузера.', 'error');
                    $('#groupActionModal').css('display', 'flex');
                }
            })
            .catch(error => {
                console.error('Ошибка сети:', error);
                showMessage('Ошибка сети: ' + error.message, 'error');
                $('#groupActionModal').css('display', 'flex');
            });
    });

    // Закрытие окна перераспределения
    window.closeRedistributeModal = function() {
        $('#redistributeModal').hide();
        $('#groupActionModal').css('display', 'flex');
    };

    // При изменении выбора специальности загружаем группы
    $('#redistributeSpec').on('change', function() {
        const specId = $(this).val();
        if (!specId) return;
        
        // Получаем class и forma из выбранной опции
        const selectedOption = $(this).find('option:selected');
        const classNum = selectedOption.data('class');
        const forma = selectedOption.data('forma');
        
        fetch('load/get_groups.php?spec_id=' + specId + '&class=' + classNum + '&forma=' + forma)
            .then(response => response.json())
            .then(data => {
                const groupSelect = $('#redistributeExistingGroup');
                groupSelect.empty();
                groupSelect.append('<option value="">-- Выберите существующую группу --</option>');
                
                if (data.groups && data.groups.length > 0) {
                    data.groups.forEach(group => {
                        groupSelect.append(new Option(group.name, group.id));
                    });
                }
            });
    });

    // Обработка формы перераспределения
    $('#redistributeForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        formData.append('current_group_id', currentActionData.groupId);
        
        fetch('save/redistribute_group.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showMessage('Абитуриент успешно перераспределен', 'success');
                closeRedistributeModal();
                setTimeout(() => location.reload(), 1000);
            } else {
                showMessage('Ошибка: ' + data.message, 'error');
            }
        })
        .catch(error => {
            showMessage('Ошибка сети: ' + error.message, 'error');
        });
    });
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

<!-- Модальное окно импорта -->
<div id="importModal" class="modal" style="display: none;">
  <div class="modal-content" style="max-width: 600px;">
    <span class="close" onclick="document.getElementById('importModal').style.display='none'">&times;</span>
    <h2>Импорт заявлений из Word документа</h2>
    <form id="importForm" enctype="multipart/form-data">
      <div style="margin-bottom: 20px;">
        <label for="importFile">Выберите Word файл (.docx):</label>
        <input type="file" id="importFile" name="importFile" accept=".docx" required>
      </div>
      <div style="margin-bottom: 20px;">
        <label for="srBallManual">Средний балл (X.XXXX):</label>
        <input type="number" step="0.0001" min="2.0" max="5.0" id="srBallManual" name="sr_ball_attest" placeholder="пример: 4.1234" inputmode="decimal" style="width: 200px;" title="От 2.0 до 5.0, до 4 знаков после точки">
        <small style="display: block; color: #666;">Поле не обязательно. Диапазон 2.0–5.0, до 4 знаков после точки.</small>
      </div>
      <div id="importProgress" style="display: none; margin-bottom: 20px;">
        <p>Обработка файла...</p>
      </div>
      <div id="importResult" style="margin-bottom: 20px;"></div>
      <button type="submit" class="btn btn-primary">Импортировать</button>
      <button type="button" class="btn btn-secondary" onclick="document.getElementById('importModal').style.display='none'">Отмена</button>
    </form>
  </div>
</div>

<!-- Модальное окно выбора действия (удалить или перераспределить) -->
<div id="groupActionModal" class="modal" style="display: none;">
  <div class="modal-content" style="max-width: 500px;">
    <span class="close" onclick="closeGroupActionModal()">&times;</span>
    <h2>Управление группой</h2>
    <p><strong>Абитуриент:</strong> <span id="actionFio"></span></p>
    <p><strong>Текущая группа:</strong> <span id="actionGroupName"></span></p>
    
    <div class="action-buttons" style="display: flex; flex-direction: column; gap: 10px; margin-top: 20px;">
      <button id="removeFromGroupBtn" class="btn btn-danger">Удалить из группы</button>
      <button id="redistributeToGroupBtn" class="btn btn-warning">Перераспределить в другую группу</button>
      <button type="button" class="btn btn-secondary" onclick="closeGroupActionModal()">Отмена</button>
    </div>
  </div>
</div>

<!-- Модальное окно перераспределения -->
<div id="redistributeModal" class="modal" style="display: none;">
  <div class="modal-content" style="max-width: 900px;">
    <span class="close" onclick="closeRedistributeModal()">&times;</span>
    <h2>Перераспределение в другую группу</h2>
    
    <form id="redistributeForm">
      <input type="hidden" id="redistributeAbitId" name="abit_id">
      
      <!-- Выбор специальности -->
      <div class="form-group">
        <label for="redistributeSpec">Специальность:</label>
        <select id="redistributeSpec" name="spec_id" class="form-control" required>
          <option value="">-- Выберите специальность --</option>
          <!-- Загрузится через AJAX -->
        </select>
      </div>
      
      <!-- Выбор группы -->
      <div class="form-group">
        <label>Группа:</label>
        <div style="display: flex; gap: 10px; align-items: center;">
          <select id="redistributeExistingGroup" name="existing_group" class="form-control" style="flex: 1;">
            <option value="">-- Выберите существующую группу --</option>
            <!-- Группы загружаются через AJAX -->
          </select>
          <span>или</span>
          <input type="text" id="redistributeNewGroup" name="new_group" class="form-control" style="flex: 1;" 
                 placeholder="Введите новую группу">
        </div>
      </div>
      
      <!-- Информация об абитуриенте -->
      <div class="form-group">
        <h4>Абитуриент для перераспределения</h4>
        <div id="redistributeApplicantInfo" style="padding: 10px; background: #f5f5f5; border-radius: 5px;">
          <!-- Информация загрузится через AJAX -->
        </div>
      </div>
      
      <button type="submit" class="btn btn-primary">Перераспределить</button>
      <button type="button" class="btn btn-secondary" onclick="closeRedistributeModal()">Отмена</button>
    </form>
  </div>
</div>

<!-- Модальное окно подтверждения удаления -->
<div id="confirmRemoveModal" class="modal" style="display: none;">
  <div class="modal-content" style="max-width: 500px;">
    <h3>Подтверждение удаления</h3>
    <p>Вы уверены, что хотите удалить абитуриента <span id="confirmFio"></span> из группы <span id="confirmGroup"></span>?</p>
    <div style="display: flex; gap: 10px; margin-top: 20px;">
      <button id="confirmRemoveBtn" class="btn btn-danger">Да, удалить</button>
      <button type="button" class="btn btn-secondary" onclick="closeConfirmRemoveModal()">Отмена</button>
    </div>
  </div>
</div>

<!-- Модальное окно распределения по группам -->
<div id="distributeModal" class="modal" style="display: none;">
  <div class="modal-content" style="max-width: 900px;">
    <span class="close" onclick="document.getElementById('distributeModal').style.display='none'">&times;</span>
    <h2>Распределение по группам</h2>
    <form id="distributeForm">
      <!-- Выбор специальности -->
      <div class="form-group">
        <label for="specForGroup">Специальность:</label>
        <select id="specForGroup" name="spec_id" class="form-control" required>
          <option value="">-- Выберите специальность --</option>
          <?php
          $sql = "SELECT ps.id_prof_spec, ps.code, ps.title, ps.socr, ps.class, ps.forma_obuch 
                  FROM profec_spec ps 
                  ORDER BY ps.code";
          $result = $conn->query($sql);
          while ($row = $result->fetch_assoc()) {
            $formaText = $row['forma_obuch'] == 1 ? 'очная' : 'заочная';
            $classText = $row['class'] == 1 ? '9 классов' : '11 классов';
            echo '<option value="' . $row['id_prof_spec'] . '" data-class="' . $row['class'] . '" data-forma="' . $row['forma_obuch'] . '">' . 
                 htmlspecialchars($row['socr']) . ' (' . $row['code'] . ') - ' . $classText . ', ' . $formaText . '</option>';
          }
          ?>
        </select>
      </div>
      
      <!-- Только поле для ввода названия группы -->
      <div class="form-group">
        <label for="groupNameInput">Название группы:</label>
        <input type="text" id="groupNameInput" name="new_group" class="form-control" 
               placeholder="Введите название группы (например, ИП1-11)" required>
      </div>
      
      <!-- Список абитуриентов -->
      <div class="form-group">
        <h3>Абитуриенты для распределения</h3>
        <div id="applicantsList" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">
          <p>Выберите специальность, чтобы увидеть список абитуриентов с оригиналами</p>
        </div>
        <div style="margin-top: 10px;">
          <label><input type="checkbox" id="selectAllApplicants"> Выбрать всех</label>
        </div>
      </div>
      
      <button type="submit" class="btn btn-primary">Распределить</button>
      <button type="button" class="btn btn-secondary" onclick="document.getElementById('distributeModal').style.display='none'">Отмена</button>
    </form>
  </div>
</div>

</body>
</html>

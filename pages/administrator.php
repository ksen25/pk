<?php
$host = 'localhost';
$user = 'root';
$password = 'root';
$dbname = 'pk_2025';
$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

// Гарантируем наличие поля issue_note для отметок "Документы выданы"
$colCheckIssue = $conn->query("SHOW COLUMNS FROM zayav LIKE 'issue_note'");
if ($colCheckIssue && $colCheckIssue->num_rows === 0) {
    $conn->query("ALTER TABLE zayav ADD COLUMN issue_note TEXT NULL");
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

// Сортировка по последней дате подачи

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
GROUP BY abit.id_abit, abit.familiya, abit.imya, abit.otchestvo, abit.snils, abit.date_bd
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
    WHERE abit.id_abit IN ($idsIn)
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
            <?php if (!empty($_GET['spec_title_filter']) || !empty($_GET['profession_filter'])): ?>
                <form method="GET">
                    <button type="submit" name="reset_filter" class = "unfilter" value="1">Сбросить фильтр</button>
                </form>
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
      
      <!-- Выбор группы -->
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

// Открытие модального окна распределения
document.getElementById('distributeGroups').addEventListener('click', function() {
  document.getElementById('distributeModal').style.display = 'flex';
});

// При выборе специальности загружаем группы и абитуриентов
document.getElementById('specForGroup').addEventListener('change', function() {
  const specId = this.value;
  const classNum = this.options[this.selectedIndex].getAttribute('data-class');
  const forma = this.options[this.selectedIndex].getAttribute('data-forma');
  
  if (!specId) return;
  
  // Проверяем, что атрибуты существуют
  const classValue = classNum !== null ? classNum : '';
  const formaValue = forma !== null ? forma : '';
  
  // Загружаем группы для выбранной специальности
  fetch('load/get_groups.php?spec_id=' + specId + '&class=' + classValue + '&forma=' + formaValue)
    .then(response => response.json())
    .then(data => {
      const groupSelect = document.getElementById('existingGroup');
      groupSelect.innerHTML = '<option value="">-- Выберите существующую группу --</option>';
      data.groups.forEach(group => {
        const option = document.createElement('option');
        option.value = group.id;
        option.textContent = group.name;
        groupSelect.appendChild(option);
      });
    });
  
  // Загружаем абитуриентов для выбранной специальности
  fetch('load/get_applicants_for_group.php?spec_id=' + specId + '&class=' + classNum + '&forma=' + forma)
    .then(response => response.json())
    .then(data => {
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
    });
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
  
  const specId = document.getElementById('specForGroup').value;
  const classNum = document.getElementById('specForGroup').options[document.getElementById('specForGroup').selectedIndex].getAttribute('data-class');
  const forma = document.getElementById('specForGroup').options[document.getElementById('specForGroup').selectedIndex].getAttribute('data-forma');
  const existingGroup = document.getElementById('existingGroup').value;
  const newGroup = document.getElementById('newGroup').value;
  
  if (!existingGroup && !newGroup) {
    alert('Выберите существующую группу или введите новую');
    return;
  }
  
  const applicants = [];
  document.querySelectorAll('input[name="applicants[]"]:checked').forEach(checkbox => {
    applicants.push(checkbox.value);
  });
  
  if (applicants.length === 0) {
    alert('Выберите хотя бы одного абитуриента');
    return;
  }
  
  const formData = new FormData();
  formData.append('spec_id', specId);
  formData.append('class', classNum);
  formData.append('forma', forma);
  formData.append('existing_group', existingGroup);
  formData.append('new_group', newGroup);
  formData.append('applicants', JSON.stringify(applicants));
  
  fetch('save/distribute_groups.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      showMessage('Распределение успешно сохранено', 'success');
      document.getElementById('distributeModal').style.display = 'none';
      // Обновляем страницу через 1 секунду
      setTimeout(() => location.reload(), 1000);
    } else {
      showMessage('Ошибка: ' + data.message, 'error');
    }
  })
  .catch(error => {
    showMessage('Ошибка сети: ' + error.message, 'error');
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
        
        echo "<tr>";
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
        }
        
        echo "<textarea 
            class='notes-textarea' 
            data-abit-id='" . $idAbit . "' 
            placeholder='Примечания...'>" . htmlspecialchars($data['last_comment'] ?? '') . "</textarea>
        <button class='btn btn-secondary hide-from-rating' style='margin-top:6px; width:100%;' data-id='" . $firstZayav['id_zayav'] . "' data-name='" . htmlspecialchars($data['familiya'] . ' ' . $data['imya'], ENT_QUOTES) . "'>Убрать из рейтингов</button>
        </div>
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

// Убрать из рейтингов (пометка "Документы выданы [дата]")
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
                    console.error('Ответ сервера:', text);
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
        console.error('Форма импорта не найдена! Проверьте, что элемент с id="importForm" существует.');
    }
    
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

</body>
</html>


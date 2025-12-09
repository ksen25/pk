<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Подключаем PhpWord - используем корневой vendor
$autoloadPath = __DIR__ . '/../../vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    die(json_encode(['success' => false, 'error' => 'Autoload не найден по пути: ' . $autoloadPath]));
}

require_once $autoloadPath;
require_once __DIR__ . '/../config/config.php';

// Проверяем, что класс доступен
if (!class_exists('PhpOffice\PhpWord\IOFactory')) {
    die(json_encode(['success' => false, 'error' => 'Класс PhpOffice\PhpWord\IOFactory не найден. Убедитесь, что библиотека phpoffice/phpword установлена через composer. Путь к autoload: ' . $autoloadPath]));
}

use PhpOffice\PhpWord\IOFactory;

header('Content-Type: application/json');

// Включим детальное логирование
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/import_debug.log');
error_log("=== START IMPORT PROCESS ===");

// Проверка загрузки файла
if (!isset($_FILES['importFile'])) {
    echo json_encode(['success' => false, 'error' => 'Файл не был загружен. Проверьте, что форма отправляется правильно.']);
    exit;
}

if ($_FILES['importFile']['error'] !== UPLOAD_ERR_OK) {
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'Файл превышает максимальный размер',
        UPLOAD_ERR_FORM_SIZE => 'Файл превышает максимальный размер формы',
        UPLOAD_ERR_PARTIAL => 'Файл был загружен частично',
        UPLOAD_ERR_NO_FILE => 'Файл не был загружен',
        UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка',
        UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск',
        UPLOAD_ERR_EXTENSION => 'Загрузка файла была остановлена расширением'
    ];
    $errorMsg = $errorMessages[$_FILES['importFile']['error']] ?? 'Неизвестная ошибка загрузки (код: ' . $_FILES['importFile']['error'] . ')';
    echo json_encode(['success' => false, 'error' => 'Ошибка загрузки файла: ' . $errorMsg]);
    exit;
}

$file = $_FILES['importFile'];
$fileName = $file['name'];
$fileTmpPath = $file['tmp_name'];

// Проверка расширения файла
$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if ($fileExtension !== 'docx') {
    echo json_encode(['success' => false, 'error' => 'Поддерживаются только файлы .docx']);
    exit;
}

try {
    // Загрузка Word документа
    error_log("Загрузка файла: $fileName");
    $phpWord = IOFactory::load($fileTmpPath);
    
    $importedAbit = 0;
    $importedZayav = 0;
    $errors = [];
    
    // Получаем весь текст из документа
    $fullText = '';
    
    foreach ($phpWord->getSections() as $section) {
        $fullText .= extractTextFromElement($section);
    }
    
    // Сохраняем извлеченный текст для отладки
    file_put_contents(__DIR__ . '/debug_extracted_text.txt', $fullText);
    error_log("Текст извлечен, длина: " . strlen($fullText));
    
    // Парсинг данных из текста
    $data = parseWordDocument($fullText, $conn);
    
    if (empty($data)) {
        error_log("Не удалось распарсить данные из документа");
        // Попробуем альтернативный метод извлечения текста
        $fullTextAlt = '';
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if ($element instanceof \PhpOffice\PhpWord\Element\Table) {
                    foreach ($element->getRows() as $row) {
                        foreach ($row->getCells() as $cell) {
                            $cellText = '';
                            foreach ($cell->getElements() as $cellElement) {
                                if ($cellElement instanceof \PhpOffice\PhpWord\Element\TextRun) {
                                    foreach ($cellElement->getElements() as $textElement) {
                                        if ($textElement instanceof \PhpOffice\PhpWord\Element\Text) {
                                            $cellText .= $textElement->getText() . ' ';
                                        }
                                    }
                                } elseif ($cellElement instanceof \PhpOffice\PhpWord\Element\Text) {
                                    $cellText .= $cellElement->getText() . ' ';
                                }
                            }
                            $fullTextAlt .= trim($cellText) . ' | ';
                        }
                        $fullTextAlt .= "\n";
                    }
                } else {
                    $fullTextAlt .= strip_tags($element->getText()) . "\n";
                }
            }
        }
        
        file_put_contents(__DIR__ . '/debug_extracted_text_alt.txt', $fullTextAlt);
        error_log("Альтернативный текст извлечен, длина: " . strlen($fullTextAlt));
        
        // Пробуем парсить альтернативный текст
        $data = parseWordDocument($fullTextAlt, $conn);
        
        if (empty($data)) {
            echo json_encode(['success' => false, 'error' => 'Не удалось извлечь данные из документа. Проверьте файлы debug_extracted_text.txt и debug_extracted_text_alt.txt.']);
            exit;
        }
    }
    
    // Пользовательский средний балл (если передан из формы)
    $manualSrBall = isset($_POST['sr_ball_attest']) ? trim($_POST['sr_ball_attest']) : null;
    if ($manualSrBall !== null && $manualSrBall !== '') {
        // нормализуем точку и приводим к формату X.XXXX (с доп. нулями)
        $manualSrBall = str_replace(',', '.', $manualSrBall);
        if (preg_match('/^(\\d(?:\\.\\d{0,4})?)$/', $manualSrBall)) {
            $manualSrBall = number_format((float)$manualSrBall, 4, '.', '');
        } else {
            $manualSrBall = null; // некорректный ввод, игнорируем
        }
    }
    if (!empty($data) && $manualSrBall !== null) {
        foreach ($data as &$app) {
            $app['sr_ball_attest'] = $manualSrBall;
        }
        unset($app);
    }
    
    error_log("Успешно распаршено заявлений: " . count($data));
    
    // Импорт данных в базу
    error_log("Начало импорта в базу данных");
    $conn->begin_transaction();
    
    try {
        // Получаем текущую дату для пометки "ГУ"
        $currentDate = date('d.m.Y');
        
        foreach ($data as $appIndex => $application) {
            error_log("Обработка заявления {$appIndex}: " . ($application['familiya'] ?? 'Неизвестно'));
            
            // Проверяем наличие абитуриента по СНИЛС
            $abitId = null;
            if (!empty($application['snils'])) {
                error_log("Поиск абитуриента по СНИЛС: " . $application['snils']);
                $sql = "SELECT id_abit FROM abit WHERE snils = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt === false) {
                    error_log("Ошибка подготовки запроса SELECT: " . $conn->error);
                    $errors[] = "Ошибка базы данных при поиске абитуриента";
                    continue;
                }
                
                $stmt->bind_param("s", $application['snils']);
                if (!$stmt->execute()) {
                    error_log("Ошибка выполнения SELECT: " . $stmt->error);
                    $stmt->close();
                    continue;
                }
                
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $abitId = $result->fetch_assoc()['id_abit'];
                    error_log("Найден существующий абитуриент ID: " . $abitId);
                    
                    // Обновляем поле pervooch_priem если нужно
                    if (isset($application['pervooch_priem'])) {
                        $updateSql = "UPDATE abit SET pervooch_priem = ? WHERE id_abit = ?";
                        $updateStmt = $conn->prepare($updateSql);
                        if ($updateStmt) {
                            $updateStmt->bind_param("si", $application['pervooch_priem'], $abitId);
                            $updateStmt->execute();
                            $updateStmt->close();
                        }
                    }
                }
                $stmt->close();
            }
            
            // Если абитуриент не найден, создаем нового
            if (!$abitId) {
                error_log("Создание нового абитуриента");
                $result = createAbiturient($application, $conn);
                if (is_array($result) && isset($result['error'])) {
                    $errorMsg = "Не удалось создать абитуриента " . ($application['familiya'] ?? 'Неизвестно') . ": " . $result['error'];
                    $errors[] = $errorMsg;
                    error_log($errorMsg);
                    continue;
                } elseif ($result) {
                    $abitId = $result;
                    $importedAbit++;
                    error_log("Создан новый абитуриент ID: " . $abitId);
                } else {
                    $errorMsg = "Не удалось создать абитуриента: " . ($application['familiya'] ?? 'Неизвестно');
                    $errors[] = $errorMsg;
                    error_log($errorMsg);
                    continue;
                }
            }
            
            // Импортируем заявления для каждой специальности
            if (!empty($application['specialties'])) {
                error_log("Найдено специальностей: " . count($application['specialties']));
                foreach ($application['specialties'] as $specIndex => $specData) {
                    $specCode = $specData['code'] ?? '';
                    $classNum = $specData['class'] ?? null;
                    $formaTitle = $specData['forma_title'] ?? null;
                    $formaOplaty = $specData['forma_oplaty'] ?? null;
                    
                    error_log("Обработка специальности {$specIndex}: код='{$specCode}', класс={$classNum}, форма='{$formaTitle}', оплата='{$formaOplaty}'");
                    
                    // Находим ID специальности/профессии с учетом class и forma_obuch
                    $specId = findSpecialtyByCode($specCode, $classNum, $formaTitle, $conn);
                    
                    if ($specId) {
                        error_log("Найдена специальность ID: " . $specId);
                        
                        // Проверяем, нет ли уже заявления на эту же специальность с той же формой обучения
                        $duplicateCheck = checkDuplicateApplication($abitId, $specId, $formaTitle, $conn);
                        if ($duplicateCheck) {
                            $errorMsg = "Заявление на специальность '$specCode' с формой обучения '$formaTitle' уже существует";
                            $errors[] = $errorMsg;
                            error_log($errorMsg);
                            continue; // Пропускаем эту специальность
                        }
                        
                        // Комментарий: только форма оплаты, если "Коммерческая", и служебные метки
                        $commentParts = [];
                        if (!empty($formaOplaty) && mb_stripos($formaOplaty, 'коммер') !== false) {
                            $commentParts[] = $formaOplaty;
                        }
                        $commentParts[] = "ГУ {$currentDate}";
                        if (!empty($application['zayav_date'])) $commentParts[] = "Дата заявления: {$application['zayav_date']}";
                        if (!empty($application['channel']))    $commentParts[] = "Канал: {$application['channel']}";
                        $comment = implode("; ", $commentParts);
                        error_log("Комментарий для заявления: " . $comment);

                        // Проверяем количество заявлений (не ограничиваем по форме обучения, т.к. могут быть оч/заоч для одной специальности)
                        $countSql = "SELECT COUNT(*) as cnt FROM zayav WHERE id_abitur = ?";
                        $countStmt = $conn->prepare($countSql);
                        if ($countStmt === false) {
                            error_log("Ошибка подготовки запроса подсчета заявлений: " . $conn->error);
                            continue;
                        }
                        
                        $countStmt->bind_param("i", $abitId);
                        $countStmt->execute();
                        $countResult = $countStmt->get_result();
                        $currentCount = $countResult->fetch_assoc()['cnt'];
                        $countStmt->close();
                        if ($currentCount < 5) {
                            // Вставляем заявление без проверки дубликатов по форме обучения/классу
                            $insertSql = "INSERT INTO zayav (id_abitur, id_spec_prof, date, comment) VALUES (?, ?, NOW(), ?)";
                            $insertStmt = $conn->prepare($insertSql);
                            if ($insertStmt === false) {
                                error_log("Ошибка подготовки запроса вставки заявления: " . $conn->error);
                            } else {
                                $insertStmt->bind_param("iis", $abitId, $specId, $comment);
                                if ($insertStmt->execute()) {
                                    $importedZayav++;
                                    error_log("Заявление успешно создано");
                                } else {
                                    $errorMsg = "Ошибка создания заявления для специальности '$specCode': " . $insertStmt->error;
                                    $errors[] = $errorMsg;
                                    error_log($errorMsg);
                                }
                                $insertStmt->close();
                            }
                        } else {
                            $errorMsg = "Превышен лимит заявлений для абитуриента: " . ($application['familiya'] ?? 'Неизвестно');
                            $errors[] = $errorMsg;
                            error_log($errorMsg);
                        }
                    } else {
                        $errorMsg = "Специальность с кодом '$specCode' не найдена" . 
                                   ($classNum ? " (класс: $classNum)" : "") . 
                                   ($formaTitle ? " (форма обучения: $formaTitle)" : "");
                        $errors[] = $errorMsg;
                        error_log($errorMsg);
                    }
                }
            } else {
                $errorMsg = "Для абитуриента " . ($application['familiya'] ?? 'Неизвестно') . " не найдены специальности в документе.";
                $errors[] = $errorMsg;
                error_log($errorMsg);
            }
        }
        
        $conn->commit();
        error_log("Импорт завершен успешно");
        
        echo json_encode([
            'success' => true,
            'imported_abit' => $importedAbit,
            'imported_zayav' => $importedZayav,
            'errors' => $errors
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Ошибка при импорте: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Ошибка при импорте: ' . $e->getMessage()]);
    }
    
} catch (Exception $e) {
    error_log("Ошибка чтения файла: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Ошибка чтения файла: ' . $e->getMessage()]);
}

// Вспомогательная функция для извлечения текста из элемента
function extractTextFromElement($element) {
    $text = '';
    
    if ($element instanceof \PhpOffice\PhpWord\Element\Text) {
        $text .= $element->getText();
    } elseif ($element instanceof \PhpOffice\PhpWord\Element\TextRun) {
        foreach ($element->getElements() as $textElement) {
            if ($textElement instanceof \PhpOffice\PhpWord\Element\Text) {
                $text .= $textElement->getText();
            }
        }
    } elseif ($element instanceof \PhpOffice\PhpWord\Element\Table) {
        foreach ($element->getRows() as $row) {
            foreach ($row->getCells() as $cell) {
                foreach ($cell->getElements() as $cellElement) {
                    $text .= extractTextFromElement($cellElement) . ' ';
                }
                $text .= ' | ';
            }
            $text .= "\n";
        }
    } elseif ($element instanceof \PhpOffice\PhpWord\Element\Section) {
        foreach ($element->getElements() as $sectionElement) {
            $text .= extractTextFromElement($sectionElement);
        }
    } elseif (method_exists($element, 'getElements')) {
        foreach ($element->getElements() as $subElement) {
            $text .= extractTextFromElement($subElement);
        }
    }
    
    return $text;
}

// Функция парсинга Word документа (полный набор полей)
function parseWordDocument($text, $conn) {
    $currentApp = [];
    error_log("Начало парсинга документа");
    
    $currentApp['_raw_text'] = $text;
    
    // Нормализуем переводы строк, но НЕ убираем их, чтобы не склеивать блоки
    $textNl = preg_replace("/\r\n|\r|\n/u", "\n", $text);
    $lines = array_map('trim', explode("\n", $textNl));
    $joined = implode("\n", $lines); // пригодится для быстрых поиска по всему тексту
    
    // 1. ФИО – первая строка с тремя словами в верхнем регистре
    if (preg_match('/^\\s*([А-ЯЁ]+)\\s+([А-ЯЁ]+)\\s+([А-ЯЁ]+)/u', trim($lines[0] ?? ''), $fio)) {
        $currentApp['familiya'] = mb_convert_case($fio[1], MB_CASE_TITLE, 'UTF-8');
        $currentApp['imya'] = mb_convert_case($fio[2], MB_CASE_TITLE, 'UTF-8');
        $currentApp['otchestvo'] = mb_convert_case($fio[3], MB_CASE_TITLE, 'UTF-8');
        // Логируем латинские ключи, чтобы не было undefined index
        error_log("Найдено ФИО: {$currentApp['familiya']} {$currentApp['imya']} {$currentApp['otchestvo']}");
    } else {
        if (preg_match('/фамилия[:\\s]+([А-ЯЁа-яё\\-]+)/ui', $joined, $m)) $currentApp['familiya'] = trim($m[1]);
        if (preg_match('/имя[:\\s]+([А-ЯЁа-яё\\-]+)/ui', $joined, $m)) $currentApp['imya'] = trim($m[1]);
        if (preg_match('/отчество[:\\s]+([А-ЯЁа-яё\\-]+)/ui', $joined, $m)) $currentApp['otchestvo'] = trim($m[1]);
    }
    
    // 2. СНИЛС
    if (preg_match('/снилс[:\\s]*([\\d\\s-]+)/ui', $joined, $m) || preg_match('/(\\d{3}[\\s-]?\\d{3}[\\s-]?\\d{3}[\\s-]?\\d{2})/u', $joined, $m)) {
        $sn = preg_replace('/\\D/', '', $m[1]);
        if (strlen($sn) === 11) {
            $currentApp['snils'] = substr($sn,0,3).'-'.substr($sn,3,3).'-'.substr($sn,6,3).' '.substr($sn,9,2);
            error_log("Найден СНИЛС: {$currentApp['snils']}");
        }
    }
    
    // 3. Дата рождения
    if (preg_match('/дата\\s+рождения[:\\s]*([\\d\\.\\/-]+)/ui', $joined, $m)) {
        $parsed = parseDate($m[1]);
        if ($parsed) {
            $currentApp['date_bd'] = $parsed;
            error_log("Найдена дата рождения: {$currentApp['date_bd']}");
        }
    }
    
    // 4. Место рождения – берем блок между "Место рождения:" и "СНИЛС:"
    $placeBlock = extractBetween($joined, 'Место рождения:', 'СНИЛС:');
    if ($placeBlock) {
        $place = parsePlace($placeBlock);
        if (!empty($place['gorod']) || !empty($place['oblast'])) {
            $currentApp['mesto_rozhd'] = $place;
            error_log("Место рождения: " . json_encode($place, JSON_UNESCAPED_UNICODE));
        }
    }
    
    // 5. Адрес регистрации – блок между "Адрес регистрации:" и "Тип документа, удостоверяющий личность"
    $addrBlock = extractBetween($joined, 'Адрес регистрации:', 'Тип документа, удостоверяющий личность');
    if ($addrBlock) {
        $addr = parseAddress($addrBlock);
        if (!empty($addr)) {
            $currentApp['adress_registr'] = $addr;
            error_log("Адрес регистрации: " . json_encode($addr, JSON_UNESCAPED_UNICODE));
        }
    }
    
    // 6. Документ личности – блок между "Тип документа, удостоверяющий личность:" и "Тип документа об образовании:"
    $docBlock = extractBetween($joined, 'Тип документа, удостоверяющий личность:', 'Тип документа об образовании:');
    if ($docBlock) {
        if (preg_match('/^(.*)$/mu', trim($docBlock), $m)) {
            $currentApp['document'] = trim($m[1]);
        }
        if (preg_match('/Серия[:\\s]*([^\\s]+)?\\s*Номер[:\\s]*([^\\s]+?)(?=(Дата|Кем|$))/ui', $docBlock, $m)) {
            $ser = trim($m[1] ?? '');
            $num = trim($m[2] ?? '');
            if (mb_strtolower($ser, 'UTF-8') === 'нет') $ser = '';
            $currentApp['ser_num'] = trim($ser . ' ' . $num);
        }
        if (preg_match('/Дата выдачи[:\\s]*([\\d\\.\\/-]+)/ui', $docBlock, $m)) {
            $parsed = parseDate($m[1]);
            if ($parsed) $currentApp['date_vidachi_doc'] = $parsed;
        }
        if (preg_match('/Кем выдан[:\\s]*([\\p{L}\\d\\s\\.,\\"«»\\-]+?)(?:Код подразделения|$)/ui', $docBlock, $m)) {
            $currentApp['kem_vidan_doc'] = trim($m[1]);
        }
    }
    
    // 7. Документ об образовании – блок между "Тип документа об образовании:" и "Общежитие:"
    $eduBlock = extractBetween($joined, 'Тип документа об образовании:', 'Общежитие:');
    if ($eduBlock) {
        $eduTitle = trim(strtok($eduBlock, "\n"));
        if ($eduTitle) {
            $currentApp['doc_obr_title'] = $eduTitle;
            $docId = findDocObrByTitle($eduTitle, $conn);
            if ($docId) $currentApp['id_doc_obr'] = $docId;
        }
        if (preg_match('/Дата выдачи[:\\s]*([\\d\\.\\/-]+)/ui', $eduBlock, $m)) {
            $parsed = parseDate($m[1]);
            if ($parsed) $currentApp['date_doc_obr'] = $parsed;
        }
        if (preg_match('/Серия[:\\s]*([^\\s]+)?\\s*Номер[:\\s]*([^\\s]+?)(?=(Дата|Кем|$))/ui', $eduBlock, $m)) {
            $series = $m[1] ?? '';
            $number = $m[2] ?? '';
            if (mb_strtolower($series, 'UTF-8') === 'нет') $series = '';
            $currentApp['num_doc_obr'] = buildEducationNumber($eduTitle, $series, $number);
        }
        // Берем "Кем выдан" до "Дата выдачи/Серия/Номер" или конца блока, убираем лишнее
        if (preg_match('/Кем выдан[:\\s]*([^\\n]*?)(?=(Дата выдачи|Серия|Номер|$))/ui', $eduBlock, $m)) {
            $val = trim($m[1]);
            $val = trim($val, " \t\n\r|");
            $currentApp['kem_doc_obr'] = $val;
        }
    }
    
    // 8. Общежитие
    if (preg_match('/Общежитие[:\\s]*([^\\n]+)/ui', $joined, $m)) {
        $lineObzh = mb_strtolower(trim($m[1]), 'UTF-8');
        if (strpos($lineObzh, 'требует') !== false) {
            $currentApp['obzh'] = 1;
        } elseif (strpos($lineObzh, 'не') !== false) {
            $currentApp['obzh'] = 0;
        }
    }
    
    // 9. Достижения
    if (preg_match('/Индивидуальные достижения[:\\s]*([^\\n]+)/ui', $joined, $m)) {
        $ach = trim($m[1]);
        $ach = trim($ach, " \t\n\r|");
        if ($ach !== '') {
            $currentApp['dostizheniya'] = mb_substr($ach, 0, 100);
        }
    }
    
    // 10. Средний балл (формат X.XXXX, без округления — усечение до 4 знаков)
    if (preg_match('/ср[\\.\\s]*бал+[^\d]*([0-9]+[\\.,]?[0-9]{0,4})/ui', $joined, $m)) {
        $raw = str_replace(',', '.', $m[1]);
        if ($raw !== '') {
            $parts = explode('.', $raw, 2);
            if (count($parts) === 2) {
                $frac = substr($parts[1], 0, 4);
                $raw = $parts[0] . '.' . $frac;
            }
            $currentApp['sr_ball_attest'] = $raw;
        }
    }
    
    // 11. Целевое
    if (preg_match('/договору\\s+о\\s+целевом\\s+обучении[:\\s]+(да|yes|1)/ui', $joined)) {
        $currentApp['pervooch_priem'] = 'Целевое обучение';
    }
    
    // 12. Специальности – берём блок после "Специальности:"
    $currentApp['specialties'] = [];
    $specBlock = extractBetween($joined, 'Специальности:', null);
    if ($specBlock) {
        // Разбиваем по вхождению "Специальность:"
        $parts = preg_split('/Специальность:/ui', $specBlock);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            // Код
            if (!preg_match('/(\\d{2}\\.\\d{2}\\.\\d{2,3})/u', $part, $mCode)) continue;
            $code = rtrim($mCode[1], '.');
            $spec = ['code' => $code];
            // Форма обучения
            if (preg_match('/Форма обучения:\\s*\\|?\\s*([^|\\n]+)/ui', $part, $mForma)) {
                $spec['forma_title'] = trim($mForma[1]);
            }
            // Форма оплаты
            if (preg_match('/Форма оплаты:\\s*\\|?\\s*([^|\\n]+)/ui', $part, $mPay)) {
                $spec['forma_oplaty'] = trim($mPay[1]);
            }
            // Уровень базового образования
            if (preg_match('/Уровень базового образования:\\s*\\|?\\s*([0-9]+)/ui', $part, $mClass)) {
                $spec['class'] = intval($mClass[1]);
            } elseif (preg_match('/Уровень базового образования:\\s*\\|?\\s*(9|11)\\s*классов/ui', $part, $mClassWord)) {
                $spec['class'] = intval($mClassWord[1]);
            }
            // Добавляем если уникально
            $exists = false;
            foreach ($currentApp['specialties'] as $sp) {
                if ($sp['code'] === $code && $sp['forma_title'] === $spec['forma_title']) { 
                    $exists = true; 
                    break; 
                }
            }
            if (!$exists) $currentApp['specialties'][] = $spec;
        }
    }
    
    if (!empty($currentApp['familiya']) && !empty($currentApp['imya']) && !empty($currentApp['otchestvo']) && !empty($currentApp['date_bd'])) {
        error_log("Заявление успешно распарсено (полный парсер)");
        return [$currentApp];
    }
    
    error_log("Заявление не распарсено - обязательные поля отсутствуют");
    return [];
}

// Построение номера документа об образовании с учетом типа
function buildEducationNumber($docTitle, $series, $number) {
    $docTitleLower = mb_strtolower($docTitle ?? '', 'UTF-8');
    $series = trim($series ?? '');
    $number = trim($number ?? '');
    
    if (empty($series)) {
        return $number;
    }
    
    if (strpos($docTitleLower, 'аттестат') !== false) {
        return $series . $number; // без пробела
    }
    if (strpos($docTitleLower, 'диплом') !== false) {
        return trim($series . ' ' . $number); // с пробелом
    }
    return trim($series . ' ' . $number);
}

// Функция создания абитуриента с адресом и местом рождения
function createAbiturient($data, $conn) {
    $familiya = $data['familiya'] ?? '';
    $imya = $data['imya'] ?? '';
    $otchestvo = $data['otchestvo'] ?? '';
    $dateBd = $data['date_bd'] ?? null;
    $snils = $data['snils'] ?? null;
    $document = $data['document'] ?? null;
    $serNum = $data['ser_num'] ?? '';
    $dateVidachiDoc = $data['date_vidachi_doc'] ?? null;
    $kemVidanDoc = $data['kem_vidan_doc'] ?? '';
    $idDocObr = $data['id_doc_obr'] ?? null;
    $numDocObr = $data['num_doc_obr'] ?? null;
    $dateDocObr = $data['date_doc_obr'] ?? null;
    $kemDocObr = $data['kem_doc_obr'] ?? '';
    $obzh = isset($data['obzh']) ? intval($data['obzh']) : null;
    $dostizheniya = $data['dostizheniya'] ?? null;
    $pervooch = $data['pervooch_priem'] ?? null;
    $srBall = $data['sr_ball_attest'] ?? null;
    
    if (empty($familiya) || empty($imya) || empty($otchestvo) || empty($dateBd)) {
        return ['error' => 'Отсутствуют обязательные поля'];
    }
    
    // Адрес регистрации
    $addr = $data['adress_registr'] ?? [];
    $addrOblast = $addr['oblast'] ?? 'не указано';
    $addrGorod = $addr['gorod'] ?? 'не указано';
    $addrUlica = $addr['ulica'] ?? 'не указано';
    $addrDom = $addr['dom'] ?? 'н/д';
    $addrKorpus = $addr['korpus'] ?? null;
    $addrKv = $addr['kv'] ?? null;
    $addrIndex = intval($addr['indecs'] ?? 0);
    
    $stmtAddr = $conn->prepare("INSERT INTO adress_registr (oblast, gorod, ulica, dom, korpus, kv, indecs) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$stmtAddr) {
        return ['error' => 'Ошибка подготовки адреса: ' . $conn->error];
    }
    $stmtAddr->bind_param("ssssssi", $addrOblast, $addrGorod, $addrUlica, $addrDom, $addrKorpus, $addrKv, $addrIndex);
    if (!$stmtAddr->execute()) {
        $err = $stmtAddr->error;
        $stmtAddr->close();
        return ['error' => 'Ошибка сохранения адреса: ' . $err];
    }
    $addrId = $conn->insert_id;
    $stmtAddr->close();
    
    // Место рождения
    $place = $data['mesto_rozhd'] ?? [];
    $placeGorod = $place['gorod'] ?? 'не указано';
    $placeOblast = $place['oblast'] ?? 'не указано';
    $stmtPlace = $conn->prepare("INSERT INTO mesto_rozhd (gorod, oblast) VALUES (?, ?)");
    if (!$stmtPlace) {
        return ['error' => 'Ошибка подготовки места рождения: ' . $conn->error];
    }
    $stmtPlace->bind_param("ss", $placeGorod, $placeOblast);
    if (!$stmtPlace->execute()) {
        $err = $stmtPlace->error;
        $stmtPlace->close();
        return ['error' => 'Ошибка сохранения места рождения: ' . $err];
    }
    $placeId = $conn->insert_id;
    $stmtPlace->close();
    
    // Основная вставка в abit
    $sql = "INSERT INTO abit (
        familiya, imya, otchestvo, snils, date_bd, document, ser_num, date_vidachi_doc, kem_vidan_doc,
        id_doc_obr, num_doc_obr, date_doc_obr, kem_doc_obr, obzh, dostizheniya, sr_ball_attest, pervooch_priem,
        adress_registr, mesto_rozhd
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['error' => 'Ошибка подготовки запроса: ' . $conn->error];
    }
    
    // kem_doc_obr NOT NULL, ser_num NOT NULL -> ставим пустую строку если нет
    $serNum = $serNum ?? '';
    $kemDocObr = $kemDocObr ?? '';
    
    $stmt->bind_param(
        "sssssssssisssisssii",
        $familiya,
        $imya,
        $otchestvo,
        $snils,
        $dateBd,
        $document,
        $serNum,
        $dateVidachiDoc,
        $kemVidanDoc,
        $idDocObr,
        $numDocObr,
        $dateDocObr,
        $kemDocObr,
        $obzh,
        $dostizheniya,
        $srBall,
        $pervooch,
        $addrId,
        $placeId
    );
    
    if ($stmt->execute()) {
        $abitId = $conn->insert_id;
        $stmt->close();
        return $abitId;
    } else {
        $err = $stmt->error;
        $stmt->close();
        return ['error' => 'Ошибка выполнения запроса: ' . $err];
    }
}

// Проверка существования кода
function checkIfCodeExists($code, $conn) {
    $code = rtrim(trim($code), '.');
    $codeWithDot = $code . '.';
    $codeWithoutDot = $code;
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM profec_spec WHERE code = ? OR code = ?");
    if (!$stmt) return false;
    $stmt->bind_param("ss", $codeWithDot, $codeWithoutDot);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row['cnt'] > 0;
}

// Вспомогательная функция для поиска ID класса
function findClassIdByNum($num, $conn) {
    // Преобразуем 9->1, 11->2 напрямую
    if ($num == 9) return 1;
    if ($num == 11) return 2;
    return null;
}


// Поиск специальности с учетом class (уровень) и формы обучения
function findSpecialtyByCode($code, $classNum, $formaTitle, $conn) {
    $code = rtrim(trim($code), '.');
    
    // Преобразуем форму обучения в числовой формат (1-очная, 2-заочная)
    $formaNumber = null;
    if ($formaTitle) {
        $formaTitleLower = mb_strtolower($formaTitle, 'UTF-8');
        if (strpos($formaTitleLower, 'заоч') !== false) {
            $formaNumber = 2; // Заочная
        } else {
            $formaNumber = 1; // Очная (по умолчанию)
        }
    }
    
    // Преобразуем класс в числовой формат (9->1, 11->2)
    $classNumber = null;
    if ($classNum == 9) {
        $classNumber = 1;
    } elseif ($classNum == 11) {
        $classNumber = 2;
    }
    
    // Формируем шаблон поиска: код + возможные суффиксы
    $codePattern = $code . '%';
    
    // Пробуем несколько вариантов поиска
    $searchQueries = [];
    
    // 1. Поиск по точному коду с учетом класса и формы
    $sql1 = "SELECT id_prof_spec FROM profec_spec 
             WHERE (code LIKE ? OR socr LIKE ?)";
    $params1 = ["$codePattern", "%$code%"];
    
    if ($classNumber) {
        $sql1 .= " AND class = ?";
        $params1[] = $classNumber;
    }
    
    if ($formaNumber) {
        $sql1 .= " AND forma_obuch = ?";
        $params1[] = $formaNumber;
    }
    
    $sql1 .= " LIMIT 1";
    $searchQueries[] = ['sql' => $sql1, 'params' => $params1];
    
    // 2. Поиск по коду с учетом класса (без учета формы)
    if ($classNumber && $formaNumber) {
        $sql2 = "SELECT id_prof_spec FROM profec_spec 
                 WHERE (code LIKE ? OR socr LIKE ?)
                 AND class = ? 
                 LIMIT 1";
        $params2 = ["$codePattern", "%$code%", $classNumber];
        $searchQueries[] = ['sql' => $sql2, 'params' => $params2];
    }
    
    // 3. Поиск только по коду (без класса и формы)
    $sql3 = "SELECT id_prof_spec FROM profec_spec 
             WHERE code LIKE ? OR socr LIKE ? 
             LIMIT 1";
    $params3 = ["$codePattern", "%$code%"];
    $searchQueries[] = ['sql' => $sql3, 'params' => $params3];
    
    // 4. Поиск по части кода в начале строки
    $sql4 = "SELECT id_prof_spec FROM profec_spec 
             WHERE SUBSTRING(code, 1, 8) = ? OR SUBSTRING(code, 1, 9) = ?
             LIMIT 1";
    $params4 = [substr($code, 0, 8), substr($code, 0, 9)];
    $searchQueries[] = ['sql' => $sql4, 'params' => $params4];
    
    // Выполняем поиск по всем вариантам
    foreach ($searchQueries as $query) {
        $stmt = $conn->prepare($query['sql']);
        if ($stmt) {
            // Динамическое связывание параметров
            $types = '';
            foreach ($query['params'] as $param) {
                if (is_int($param)) {
                    $types .= 'i';
                } else {
                    $types .= 's';
                }
            }
            
            $stmt->bind_param($types, ...$query['params']);
            $stmt->execute();
            $res = $stmt->get_result();
            
            if ($res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $stmt->close();
                
                // Логируем найденную специальность для отладки
                $debugSql = "SELECT code, socr, title, class, forma_obuch FROM profec_spec WHERE id_prof_spec = ?";
                $debugStmt = $conn->prepare($debugSql);
                $debugStmt->bind_param("i", $row['id_prof_spec']);
                $debugStmt->execute();
                $debugResult = $debugStmt->get_result();
                $specInfo = $debugResult->fetch_assoc();
                $debugStmt->close();
                
                error_log("Найдена специальность: ID={$row['id_prof_spec']}, Код='{$specInfo['code']}', Сокр='{$specInfo['socr']}', Класс={$specInfo['class']}, Форма={$specInfo['forma_obuch']}");
                
                return $row['id_prof_spec'];
            }
            $stmt->close();
        }
    }
    
    // Если не нашли, выведем отладочную информацию
    error_log("Не найдена специальность: код='$code', класс='$classNum' ($classNumber), форма='$formaTitle' ($formaNumber)");
    
    // Выведем все специальности с похожим кодом
    $debugSql = "SELECT id_prof_spec, code, socr, title, class, forma_obuch FROM profec_spec WHERE code LIKE ? OR socr LIKE ? LIMIT 10";
    $debugStmt = $conn->prepare($debugSql);
    $debugStmt->bind_param("ss", $codePattern, "%$code%");
    $debugStmt->execute();
    $debugResult = $debugStmt->get_result();
    error_log("Найдено похожих специальностей: " . $debugResult->num_rows);
    while ($row = $debugResult->fetch_assoc()) {
        error_log("  - ID: {$row['id_prof_spec']}, Код: {$row['code']}, Сокр: {$row['socr']}, Название: {$row['title']}, Класс: {$row['class']}, Форма: {$row['forma_obuch']}");
    }
    $debugStmt->close();
    
    return null;
}


// Нормализация названия формы обучения
function normalizeFormaTitle($formaTitle) {
    $formaTitle = mb_strtolower(trim($formaTitle), 'UTF-8');
    
    $map = [
        'очная' => 'Очная',
        'очно' => 'Очная',
        'дневная' => 'Очная',
        'заочная' => 'Заочная',
        'заочно' => 'Заочная',
        'очно-заочная' => 'Очно-заочная',
        'вечерняя' => 'Очно-заочная',
        'дистанционная' => 'Дистанционная',
        'дистанционно' => 'Дистанционная',
        'онлайн' => 'Дистанционная',
    ];
    
    foreach ($map as $key => $value) {
        if (mb_strpos($formaTitle, $key) !== false) {
            return $value;
        }
    }
    
    // Если не нашли в карте, возвращаем исходное название с заглавной буквы
    return mb_convert_case(trim($formaTitle), MB_CASE_TITLE, 'UTF-8');
}

// Поиск ID формы обучения по названию
function findFormaIdByTitle($formaTitle, $conn) {
    $formaTitle = trim($formaTitle);
    if (empty($formaTitle)) {
        return null;
    }
    
    // Предполагается, что есть таблица form_obuch с полями id_forma и title
    $stmt = $conn->prepare("SELECT id_forma FROM form_obuch WHERE title = ? LIMIT 1");
    if (!$stmt) {
        // Если таблицы нет, возвращаем null
        return null;
    }
    
    $stmt->bind_param("s", $formaTitle);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $stmt->close();
        return $row['id_forma'];
    }
    
    $stmt->close();
    
    // Если точное совпадение не найдено, пробуем поиск по LIKE
    $stmt = $conn->prepare("SELECT id_forma FROM form_obuch WHERE title LIKE ? LIMIT 1");
    if ($stmt) {
        $like = "%$formaTitle%";
        $stmt->bind_param("s", $like);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $stmt->close();
            return $row['id_forma'];
        }
        $stmt->close();
    }
    
    return null;
}

// Проверка дубликата заявления (одна специальность + одна форма обучения)
function checkDuplicateApplication($abitId, $specId, $formaTitle, $conn) {
    // Если форма обучения не указана, проверяем только по специальности
    if (empty($formaTitle)) {
        $sql = "SELECT COUNT(*) as cnt FROM zayav WHERE id_abitur = ? AND id_spec_prof = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return false;
        $stmt->bind_param("ii", $abitId, $specId);
    } else {
        // Если форма обучения указана, проверяем через JOIN с таблицей profec_spec
        $formaNormalized = normalizeFormaTitle($formaTitle);
        $formaId = findFormaIdByTitle($formaNormalized, $conn);
        
        if ($formaId) {
            $sql = "SELECT COUNT(*) as cnt FROM zayav z 
                    JOIN profec_spec ps ON z.id_spec_prof = ps.id_prof_spec 
                    WHERE z.id_abitur = ? 
                    AND z.id_spec_prof = ? 
                    AND ps.id_forma_obuch = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) return false;
            $stmt->bind_param("iii", $abitId, $specId, $formaId);
        } else {
            // Если не нашли ID формы, проверяем по названию в поле forma_obuch
            $sql = "SELECT COUNT(*) as cnt FROM zayav z 
                    JOIN profec_spec ps ON z.id_spec_prof = ps.id_prof_spec 
                    WHERE z.id_abitur = ? 
                    AND z.id_spec_prof = ? 
                    AND ps.forma_obuch LIKE ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) return false;
            $like = "%" . $formaNormalized . "%";
            $stmt->bind_param("iis", $abitId, $specId, $like);
        }
    }
    
    $stmt->execute();
    $res = $stmt->get_result();
    $count = $res->fetch_assoc()['cnt'];
    $stmt->close();
    
    return $count > 0;
}

// Поиск id документа об образовании по title
function findDocObrByTitle($title, $conn) {
    if (empty($title)) return null;
    $like = '%' . $title . '%';
    $stmt = $conn->prepare("SELECT id_doc_obr FROM doc_obr WHERE title LIKE ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $stmt->close();
        return $row['id_doc_obr'];
    }
    $stmt->close();
    return null;
}

// Парсинг адреса
function parseAddress($str) {
    $addr = [];
    $str = trim($str);
    // Отрезаем все, что идет после "Тип документа" — это уже другой раздел
    $str = preg_replace('/тип\\s+документа.*$/iu', '', $str);
    // Убираем повторяющиеся разделители "|"
    $str = str_replace('|', ',', $str);
    $parts = array_map('trim', preg_split('/[,;]/u', $str));
    
    $addr['oblast'] = $parts[0] ?? '';
    $addr['gorod'] = $parts[1] ?? '';
    $addr['ulica'] = $parts[2] ?? '';
    $addr['dom'] = $parts[3] ?? '';
    
    // Ищем корпус/квартиру в оставшихся частях
    $rest = implode(' ', array_slice($parts, 4));
    if (preg_match('/корп\\.?\\s*([\\w\\d]+)/ui', $rest, $m)) {
        $addr['korpus'] = $m[1];
    } else {
        $addr['korpus'] = null;
    }
    if (preg_match('/кв\\.?\\s*([\\w\\d]+)/ui', $rest, $m)) {
        $addr['kv'] = $m[1];
    } else {
        $addr['kv'] = null;
    }
    if (preg_match('/(\\d{5,6})/u', $str, $m)) {
        $addr['indecs'] = $m[1];
    }
    return array_filter($addr, function($v) { return $v !== ''; });
}

// Парсинг места рождения
function parsePlace($str) {
    $place = [];
    $str = trim($str);
    // Если формат "г. Инта Республика Коми", берём первую часть как город, остальное в область
    if (preg_match('/^(г\\.?\\s*[^,]+)(.*)$/ui', $str, $m)) {
        $place['gorod'] = trim($m[1]);
        $place['oblast'] = trim($m[2]);
    } else {
        $parts = preg_split('/[,;]/u', $str);
        $place['gorod'] = trim($parts[0] ?? '');
        $place['oblast'] = trim($parts[1] ?? '');
    }
    if ($place['oblast'] === $place['gorod']) {
        // если только один элемент, область оставляем пустой
        $place['oblast'] = '';
    }
    return $place;
}

// Вырезает текст между двумя метками (если $endMark = null — до конца)
function extractBetween($text, $startMark, $endMark = null) {
    $posStart = mb_stripos($text, $startMark);
    if ($posStart === false) return '';
    $posStart += mb_strlen($startMark);
    if ($endMark !== null) {
        $posEnd = mb_stripos($text, $endMark, $posStart);
        if ($posEnd === false) $posEnd = mb_strlen($text);
    } else {
        $posEnd = mb_strlen($text);
    }
    return trim(mb_substr($text, $posStart, $posEnd - $posStart));
}

// Парсинг даты в Y-m-d
function parseDate($dateStr) {
    $dateStr = trim($dateStr);
    $formats = ['d.m.Y', 'd/m/Y', 'Y-m-d', 'd-m-Y'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $dateStr);
        if ($dt) {
            return $dt->format('Y-m-d');
        }
    }
    return null;
}

error_log("=== END IMPORT PROCESS ===");
?>
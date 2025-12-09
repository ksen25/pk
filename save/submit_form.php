<?php
session_start();
if (!isset($_SESSION)) {
    die(json_encode(['success' => false, 'error' => 'Сессия не запущена']));
}

ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Подключение к базе данных
$host = 'localhost';
$db = 'pk_2025';
$user = 'root';
$pass = 'root';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(['success' => false, 'error' => 'Ошибка подключения к БД: ' . $e->getMessage()]));
}

// Функция для проверки дублирования специальностей (модифицированная версия)
function checkDuplicateSpecialties($pdo, $abitId, $selectedSpecialties) {
    try {
        $stmt = $pdo->prepare("SELECT z.id_spec_prof, ps.title 
                             FROM zayav z
                             JOIN profec_spec ps ON z.id_spec_prof = ps.id_prof_spec
                             WHERE z.id_abitur = ? AND z.id_spec_prof IN (" . 
                             implode(',', array_fill(0, count($selectedSpecialties), '?')) . ")");
        $stmt->execute(array_merge([$abitId], $selectedSpecialties));
        $existingSpecs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($existingSpecs)) {
            $duplicates = array_map(function($spec) { return $spec['title']; }, $existingSpecs);
            return [
                'has_duplicates' => true,
                'duplicates' => $duplicates
            ];
        }
        
        return ['has_duplicates' => false];
    } catch (PDOException $e) {
        error_log("Ошибка проверки дублирования: " . $e->getMessage());
        return ['has_duplicates' => false];
    }
}

// Основные параметры
$maxApplications = 5;
$snils = isset($_POST['snils']) ? trim($_POST['snils']) : null;
$selectedSpecialties = isset($_POST['profession_specialty']) ? (array)$_POST['profession_specialty'] : [];
$newApplicationsCount = count($selectedSpecialties);

// Валидация
if (empty($selectedSpecialties)) {
    die(json_encode(['success' => false, 'error' => 'Выберите хотя бы одну специальность']));
}

foreach ($selectedSpecialties as $specId) {
    if (!is_numeric($specId)) {
        die(json_encode(['success' => false, 'error' => 'Неверный ID специальности: ' . htmlspecialchars($specId)]));
    }
}

// Обработка существующих абитуриентов
if ($snils) {
    try {
        $stmt = $pdo->prepare("SELECT id_abit FROM abit WHERE snils = ?");
        $stmt->execute([$snils]);
        $abit = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($abit) {
            // Проверяем дубли, но не завершаем выполнение
            $duplicateCheck = checkDuplicateSpecialties($pdo, $abit['id_abit'], $selectedSpecialties);
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM zayav WHERE id_abitur = ?");
            $stmt->execute([$abit['id_abit']]);
            $currentApplications = $stmt->fetchColumn();
            
            if (($currentApplications + $newApplicationsCount) > $maxApplications) {
                $remaining = $maxApplications - $currentApplications;
                die(json_encode([
                    'success' => false,
                    'error' => "Максимум $maxApplications заявлений. Вы можете подать еще $remaining"
                ]));
            }

            try {
                $pdo->beginTransaction();
                $sql = "INSERT INTO zayav (id_abitur, id_spec_prof, date) VALUES (?, ?, NOW())";
                $stmt = $pdo->prepare($sql);

                $addedCount = 0;
                foreach ($selectedSpecialties as $specId) {
                    // Проверяем, нет ли уже такой специальности у абитуриента
                    $checkStmt = $pdo->prepare("SELECT 1 FROM zayav WHERE id_abitur = ? AND id_spec_prof = ?");
                    $checkStmt->execute([$abit['id_abit'], $specId]);
                    
                    if (!$checkStmt->fetch()) {
                        if ($stmt->execute([$abit['id_abit'], $specId])) {
                            $addedCount++;
                        }
                    }
                }
                
                if ($addedCount === 0) {
                    $pdo->rollBack();
                    $message = $duplicateCheck['has_duplicates'] ? 
                        'Все выбранные специальности уже были добавлены ранее: ' . implode(', ', $duplicateCheck['duplicates']) :
                        'Не удалось добавить ни одного заявления';
                    die(json_encode(['success' => false, 'error' => $message]));
                }
                
                $pdo->commit();
                $_SESSION['id_abit'] = $abit['id_abit'];
                
                $message = $duplicateCheck['has_duplicates'] ? 
                    "Добавлено $addedCount новых заявлений (некоторые специальности уже были добавлены ранее)" :
                    "Успешно добавлено $addedCount заявлений";
                
                echo json_encode(['success' => true, 'message' => $message]);
                exit;
                
            } catch (Exception $e) {
                $pdo->rollBack();
                die(json_encode(['success' => false, 'error' => $e->getMessage()]));
            }
        }
    } catch (PDOException $e) {
        die(json_encode(['success' => false, 'error' => 'Ошибка работы с БД: ' . $e->getMessage()]));
    }
}

// Обработка новых абитуриентов
if ($newApplicationsCount > $maxApplications) {
    die(json_encode([
        'success' => false,
        'error' => "Максимальное количество заявлений - $maxApplications"
    ]));
}

try {
    $pdo->beginTransaction();
    
    // 1. Вставка адресов
    try {
        $stmt = $pdo->prepare("INSERT INTO adress_prozhiv (oblast, gorod, ulica, dom, korpus, kv, indecs) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['prozhiv_oblast'] ?? '',
            $_POST['prozhiv_gorod'] ?? '',
            $_POST['prozhiv_ulica'] ?? '',
            $_POST['prozhiv_dom'] ?? '',
            $_POST['prozhiv_korpus'] ?? null,
            $_POST['prozhiv_kv'] ?? null,
            $_POST['prozhiv_index'] ?? ''
        ]);
        $adress_prozhiv_id = $pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO adress_registr (oblast, gorod, ulica, dom, korpus, kv, indecs) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_POST['registr_oblast'] ?? '',
            $_POST['registr_gorod'] ?? '',
            $_POST['registr_ulica'] ?? '',
            $_POST['registr_dom'] ?? '',
            $_POST['registr_korpus'] ?? null,
            $_POST['registr_kv'] ?? null,
            $_POST['registr_index'] ?? ''
        ]);
        $adress_registr_id = $pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO mesto_rozhd (gorod, oblast) VALUES (?, ?)");
        $stmt->execute([
            $_POST['mesto_rozhd_gorod'] ?? '',
            $_POST['mesto_rozhd_oblast'] ?? ''
        ]);
        $mesto_rozhd_id = $pdo->lastInsertId();
    } catch (PDOException $e) {
        throw new Exception("Ошибка вставки адресов: " . $e->getMessage());
    }
    
    // 2. Обработка языка
    $selected_language = $_POST['foreign_languages'] ?? null;
    $other_language = $_POST['other_language'] ?? null;
    $in_yaz = null;
    $dr_in_yaz = null;
    
    if ($selected_language === 'other' && !empty($other_language)) {
        $dr_in_yaz = $other_language;
    } elseif (is_numeric($selected_language)) {
        $in_yaz = (int)$selected_language;
    }
    
    // 3. Вставка абитуриента с проверкой параметров
    try {
        $sql = "INSERT INTO abit (
            familiya, imya, otchestvo, snils, phone, date_bd, document, ser_num, date_vidachi_doc, kem_vidan_doc, 
            polis, komp_polis, email, id_doc_obr, num_doc_obr, 
            date_doc_obr, kem_doc_obr, in_yaz, dr_in_yaz, usl_ovz, obzh, grazhdanstvo, kat_grazhd, pervooch_priem, dostizheniya, sr_ball_attest, adress_prozhiv, adress_registr, mesto_rozhd
        ) VALUES (
            :familiya, :imya, :otchestvo, :snils, :phone, :date_bd, :document, :ser_num, :date_vidachi_doc, :kem_vidan_doc, 
            :polis, :komp_polis, :email, :id_doc_obr, :num_doc_obr, 
            :date_doc_obr, :kem_doc_obr, :in_yaz, :dr_in_yaz, :usl_ovz, :obzh, :grazhdanstvo, :kat_grazhd, :pervooch_priem, :dostizheniya, :sr_ball_attest, :adress_prozhiv, :adress_registr, :mesto_rozhd
        )";
        
        $params = [
            ':familiya' => $_POST['surname'] ?? '',
            ':imya' => $_POST['name'] ?? '',
            ':otchestvo' => $_POST['patronymic'] ?? '',
            ':snils' => $snils ?? '',
            ':phone' => $_POST['phone'] ?? '',
            ':date_bd' => $_POST['birthdate'] ?? '',
            ':document' => $_POST['identity_document'] ?? '',
            ':ser_num' => str_replace(' ', '', $_POST['series_number'] ?? ''),
            ':date_vidachi_doc' => $_POST['issue_date'] ?? '',
            ':kem_vidan_doc' => $_POST['issued_by'] ?? '',
            ':polis' => $_POST['insurance_policy_number'] ?? '',
            ':komp_polis' => $_POST['insurance_company'] ?? '',
            ':email' => $_POST['email'] ?? '',
            ':id_doc_obr' => $_POST['foreign_documents'][0] ?? null,
            ':num_doc_obr' => $_POST['seriesNumberDocObr'] ?? '',
            ':date_doc_obr' => $_POST['issueDateDocObr'] ?? '',
            ':kem_doc_obr' => $_POST['issuedByDocObr'] ?? '',
            ':in_yaz' => $in_yaz,
            ':dr_in_yaz' => $dr_in_yaz,
            ':usl_ovz' => $_POST['usl_ovz'] ?? null,
            ':obzh' => $_POST['obzh'] ?? null,
            ':grazhdanstvo' => $_POST['grazhdanstvo'] ?? '',
            ':kat_grazhd' => $_POST['kat_grazhd'] ?? '',
            ':pervooch_priem' => $_POST['perviy'] ?? '',
            ':dostizheniya' => $_POST['personal_achievements'] ?? '',
            ':sr_ball_attest' => $_POST['average_score'] ?? '',
            ':adress_prozhiv' => $adress_prozhiv_id,
            ':adress_registr' => $adress_registr_id,
            ':mesto_rozhd' => $mesto_rozhd_id
        ];

        // Проверка количества параметров
        if (count($params) !== 29) {
            throw new Exception("Неверное количество параметров для вставки абитуриента. Ожидается 29, получено " . count($params));
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $abitId = $pdo->lastInsertId();
        $_SESSION['id_abit'] = $abitId;
    } catch (PDOException $e) {
        throw new Exception("Ошибка вставки абитуриента: " . $e->getMessage());
    }

    // 4. Вставка заявлений
    try {
        $sql = "INSERT INTO zayav (id_abitur, id_spec_prof, date) VALUES (?, ?, NOW())";
        $stmt = $pdo->prepare($sql);
        
        foreach ($selectedSpecialties as $specId) {
            if (!$stmt->execute([$abitId, $specId])) {
                throw new Exception("Ошибка вставки заявления для специальности $specId");
            }
        }
    } catch (PDOException $e) {
        throw new Exception("Ошибка вставки заявлений: " . $e->getMessage());
    }
    
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Абитуриент и заявления добавлены']);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Ошибка при сохранении данных: " . $e->getMessage() . "\nPOST данные: " . print_r($_POST, true));
    die(json_encode(['success' => false, 'error' => 'Ошибка при сохранении данных: ' . $e->getMessage()]));
}
?>
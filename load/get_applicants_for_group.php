<?php
require_once '../config/config.php';

$spec_id = $_GET['spec_id'] ?? 0;
$class = $_GET['class'] ?? 0;
$forma = $_GET['forma'] ?? 0;

// Получаем абитуриентов с оригиналами на указанную специальность
$sql = "
SELECT 
    z.id_zayav,
    a.familiya,
    a.imya,
    a.otchestvo,
    a.snils,
    z.group_id,
    g.name as group_name
FROM zayav z
JOIN abit a ON z.id_abitur = a.id_abit
LEFT JOIN `groups` g ON z.group_id = g.id
WHERE z.id_spec_prof = ? 
    AND z.original = 1
    AND NOT EXISTS (
        SELECT 1 FROM zayav z2 
        WHERE z2.id_abitur = z.id_abitur 
        AND z2.issue_note LIKE 'Документы выданы%'
    )
ORDER BY a.familiya, a.imya, a.otchestvo
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $spec_id);
$stmt->execute();
$result = $stmt->get_result();

$applicants = [];
while ($row = $result->fetch_assoc()) {
    $applicants[] = $row;
}

echo json_encode(['applicants' => $applicants]);
?>
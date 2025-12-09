<?php
session_start();
require 'pages/config/config.php'; // Подключение к базе данных 

$error = '';

$valid_login = "secretary";
$valid_password = "admin123"; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['abit_login'])) {
        header("Location: pages/application.php");
        exit();
    } elseif (isset($_POST['secretary_login'])) {  
        $login = trim($_POST['login'] ?? '');
        $password = trim($_POST['password'] ?? '');
        
        if (empty($login) || empty($password)) {
            $error = "Логин и пароль обязательны для заполнения";
        } else {
            // Проверяем учётные данные напрямую
            if ($login === $valid_login && $password === $valid_password) {
                $_SESSION['secretary_logged_in'] = true;
                header("Location: pages/zayavleniya.php");
                exit();
            } else {
                $error = "Неверный логин или пароль";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="pages/css/styles.css">
    <title>Авторизация</title>
    <link rel="shortcut icon" href="img/ykuipt_logo.png">
</head>
<body>
    <h2 class="form_auth_h">Выберите роль</h2>
    
    <div class="form_auth">
        <form method="POST">
            <h3>Вход для абитуриента</h3>       
            <button type="submit" name="abit_login">Войти как абитуриент</button>
        </form>
        
        <form method="POST">
            <h3>Вход для секретаря</h3>
            <div class="form-group">
                <label for="login">Логин:</label>
                <input type="text" id="login" name="login" required>
            </div>
            <div class="form-group">
                <label for="password">Пароль:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" name="secretary_login">Войти как секретарь</button>
        </form>

        <form action="pages/administrator.php" method="get">
            <h3>Вход для администратора</h3>
            <button type="submit">Войти как администратор</button>
        </form>
    </div>
    
    <?php if (!empty($error)) echo "<p style='color:red; text-align: center;'>$error</p>"; ?>
</body>
</html>
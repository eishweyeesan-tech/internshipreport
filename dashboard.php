<?php
require_once 'auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Internship Report System</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="max-w-4xl mx-auto p-8">
        <div class="bg-white rounded-lg shadow-md p-6">
            <h1 class="text-2xl font-bold text-gray-800">Dashboard</h1>
            <p class="text-gray-600 mt-2">Welcome, <?= htmlspecialchars($_SESSION['username']) ?>!</p>
            <p class="text-gray-500 text-sm">Role: <?= htmlspecialchars($_SESSION['role']) ?></p>
            <a href="logout.php" class="inline-block mt-4 text-red-600 hover:underline">Logout</a>
        </div>
    </div>
</body>
</html>

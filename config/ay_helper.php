<?php
/**
 * ay_helper.php – Academic year filter helper.
 *
 * Include AFTER database.php and init_year.php.
 *
 * Provides:
 *   get_current_ay_id()          – returns the active year ID (int|null)
 *   get_ay_filter($pdo, $table) – returns ['sql' => 'AND ...', 'params' => [...]]
 */

/**
 * Return the currently selected academic year ID from the session.
 * Returns null if no year is selected (e.g. pre-migration table).
 */
function get_current_ay_id(): ?int {
    $id = (int) ($_SESSION['selected_academic_year_id'] ?? 0);
    return $id > 0 ? $id : null;
}

/**
 * Build a WHERE-clause fragment that filters users by the current academic year.
 *
 * Returns an associative array:
 *   'sql'    => string fragment to append (starts with " AND ")
 *   'params' => array of bind values (0 or 1 element)
 *
 * If no year is selected, returns an empty fragment (no filter applied).
 *
 * Usage:
 *   $ay = get_ay_filter($pdo);
 *   $sql = "SELECT ... FROM users u WHERE u.role = 'student'" . $ay['sql'];
 *   $stmt->execute($ay['params']);
 *
 * @param PDO    $pdo   Database connection (used only to verify the column exists)
 * @param string $table The table alias or name that owns the academic_year_id column
 *                      (defaults to 'u' for the users table)
 */
function get_ay_filter(PDO $pdo, string $table = 'u'): array {
    $ay_id = get_current_ay_id();

    if ($ay_id === null) {
        return ['sql' => '', 'params' => []];
    }

    return [
        'sql'    => " AND {$table}.academic_year_id = ?",
        'params' => [$ay_id],
    ];
}

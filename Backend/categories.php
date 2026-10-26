<?php
// Backend/categories.php
// Budget categories = 5 standard ones + custom ones that students add themselves.
//
// budget_categories()            -> standard + ALL custom categories (use this for showing names)
// budget_categories($walletId)   -> standard + only that student's custom categories (use this for forms)
// sponsor_categories($sponsorId) -> standard + custom categories of the students that sponsor actively sponsors
//
// Custom categories are stored in the custom_categories table and use the code "c" + id (e.g. "c7"),
// so they never clash with the standard codes below.

const STANDARD_CATEGORIES = [
    'tuition'   => 'Tuition',
    'rent'      => 'Rent',
    'food'      => 'Food',
    'transport' => 'Transport',
    'academic'  => 'Academic Materials',
];

const MAX_CUSTOM_CATEGORIES = 10;

function budget_categories(?int $walletId = null): array
{
    global $conn;
    static $cache = [];

    $key = $walletId === null ? 'all' : (string) $walletId;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $list = STANDARD_CATEGORIES;
    if ($conn instanceof mysqli) {
        try {
            if ($walletId === null) {
                $result = $conn->query('SELECT id, name FROM custom_categories ORDER BY name');
                $rows = $result->fetch_all(MYSQLI_ASSOC);
            } else {
                $stmt = $conn->prepare('SELECT id, name FROM custom_categories WHERE wallet_id = ? ORDER BY name');
                $stmt->bind_param('i', $walletId);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
            foreach ($rows as $r) {
                $list['c' . $r['id']] = $r['name'];
            }
        } catch (Throwable $e) {
            // Table not created yet: just use the standard categories
        }
    }
    return $cache[$key] = $list;
}

function sponsor_categories(int $sponsorId): array
{
    global $conn;
    $list = STANDARD_CATEGORIES;
    try {
        $stmt = $conn->prepare(
            'SELECT c.id, c.name FROM custom_categories c
             JOIN wallets w ON w.id = c.wallet_id
             JOIN sponsorships sp ON sp.student_id = w.student_id AND sp.status = \'active\'
             WHERE sp.sponsor_id = ? ORDER BY c.name'
        );
        $stmt->bind_param('i', $sponsorId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $list['c' . $r['id']] = $r['name'];
        }
        $stmt->close();
    } catch (Throwable $e) {
    }
    return $list;
}

/** Is this one of the student's own custom categories (not a standard one)? */
function is_custom_category(string $slug): bool
{
    return (bool) preg_match('/^c\d+$/', $slug);
}
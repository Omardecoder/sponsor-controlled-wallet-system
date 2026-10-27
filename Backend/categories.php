<?php
// Backend/categories.php
// Budget categories belong to each student: the student creates every category they need
// (helped by a list of suggestions). Nothing is fixed.
//
// budget_categories()            -> every category name in the system (use this for SHOWING names)
// budget_categories($walletId)   -> only that student's categories (use this for FORMS)
// sponsor_categories($sponsorId) -> the categories of the students that sponsor actively sponsors (filters)
//
// Categories are stored in the custom_categories table and use the code "c" + id (e.g. "c7").
// Step 23 converted the five old built-in categories into each student's own categories;
// their old codes are kept below only so very old records still show a name.

const LEGACY_CATEGORIES = [
    'tuition'   => 'Tuition',
    'rent'      => 'Rent',
    'food'      => 'Food',
    'transport' => 'Transport',
    'academic'  => 'Academic Materials',
];

// Quick picks offered on the budget page. The student can also type any name.
const SUGGESTED_CATEGORIES = [
    'Tuition', 'Rent', 'Food', 'Transport', 'Academic Materials', 'Internet & Airtime',
    'Medical', 'Personal Care', 'Clothing', 'Entertainment', 'Savings',
];

const MAX_CUSTOM_CATEGORIES = 15;   // per student

function budget_categories(?int $walletId = null): array
{
    global $conn;
    static $cache = [];

    $key = $walletId === null ? 'all' : (string) $walletId;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $list = $walletId === null ? LEGACY_CATEGORIES : [];
    if ($conn instanceof mysqli) {
        try {
            if ($walletId === null) {
                $rows = $conn->query('SELECT id, name FROM custom_categories ORDER BY name')->fetch_all(MYSQLI_ASSOC);
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
            // Table not created yet
        }
    }
    return $cache[$key] = $list;
}

function sponsor_categories(int $sponsorId): array
{
    global $conn;
    $list = LEGACY_CATEGORIES;
    try {
        $stmt = $conn->prepare(
            "SELECT c.id, c.name FROM custom_categories c
             JOIN wallets w ON w.id = c.wallet_id
             JOIN sponsorships sp ON sp.student_id = w.student_id AND sp.status = 'active'
             WHERE sp.sponsor_id = ? ORDER BY c.name"
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

/** Is this a student-created category code ("c" + id)? */
function is_custom_category(string $slug): bool
{
    return (bool) preg_match('/^c\d+$/', $slug);
}
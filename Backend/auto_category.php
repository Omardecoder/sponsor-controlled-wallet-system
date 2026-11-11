<?php
// Backend/auto_category.php
// Step 32: the budget line is picked automatically from the expense description.
//
// Two sources of words, matched against the student's OWN approved budget lines:
//   1. The student's history: words from expenses they recorded before, and the line they used
//      ("lunch" was recorded under "Food" -> "lunch" means Food). Strongest signal (weight 3).
//   2. Common Kenyan student spending words, attached to a line by its name
//      ("matatu", "fare", "boda" -> a line called "Transport"; "naivas", "lunch" -> "Food"). Weight 2.
//   Words of the line's own name count too ("rent" -> "Rent"). Weight 2.
// The same word list is sent to the page, so the suggestion appears while the student types;
// the server uses it again when the student leaves "Choose for me" selected.
// Usage:  require_once __DIR__ . '/auto_category.php';

// [regex on the budget line's NAME, words that point to that line]. Words are stems: "photocop"
// also matches "photocopy" and "photocopying".
const CATEGORY_RULES = [
    ['/tuition|fee|school|college|universit/i', ['fee', 'fees', 'tuition', 'exam', 'registration', 'semester', 'term', 'helb', 'invoice', 'admission']],
    ['/rent|hostel|house|accommodation|housing/i', ['rent', 'hostel', 'house', 'caretaker', 'landlord', 'room', 'deposit', 'accommodation', 'water bill', 'electricity', 'tokens', 'kplc']],
    ['/food|meal|lunch|grocer|upkeep/i', ['food', 'lunch', 'supper', 'dinner', 'breakfast', 'cafeteria', 'canteen', 'mess', 'hotel', 'chips', 'githeri', 'chapati', 'ugali', 'sukuma', 'unga', 'bread', 'milk', 'eggs', 'rice', 'sugar', 'tea', 'snack', 'naivas', 'quickmart', 'carrefour', 'chandarana', 'supermarket', 'kibanda', 'groceries', 'grocer', 'fruits', 'vegetables', 'meat', 'chicken', 'kfc', 'pizza']],
    ['/transport|travel|fare|commut/i', ['transport', 'fare', 'matatu', 'mat', 'bus', 'boda', 'bodaboda', 'uber', 'bolt', 'taxi', 'train', 'sgr', 'fuel', 'petrol', 'parking', 'travel', 'ticket', 'easycoach']],
    ['/academic|book|material|stationer|study|learning/i', ['book', 'books', 'textbook', 'novel', 'printing', 'print', 'photocop', 'stationery', 'pen', 'pens', 'notebook', 'exercise', 'calculator', 'laptop', 'flash', 'revision', 'handout', 'binding', 'lab', 'coat', 'project', 'research', 'library']],
    ['/internet|airtime|data|bundle|phone|wifi|communic/i', ['airtime', 'bundle', 'bundles', 'data', 'internet', 'wifi', 'safaricom', 'airtel', 'telkom', 'zuku', 'faiba', 'credit', 'okoa', 'sms']],
    ['/medic|health|hospital|clinic/i', ['hospital', 'clinic', 'pharmacy', 'chemist', 'medicine', 'drugs', 'doctor', 'dentist', 'treatment', 'tablets', 'panadol', 'nhif', 'sha', 'checkup', 'glasses']],
    ['/personal|care|toiletr|hygiene|beauty/i', ['soap', 'toothpaste', 'tissue', 'sanitary', 'pads', 'lotion', 'vaseline', 'deodorant', 'salon', 'barber', 'haircut', 'shave', 'detergent', 'omo', 'laundry', 'toiletries', 'cosmetics']],
    ['/cloth|wear|uniform|shoe/i', ['clothes', 'clothing', 'shirt', 'trousers', 'dress', 'skirt', 'shoes', 'sneakers', 'jacket', 'sweater', 'uniform', 'socks', 'mtumba', 'gikomba']],
    ['/entertain|leisure|fun|outing/i', ['movie', 'cinema', 'outing', 'party', 'netflix', 'showmax', 'spotify', 'game', 'games', 'trip', 'birthday', 'concert']],
    ['/saving|emergency/i', ['saving', 'savings', 'save', 'mshwari', 'sacco', 'chama', 'emergency']],
];

// Words that say nothing about the category
const CATEGORY_STOPWORDS = ['the', 'and', 'for', 'from', 'with', 'at', 'to', 'of', 'in', 'on', 'my', 'a', 'an', 'paid', 'pay', 'payment',
    'bought', 'buy', 'kes', 'ksh', 'shs', 'today', 'yesterday', 'week', 'month', 'this', 'that', 'some', 'new', 'one', 'two'];

/** "Lunch at Naivas!" -> ['lunch', 'naivas'] */
function category_tokens(string $text): array
{
    $words = preg_split('/[^a-z0-9]+/', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_filter($words, fn($w) => mb_strlen($w) >= 3 && !in_array($w, CATEGORY_STOPWORDS, true) && !ctype_digit($w)));
}

/**
 * The word list for this student's budget lines: [['w' => word, 'c' => category code, 'n' => weight], ...]
 * $lines = [category code => line name] (only the lines that can be chosen).
 */
function category_word_map(mysqli $conn, int $walletId, array $lines): array
{
    $map = [];
    $add = function (string $word, string $code, int $weight) use (&$map) {
        foreach (category_tokens($word) as $w) {
            $map[] = ['w' => $w, 'c' => $code, 'n' => $weight];
        }
    };

    foreach ($lines as $code => $name) {
        $add($name, $code, 2);                                    // the line's own name
        foreach (CATEGORY_RULES as [$pattern, $words]) {
            if (preg_match($pattern, $name)) {
                foreach ($words as $w) {
                    $add($w, $code, 2);
                }
            }
        }
    }

    // What the student did before: each word goes to the line it was used with most
    try {
        $stmt = $conn->prepare('SELECT category, description FROM expenses WHERE wallet_id = ? ORDER BY id DESC LIMIT 300');
        $stmt->bind_param('i', $walletId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Throwable $e) {
        $rows = [];
    }
    $seen = [];   // word => [code => count]
    foreach ($rows as $r) {
        if (!isset($lines[$r['category']])) {
            continue;
        }
        foreach (array_unique(category_tokens($r['description'])) as $w) {
            $seen[$w][$r['category']] = ($seen[$w][$r['category']] ?? 0) + 1;
        }
    }
    foreach ($seen as $w => $byCode) {
        arsort($byCode);
        $map[] = ['w' => $w, 'c' => array_key_first($byCode), 'n' => 3];
    }
    return $map;
}

/**
 * Best budget line for a description, or null. Returns ['code' => ..., 'word' => matched word, 'learned' => bool].
 * Matching: the same word, or a description word that starts with a listed word of 4+ letters.
 * (Frontend/record-spending.php does exactly the same in JavaScript.)
 */
function suggest_category(array $map, string $description): ?array
{
    $score = [];
    $why   = [];
    foreach (category_tokens($description) as $t) {
        foreach ($map as $m) {
            if ($t === $m['w'] || (mb_strlen($m['w']) >= 4 && str_starts_with($t, $m['w']))) {
                $score[$m['c']] = ($score[$m['c']] ?? 0) + $m['n'];
                if (!isset($why[$m['c']]) || $m['n'] > $why[$m['c']]['n']) {
                    $why[$m['c']] = ['word' => $t, 'n' => $m['n']];
                }
            }
        }
    }
    if (!$score) {
        return null;
    }
    arsort($score);
    $code = array_key_first($score);
    return ['code' => $code, 'word' => $why[$code]['word'], 'learned' => $why[$code]['n'] === 3];
}
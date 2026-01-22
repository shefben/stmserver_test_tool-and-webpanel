<?php
/**
 * Test Key Definitions
 * All 28 tests with their names and expected outcomes
 */

define('TEST_KEYS', [
    '1' => [
        'name' => 'Run the Steam.exe',
        'expected' => 'Client downloads, updates and presents the Welcome window',
        'category' => 'Client Startup'
    ],
    '2' => [
        'name' => 'Create a new account',
        'expected' => 'Account is created and automatically logged into, no errors in the Steam client logs. Email is sent if SMTP is enabled',
        'category' => 'Account Creation'
    ],
    '2a' => [
        'name' => 'Steam Subscriber Agreement displayed',
        'expected' => 'The SSA is shown during account creation',
        'category' => 'Account Creation'
    ],
    '2b' => [
        'name' => 'Choose unique account name',
        'expected' => 'Wizard proceeds to the email address page',
        'category' => 'Account Creation'
    ],
    '2c' => [
        'name' => 'Choose in-use account name',
        'expected' => 'Wizard shows alternative account names',
        'category' => 'Account Creation'
    ],
    '2d' => [
        'name' => 'Enter a unique email address',
        'expected' => 'Wizard proceeds to security question',
        'category' => 'Account Creation'
    ],
    '2e' => [
        'name' => 'Enter an existing email address',
        'expected' => 'Wizard prompts to find an existing account',
        'category' => 'Account Creation'
    ],
    '2f' => [
        'name' => 'Steam account information is displayed',
        'expected' => 'Information is correct and all images displayed',
        'category' => 'Account Creation'
    ],
    '3' => [
        'name' => 'Log into an existing account',
        'expected' => 'Client logs in and the main window is displayed',
        'category' => 'Authentication'
    ],
    '4' => [
        'name' => 'Log into an existing account made in an earlier client version',
        'expected' => 'Client logs in and the main window is displayed',
        'category' => 'Authentication'
    ],
    '5' => [
        'name' => 'Change password',
        'expected' => 'Client will only change password with correct information. Email is sent if SMTP is enabled',
        'category' => 'Account Management'
    ],
    '6' => [
        'name' => 'Change secret question answer',
        'expected' => 'Client will only change secret answer with correct information. Email is sent if SMTP is enabled',
        'category' => 'Account Management'
    ],
    '7' => [
        'name' => 'Change email address',
        'expected' => 'Email address on account is changed. Email is sent if SMTP is enabled',
        'category' => 'Account Management'
    ],
    '8' => [
        'name' => 'Add a non-Steam game',
        'expected' => 'Game shortcut is displayed in the My Games window',
        'category' => 'Games'
    ],
    '9' => [
        'name' => 'Purchase a game via Credit Card',
        'expected' => 'Purchase wizard shows and completes the transaction. My Games list updates with the added game(s). Check login still works',
        'category' => 'Purchases'
    ],
    '10' => [
        'name' => 'Activate a product on Steam',
        'expected' => 'CD-Key activation wizard shows and adds the game(s) to the My Games list. Check login still works',
        'category' => 'Purchases'
    ],
    '11' => [
        'name' => 'Download a game',
        'expected' => 'Game downloads and displays as installed in the My Games list',
        'category' => 'Content'
    ],
    '12a' => [
        'name' => 'GoldSrc Steam server browser',
        'expected' => 'Steam server browser shows running GoldSrc multiplayer games and/or HLTV sessions',
        'category' => 'Multiplayer - GoldSrc'
    ],
    '12b' => [
        'name' => 'GoldSrc in-game server browser',
        'expected' => 'In-game server browser shows running GoldSrc multiplayer games',
        'category' => 'Multiplayer - GoldSrc'
    ],
    '12c' => [
        'name' => 'GoldSrc Steam ticket validation',
        'expected' => 'GoldSrc server validates Steam ticket successfully',
        'category' => 'Multiplayer - GoldSrc'
    ],
    '12d' => [
        'name' => 'Source Steam server browser',
        'expected' => 'Steam server browser shows running Source multiplayer games and/or HLTV sessions',
        'category' => 'Multiplayer - Source'
    ],
    '12e' => [
        'name' => 'Source in-game server browser',
        'expected' => 'In-game server browser shows running Source multiplayer games',
        'category' => 'Multiplayer - Source'
    ],
    '12f' => [
        'name' => 'Source Steam ticket validation',
        'expected' => 'Source server validates Steam ticket successfully',
        'category' => 'Multiplayer - Source'
    ],
    '13' => [
        'name' => 'Account retrieval',
        'expected' => 'Account can be accessed via several methods',
        'category' => 'Account Recovery'
    ],
    '14a' => [
        'name' => 'Forgot password using email',
        'expected' => 'Email is sent if SMTP is enabled; this requires the correct validation code. Non-SMTP should accept any code',
        'category' => 'Account Recovery'
    ],
    '14b' => [
        'name' => 'Forgot password using CD key',
        'expected' => 'Password is reset when provided with a CD key registered on the account',
        'category' => 'Account Recovery'
    ],
    '14c' => [
        'name' => 'Forgot password using secret question',
        'expected' => 'Password is reset when provided with the correct secret question answer',
        'category' => 'Account Recovery'
    ],
    '15' => [
        'name' => 'Add a subscription',
        'expected' => 'Subscription list updates and game appears in My Games',
        'category' => 'Subscriptions'
    ],
    '16' => [
        'name' => 'Remove a subscription',
        'expected' => 'The My Games list is updated with the removal of the game(s)',
        'category' => 'Subscriptions'
    ],
    '17' => [
        'name' => 'Delete user',
        'expected' => 'The user is removed from the server',
        'category' => 'Account Management'
    ],
    '18' => [
        'name' => 'Tracker Friends - Login',
        'expected' => 'Tracker Friends service accepts login and displays friends list',
        'category' => 'Tracker Friends'
    ],
    '19' => [
        'name' => 'Tracker Friends - Add Friend',
        'expected' => 'Friend is added and appears in friends list',
        'category' => 'Tracker Friends'
    ],
    '20' => [
        'name' => 'Tracker Friends - Chat',
        'expected' => 'Chat messages can be sent and received between friends',
        'category' => 'Tracker Friends'
    ],
    '21' => [
        'name' => 'Tracker Friends - Change Status',
        'expected' => 'User status is updated for all users',
        'category' => 'Tracker Friends'
    ],
    '22' => [
        'name' => 'Tracker Friends - Play Minigame',
        'expected' => 'Minigame launches and can be played with friends',
        'category' => 'Tracker Friends'
    ],
    '23' => [
        'name' => 'Tracker Friends - Remove Friend',
        'expected' => 'Friend is removed from friends list',
        'category' => 'Tracker Friends'
    ],
    '24' => [
        'name' => 'CM Friends - Login',
        'expected' => 'CM Friends service accepts login and displays friends list',
        'category' => 'CM Friends'
    ],
    '25' => [
        'name' => 'CM Friends - Add Friend',
        'expected' => 'Friend is added and appears in friends list',
        'category' => 'CM Friends'
    ],
    '26' => [
        'name' => 'CM Friends - Chat',
        'expected' => 'Chat messages can be sent and received between friends',
        'category' => 'CM Friends'
    ],
    '27' => [
        'name' => 'CM Friends - Change Status',
        'expected' => 'User status is updated for all users',
        'category' => 'CM Friends'
    ],
    '28' => [
        'name' => 'CM Friends - Remove Friend',
        'expected' => 'Friend is removed from friends list',
        'category' => 'CM Friends'
    ]
]);

// Get test categories grouped
function getTestCategories() {
    $categories = [];
    foreach (TEST_KEYS as $key => $test) {
        $cat = $test['category'];
        if (!isset($categories[$cat])) {
            $categories[$cat] = [];
        }
        $categories[$cat][$key] = $test;
    }
    return $categories;
}

// Get test name by key
function getTestName($key) {
    return TEST_KEYS[$key]['name'] ?? "Test $key";
}

// Get test info by key
function getTestInfo($key) {
    return TEST_KEYS[$key] ?? null;
}

// Get all test keys
function getAllTestKeys() {
    return array_keys(TEST_KEYS);
}

// Sorted test keys (natural sort)
function getSortedTestKeys() {
    $keys = getAllTestKeys();
    usort($keys, function($a, $b) {
        // Extract numeric part
        preg_match('/^(\d+)([a-z]?)$/', $a, $matchA);
        preg_match('/^(\d+)([a-z]?)$/', $b, $matchB);

        $numA = (int)($matchA[1] ?? 0);
        $numB = (int)($matchB[1] ?? 0);

        if ($numA !== $numB) {
            return $numA - $numB;
        }

        return strcmp($matchA[2] ?? '', $matchB[2] ?? '');
    });
    return $keys;
}

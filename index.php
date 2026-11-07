<?php
declare(strict_types=1);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Cache-Control: no-store');
$_SESSION['token'] ??= bin2hex(random_bytes(32));

function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Display current labels for records imported from the original installer.
function label(string $value): string {
    return e(match ($value) {
        'Demo Employee' => 'John Smith',
        'Demo Second Customer' => 'Riverside Medical Centre',
        'DEMO-001' => 'ANDTR-001',
        'DEMO-002' => 'ANDTR-002',
        default => $value,
    });
}

function go(string $url = 'index.php'): void {
    header('Location: ' . $url, true, 303);
    exit;
}

function query(PDO $db, string $sql, array $args = []): PDOStatement {
    $statement = $db->prepare($sql);
    $statement->execute($args);
    return $statement;
}

function shareFor(PDO $db, array $user, int $id): ?array {
    $owner = $user['role'] === 'employee' ? 'employee_id' : 'customer_id';
    return query($db, "SELECT s.*, f.title, n.reference, n.device, n.details,
        sender.name AS employee, recipient.name AS customer
        FROM shared_findings s JOIN findings f ON f.id=s.finding_id
        JOIN notices n ON n.id=f.notice_id
        JOIN users sender ON sender.id=s.employee_id
        JOIN users recipient ON recipient.id=s.customer_id
        WHERE s.id=? AND s.$owner=?", [$id, $user['id']])->fetch() ?: null;
}

function token(): void {
    echo '<input type="hidden" name="token" value="' . e($_SESSION['token']) . '">';
}

function postText(string $name): string {
    return is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
}

try {
    $cfg = require is_file(__DIR__.'/config.local.php')
        ? __DIR__.'/config.local.php' : __DIR__.'/config.example.php';
    $db = new PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset=utf8mb4",
        $cfg['username'], $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
    $user = isset($_SESSION['user_id'])
        ? query($db, 'SELECT id,name,role FROM users WHERE id=?', [$_SESSION['user_id']])->fetch()
        : null;
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(503);
    exit('Database unavailable. Start MySQL in XAMPP, import database.sql, and check your local database settings.');
}

$error = '';
$success = $_SESSION['success'] ?? '';
unset($_SESSION['success']);
$shareId = filter_input(INPUT_GET, 'share', FILTER_VALIDATE_INT) ?: 0;
$isEmployee = $user && $user['role'] === 'employee';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['token'], postText('token'))) {
        http_response_code(403);
        exit('This form has expired. Reload the page and try again.');
    }
    try {
        $action = postText('action');
        if ($action === 'login' && !$user) {
            $account = query($db, 'SELECT id,password_hash FROM users WHERE email=?', [postText('email')])->fetch();
            if (!$account || !password_verify(postText('password'), $account['password_hash'])) {
                throw new DomainException('Check your email and password.');
            }
            session_regenerate_id(true);
            $_SESSION = ['user_id' => $account['id'], 'token' => bin2hex(random_bytes(32))];
            go();
        }
        if ($action === 'logout' && $user) {
            $_SESSION = [];
            session_destroy();
            go();
        }
        if ($action === 'share' && $isEmployee) {
            $raw = $_POST['findings'] ?? [];
            $recipient = filter_input(INPUT_POST, 'customer', FILTER_VALIDATE_INT);
            $message = postText('message');
            if (!is_array($raw) || !$raw || count($raw) > 50) {
                throw new DomainException('Select between 1 and 50 findings.');
            }
            $ids = [];
            foreach ($raw as $value) {
                $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) {
                    throw new DomainException('Invalid finding selection.');
                }
                $ids[$id] = $id;
            }
            if (!$recipient || !query($db, "SELECT id FROM users WHERE id=? AND role='customer'", [$recipient])->fetch()) {
                throw new DomainException('Select a customer.');
            }
            if (!mb_check_encoding($message, 'UTF-8') || mb_strlen($message, 'UTF-8') > 500) {
                throw new DomainException('Enter a valid message of up to 500 characters.');
            }
            $db->beginTransaction();
            foreach ($ids as $id) {
                $result = query($db, 'INSERT INTO shared_findings (finding_id,employee_id,customer_id,message)
                    SELECT id,?,?,? FROM findings WHERE id=?', [$user['id'], $recipient, $message, $id]);
                if ($result->rowCount() !== 1) {
                    throw new DomainException('A selected finding is unavailable. No findings were shared.');
                }
            }
            $db->commit();
            $_SESSION['success'] = count($ids) . ' finding(s) shared.';
            go('index.php#history');
        }
        if ($action === 'comment' && $user && !$isEmployee) {
            if (!shareFor($db, $user, $shareId)) {
                http_response_code(403);
                exit('Access denied.');
            }
            $body = postText('body');
            if ($body === '' || !mb_check_encoding($body, 'UTF-8') || mb_strlen($body, 'UTF-8') > 2000) {
                throw new DomainException('Enter a comment of 1 to 2,000 characters.');
            }
            query($db, 'INSERT INTO customer_comments (share_id,author_id,body) VALUES (?,?,?)', [$shareId, $user['id'], $body]);
            $_SESSION['success'] = 'Comment saved.';
            go('index.php?share=' . $shareId);
        }
        http_response_code(403);
        exit('Access denied.');
    } catch (DomainException $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log($exception->getMessage());
        $error = 'The action could not be saved. Please try again.';
    }
}
$share = $user && $shareId ? shareFor($db, $user, $shareId) : null;
if ($user && $shareId && !$share) {
    http_response_code(403);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ANDtr | Shared Findings</title>
<link rel="stylesheet" href="style.css?v=3">
</head>
<body class="<?= $isEmployee ? 'employee' : '' ?>">
<header><div class="bar"><a href="index.php"><strong>ANDtr</strong><span>Medical Device Safety and Recall Notice Platform</span></a>
<?php if ($user): ?>
<form method="post" action="index.php"><?php token(); ?>
<input type="hidden" name="action" value="logout">
<span><?= label($user['name']) ?></span> <button class="secondary">Sign out</button></form>
<?php endif; ?></div></header>
<div class="workspace">
<?php if ($isEmployee): ?>
<aside class="sidebar">
<strong>Company Employee</strong>
<p><?= label($user['name']) ?></p>
<nav aria-label="Employee workspace">
<a href="index.php">Dashboard</a>
<div class="nav-group">Share Findings</div>
<a class="subnav active" href="index.php#share-form">Share with Customers</a>
<a class="subnav" href="index.php#history">Shared History</a>
</nav>
<form method="post" action="index.php"><?php token(); ?>
<input type="hidden" name="action" value="logout"><button class="sidebar-logout">Logout</button></form>
</aside>
<?php endif; ?>
<main>
<?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if ($success): ?><p class="success" role="status"><?= e($success) ?></p><?php endif; ?>
<?php if (!$user): ?>
<section class="card login">
<p class="eyebrow">MEMBER 4 WORKSPACE</p>
<h1>Sign in</h1>
<p>Sign in as an employee to share findings or as a customer to review and comment.</p>
<form method="post"><?php token(); ?><input type="hidden" name="action" value="login">
<label for="email">Email</label><input id="email" name="email" type="email" autocomplete="username" required>
<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required>
<button>Sign in</button></form></section>
<?php elseif ($shareId && !$share): ?>
<section class="card"><h1>Access denied</h1><p>This share is unavailable to your account.</p><a href="index.php">Back to workspace</a></section>
<?php elseif ($share): ?>
<a href="index.php#history">← Back to <?= $isEmployee ? 'Shared History' : 'Shared Findings' ?></a>
<section class="card"><p class="eyebrow">SHARED FINDING · <?= label($share['reference']) ?></p>
<h1><?= e($share['title']) ?></h1>
<p><strong>Device</strong> <?= e($share['device']) ?></p>
<p><strong>Sent by</strong> <?= label($share['employee']) ?> to <?= label($share['customer']) ?></p>
<h2>Notice details</h2><p><?= e(preg_replace('/^Training example\. /', '', $share['details'])) ?></p>
<?php if ($share['message'] !== ''): ?><h2>Employee message</h2><p class="message"><?= e($share['message']) ?></p><?php endif; ?>
</section>
<section class="card"><h2>Customer comments</h2>
<?php $comments = query($db, 'SELECT c.*, u.name FROM customer_comments c JOIN users u ON u.id=c.author_id WHERE c.share_id=? ORDER BY c.id', [$shareId])->fetchAll(); ?>
<?php if (!$comments): ?><p>No comments yet.</p><?php endif; ?>
<?php foreach ($comments as $comment): ?>
<article><strong><?= label($comment['name']) ?></strong> <small><?= e($comment['created_at']) ?></small>
<p class="message"><?= e($comment['body']) ?></p></article>
<?php endforeach; ?>
<?php if (!$isEmployee): ?>
<form method="post"><?php token(); ?><input type="hidden" name="action" value="comment">
<label for="body">Add your comment</label>
<textarea id="body" name="body" maxlength="2000" required><?= e(postText('body')) ?></textarea>
<button>Post comment</button></form>
<?php endif; ?></section>
<?php else: ?>
<?php if ($isEmployee):
$findings = query($db, 'SELECT f.id,f.title,n.reference,n.device FROM findings f JOIN notices n ON n.id=f.notice_id ORDER BY f.id')->fetchAll();
$customers = query($db, "SELECT id,name,email FROM users WHERE role='customer' ORDER BY name")->fetchAll();
$selected = is_array($_POST['findings'] ?? null) ? $_POST['findings'] : [];
?>
<section class="share-page" id="share-form"><h1>Share Findings with Customers</h1>
<p class="intro">Select relevant findings and share them with a specific customer.</p>
<nav class="breadcrumb" aria-label="Breadcrumb">Dashboard › Share Findings › Share with Customers</nav>
<form method="post" action="index.php"><?php token(); ?><input type="hidden" name="action" value="share">
<section class="panel"><div class="panel-head"><h2>1. Select Finding to Share</h2>
<input id="search" type="search" aria-label="Search findings" placeholder="Search findings…"></div>
<div class="scroll"><table><thead><tr><th>Select</th><th>Finding Title</th><th>Notice ID</th><th>Device Name</th></tr></thead><tbody id="findings">
<?php foreach ($findings as $finding): ?>
<tr><td><input type="checkbox" name="findings[]" value="<?= e($finding['id']) ?>" aria-label="<?= e($finding['title']) ?>" <?= in_array((string)$finding['id'], $selected, true) ? 'checked' : '' ?>></td>
<td><?= e($finding['title']) ?></td><td><?= label($finding['reference']) ?></td><td><?= e($finding['device']) ?></td></tr>
<?php endforeach; ?></tbody></table></div></section>
<div class="share-grid"><section class="panel"><h2>2. Select Customer</h2><div class="panel-body"><label for="customer">Customer</label>
<select name="customer" id="customer" required><option value="">Choose a customer</option>
<?php foreach ($customers as $customer): ?>
<option data-email="<?= e($customer['email']) ?>" value="<?= e($customer['id']) ?>" <?= postText('customer') === (string)$customer['id'] ? 'selected' : '' ?>><?= label($customer['name']) ?></option>
<?php endforeach; ?></select>
<div class="recipient-details">
<strong>Customer Details</strong>
<p id="recipient-hint">Choose a customer to view their details.</p>
<dl id="recipient-info" hidden><dt>Organisation</dt><dd id="recipient-name"></dd><dt>Email</dt><dd id="recipient-email"></dd></dl>
</div></div></section>
<section class="panel"><h2>3. Add Message (Optional)</h2><div class="panel-body"><label for="message">Message to Customer</label>
<textarea id="message" name="message" maxlength="500" placeholder="Add a summary, recommended actions or other information."><?= e(postText('message')) ?></textarea>
<p class="counter" id="count">0 / 500</p>
</div></section></div>
<div class="share-actions"><button>Share Finding</button><a class="cancel" href="index.php">Cancel</a></div>
</form></section>
<script>
const search = document.getElementById('search');
search.addEventListener('input', () => {
    const term = search.value.toLowerCase().trim();
    document.querySelectorAll('#findings tr').forEach(row => {
        row.hidden = !row.textContent.toLowerCase().includes(term);
    });
});
const message = document.getElementById('message');
const updateCount = () => document.getElementById('count').textContent = message.value.length + ' / 500';
message.addEventListener('input', updateCount);
updateCount();
const recipient = document.getElementById('customer');
const updateRecipient = () => {
    const option = recipient.selectedOptions[0];
    document.getElementById('recipient-info').hidden = !recipient.value;
    document.getElementById('recipient-hint').hidden = !!recipient.value;
    document.getElementById('recipient-name').textContent = recipient.value ? option.textContent : '';
    document.getElementById('recipient-email').textContent = option.dataset.email || '';
};
recipient.addEventListener('change', updateRecipient);
updateRecipient();
</script>
<?php endif; ?>
<section class="card" id="history">
<p class="eyebrow"><?= $isEmployee ? 'EMPLOYEE WORKSPACE' : 'CUSTOMER WORKSPACE' ?></p>
<h2><?= $isEmployee ? 'Shared History' : 'Shared Findings' ?></h2>
<?php
$owner = $isEmployee ? 'employee_id' : 'customer_id';
$shares = query($db, "SELECT s.id,s.created_at,f.title,n.reference,u.name,
    (SELECT COUNT(*) FROM customer_comments c WHERE c.share_id=s.id) AS comment_count
    FROM shared_findings s JOIN findings f ON f.id=s.finding_id
    JOIN notices n ON n.id=f.notice_id JOIN users u ON u.id=s.customer_id
    WHERE s.$owner=? ORDER BY s.id DESC", [$user['id']])->fetchAll();
?>
<?php if (!$shares): ?><p>No findings have been shared yet.</p><?php endif; ?>
<?php foreach ($shares as $row): ?>
<article><a href="index.php?share=<?= e($row['id']) ?>"><strong><?= e($row['title']) ?></strong></a>
<p><?= label($row['reference']) ?> · <?= e($row['created_at']) ?> · <?= e($row['comment_count']) ?> comment(s)
<?= $isEmployee ? ' · ' . label($row['name']) : '' ?></p></article>
<?php endforeach; ?></section>
<?php endif; ?>
</main></div><footer>ANDtr · Medical Device Safety and Recall Notice Platform</footer>
</body></html>

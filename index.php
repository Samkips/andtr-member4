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
        'Demo Employee', 'John Smith' => 'Samuel Kipkemoi',
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
<style>
* { box-sizing: border-box; }
body { margin: 0; font: 16px/1.55 system-ui, sans-serif; color: #172941; background: #eef3f8; }
header { background: #102843; color: white; padding: 14px 20px; }
.bar { max-width: 1000px; margin: auto; display: flex; gap: 14px; flex-wrap: wrap; justify-content: space-between; align-items: center; }
header a { color: white; text-decoration: none; }
header strong { font-size: 1.4rem; margin-right: 12px; }
main { width: min(1000px, 94%); margin: 32px auto; }
.card { background: white; border: 1px solid #d6e0eb; border-radius: 14px; padding: clamp(16px, 4vw, 32px); margin: 16px 0; }
.login { max-width: 480px; margin: 60px auto; }
h1 { margin: 0; font-size: clamp(1.6rem, 4vw, 2.2rem); line-height: 1.2; }
h2 { font-size: 1.15rem; margin: 20px 0 8px; }
.eyebrow { color: #086c65; font-weight: 750; letter-spacing: .09em; font-size: .75rem; }
label { display: block; font-weight: 650; margin: 10px 0 4px; }
input:not([type=checkbox]), select, textarea { display: block; width: 100%; padding: 9px 12px; border: 1px solid #a5b5c5; border-radius: 7px; font: inherit; }
textarea { min-height: 90px; }
button { border: 0; border-radius: 7px; background: #086c65; color: white; padding: 10px 15px; cursor: pointer; font: inherit; font-weight: 650; margin-top: 12px; }
button.secondary { margin: 0 0 0 5px; background: #304e6d; }
a { color: #075b7d; }
.cancel { padding: 10px; }
.scroll { overflow-x: auto; }
table { border-collapse: collapse; width: 100%; margin-top: 10px; }
td, th { padding: 10px; border-bottom: 1px solid #dce5ee; text-align: left; }
th { background: #eff5fb; }
article { padding: 12px 0; border-top: 1px solid #dce5ee; }
article p { margin: 4px 0; }
.message { white-space: pre-wrap; overflow-wrap: anywhere; }
.counter, small, footer { color: #4b5d70; }
.counter { margin: 3px 0; text-align: right; }
.error, .success { border-radius: 7px; padding: 12px; }
.error { background: #ffe7e7; color: #8a1823; }
.success { background: #ddf5ec; color: #085c45; }
footer { text-align: center; padding: 20px; }

/* Employee workspace follows the report wireframe. */
.employee { background: #f1f2f4; color: #191c22; font: 14px/1.45 Arial, sans-serif; }
.employee header { background: white; color: inherit; border-bottom: 1px solid #afb4bb; }
.employee header a { color: inherit; }
.employee header strong { display: inline-block; border-right: 1px solid #aeb4ba; padding-right: 14px; }
.employee .bar { max-width: 1240px; }
.employee .workspace { display: grid; grid-template-columns: 210px minmax(0,1fr); max-width: 1240px; margin: auto; border: 1px solid #afb4bb; border-top: 0; background: white; }
.employee main { width: auto; min-width: 0; margin: 0; padding: 16px 20px; }
.sidebar { background: #f7f7f8; border-right: 1px solid #c8cbd0; padding: 18px 12px; }
.sidebar > p { margin: 2px 0 16px; }
.sidebar nav a, .nav-group { display: block; padding: 9px 10px; color: #212630; text-decoration: none; }
.nav-group { background: #e8eaed; font-weight: bold; }
.sidebar nav .subnav { padding-left: 22px; }
.sidebar nav .active { background: #dcdfe4; font-weight: bold; }
.sidebar-logout { background: transparent; color: #212630; padding-left: 10px; }
.share-page h1 { font-size: clamp(1.35rem, 3vw, 1.85rem); }
.intro { margin: 2px 0 8px; }
.breadcrumb { background: #eceef0; padding: 6px 10px; border-radius: 3px; margin-bottom: 12px; }
.panel { border: 1px solid #b9bec5; border-radius: 4px; overflow: hidden; }
.panel h2 { font-size: 1rem; margin: 0; padding: 9px 12px; background: #f3f4f5; }
.panel-head { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; justify-content: space-between; background: #f3f4f5; }
.panel-head input[type=search] { width: min(220px, 90%); margin: 5px 10px; }
.panel .scroll { padding: 0 10px 10px; }
.panel table { margin: 0; }
.panel th { background: #e9eaed; }
.panel th, .panel td { padding: 7px 9px; border: 1px solid #d8dbe0; }
.panel td:first-child { width: 62px; text-align: center; }
.share-grid { display: grid; grid-template-columns: 1fr 1.1fr; gap: 10px; margin-top: 10px; }
.panel-body { padding: 4px 12px 10px; }
.panel input:not([type=checkbox]), .panel select, .panel textarea { padding: 6px 8px; border-radius: 3px; border-color: #aeb4ba; }
.panel textarea { min-height: 115px; }
.recipient-details { margin-top: 9px; background: #f1f2f4; padding: 9px; overflow-wrap: anywhere; }
.recipient-details p, .recipient-details dl { margin: 4px 0 0; }
.recipient-details dt { font-weight: bold; }
.recipient-details dd { margin: 0 0 4px; }
.share-actions { display: flex; justify-content: flex-end; align-items: center; gap: 8px; margin-top: 10px; }
.share-actions button, .share-actions .cancel { margin: 0; border: 1px solid #aeb4ba; border-radius: 4px; color: #1b222b; background: #dce0e6; padding: 8px 16px; text-decoration: none; }
.share-actions .cancel { background: #f6f7f9; }
@media (max-width: 720px) {
    .employee .workspace, .share-grid { grid-template-columns: 1fr; }
    .sidebar { border-right: 0; border-bottom: 1px solid #c8cbd0; }
    .employee main { padding: 12px; }
}

/* Workspace visual polish */
.employee { background: #edf2f7; color: #17283d; }
.employee header { border-bottom-color: #d8e2ec; }
.employee header strong { color: #124c70; }
.employee .workspace { margin: 18px auto 0; border: 1px solid #d8e2ec; border-radius: 12px; overflow: hidden; box-shadow: 0 5px 20px #18395b0d; }
.employee main { padding: 24px; }
.sidebar { background: #f5f8fc; border-color: #d8e2ec; }
.sidebar > p { color: #52677b; }
.sidebar nav a, .nav-group { border-radius: 6px; }
.nav-group { background: transparent; color: #3d566d; }
.sidebar nav .active { background: #dcebf7; color: #124c70; }
.sidebar nav a:hover { background: #e7eff7; }
.intro { color: #52677b; margin-bottom: 14px; }
.breadcrumb { background: #eef4fa; color: #34536d; border-radius: 6px; }
.panel { border-color: #cddae6; border-radius: 8px; }
.panel h2, .panel-head { background: #f4f8fc; }
.panel .scroll { padding-top: 6px; }
.panel th { background: #eaf1f8; color: #274663; }
.panel th, .panel td { border-color: #dce6ef; }
.panel tbody tr:hover { background: #f4f8fc; }
.panel tbody tr:has(input:checked) { background: #e3f0fb; }
.panel input[type=checkbox] { accent-color: #14577f; }
.share-grid { gap: 14px; margin-top: 14px; }
.recipient-details { background: #f1f6fb; border-radius: 6px; }
.share-actions button { background: #14577f; color: #fff; border-color: #14577f; }
.share-actions button:hover { background: #104562; }
.share-actions .cancel:hover { background: #e7eff7; }
:focus-visible { outline: 3px solid #448ab9; outline-offset: 2px; }
@media (max-width: 720px) {
    .employee .workspace { margin: 0; border-radius: 0; }
    .employee main { padding: 14px; }
}

.customer { background: #edf2f7; }
.customer .bar { max-width: 1120px; }
.customer main { width: min(1120px, 92%); }
.findings-dashboard > h2 { font-size: clamp(1.8rem, 4vw, 2.5rem); margin: 0; }
.findings-dashboard > .eyebrow { margin: 0 0 7px; }
.findings-intro { color: #4b6076; margin: 6px 0 16px; }
.finding-total { display: inline-block; background: #dcebf7; color: #16476b; font-size: .85rem; font-weight: 700; padding: 5px 10px; border-radius: 20px; }
.findings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr)); gap: 16px; margin-top: 5px; }
.finding-card { display: flex; flex-direction: column; align-items: flex-start; padding: 20px; border: 1px solid #d1dfeb; border-radius: 12px; background: #fff; box-shadow: 0 3px 10px #17324d0d; min-width: 0; overflow-wrap: anywhere; }
.finding-meta { display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between; width: 100%; color: #4b6076; font-size: .8rem; }
.finding-meta span:first-child { background: #eaf3fa; color: #16476b; border-radius: 6px; padding: 2px 8px; font-weight: 700; }
.finding-card h3 { margin: 14px 0 3px; font-size: 1.2rem; }
.finding-card .finding-date { color: #4b6076; font-size: .83rem; margin-bottom: 16px; }
.open-finding { display: inline-flex; gap: 10px; margin-top: auto; background: #14577f; color: white; border-radius: 7px; padding: 7px 12px; text-decoration: none; font-weight: 650; }
.open-finding:hover { background: #104562; }
.customer .card textarea { border-radius: 8px; }

</style>
</head>
<body class="<?= $isEmployee ? 'employee' : ($user ? 'customer' : '') ?>">
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
<section class="<?= $isEmployee ? 'card' : 'findings-dashboard' ?>" id="history">
<p class="eyebrow"><?= $isEmployee ? 'EMPLOYEE WORKSPACE' : 'CUSTOMER WORKSPACE' ?></p>
<h2><?= $isEmployee ? 'Shared History' : 'Shared Findings' ?></h2>
<?php
$owner = $isEmployee ? 'employee_id' : 'customer_id';
$shares = query($db, "SELECT s.id,s.created_at,f.title,n.reference,n.device,u.name,
    (SELECT COUNT(*) FROM customer_comments c WHERE c.share_id=s.id) AS comment_count
    FROM shared_findings s JOIN findings f ON f.id=s.finding_id
    JOIN notices n ON n.id=f.notice_id JOIN users u ON u.id=s.customer_id
    WHERE s.$owner=? ORDER BY s.id DESC", [$user['id']])->fetchAll();
?>
<?php if (!$isEmployee): ?>
<p class="findings-intro">Review notices shared with <?= label($user['name']) ?> and respond with feedback.</p>
<p class="finding-total"><?= count($shares) ?> shared <?= count($shares) === 1 ? 'finding' : 'findings' ?></p>
<div class="findings-grid">
<?php foreach ($shares as $row): ?>
<article class="finding-card">
<div class="finding-meta"><span><?= label($row['reference']) ?></span><span><?= e($row['comment_count']) ?> comments</span></div>
<h3><?= e($row['title']) ?></h3>
<p><?= e($row['device']) ?></p>
<p class="finding-date">Shared <?= e($row['created_at']) ?></p>
<a class="open-finding" href="index.php?share=<?= e($row['id']) ?>">Open finding <span aria-hidden="true">→</span></a>
</article>
<?php endforeach; ?>
<?php if (!$shares): ?><p class="card">No findings have been shared yet.</p><?php endif; ?>
</div>
<?php else: ?>
<?php if (!$shares): ?><p>No findings have been shared yet.</p><?php endif; ?>
<?php foreach ($shares as $row): ?>
<article><a href="index.php?share=<?= e($row['id']) ?>"><strong><?= e($row['title']) ?></strong></a>
<p><?= label($row['reference']) ?> · <?= e($row['created_at']) ?> · <?= e($row['comment_count']) ?> comment(s)
<?= $isEmployee ? ' · ' . label($row['name']) : '' ?></p></article>
<?php endforeach; ?>
<?php endif; ?>
</section>
<?php endif; ?>
</main></div><footer>ANDtr · Medical Device Safety and Recall Notice Platform</footer>
</body></html>

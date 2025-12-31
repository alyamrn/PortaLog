<?php
require_once "db_connect.php";
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$vessel_id = $_SESSION['vessel_id'] ?? null;
if (!$vessel_id) {
    http_response_code(400);
    exit;
}

/* Show UNREAD only; flagged first, newest next; limit 3 */
$stmt = $pdo->prepare("
    SELECT reminder_id, modules_missing, log_date, message, created_at, is_flagged
    FROM reminders
    WHERE vessel_id = ? AND is_read = 0
    ORDER BY is_flagged DESC, created_at DESC
    LIMIT 3
");
$stmt->execute([$vessel_id]);
$reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$reminders) {
    echo '<div class="reminder-empty">No new reminders 🎉</div>';
    exit;
}

foreach ($reminders as $rem):
    $isFlagged = !empty($rem['is_flagged']);

    // Short title only
    $baseTitle = (trim(strtoupper($rem['modules_missing'])) === 'UNLOCK REQUEST')
        ? 'Unlock Request'
        : 'Missing Forms';

    $title = $baseTitle . (!empty($rem['log_date']) ? ' (' . htmlspecialchars($rem['log_date']) . ')' : '');
    $createdFmt = htmlspecialchars(date('d M Y H:i', strtotime($rem['created_at'])));
    $hoverMsg = htmlspecialchars(trim($rem['message'] ?? ''));
    ?>
    <style>
        .notification.slim {
            display: flex;
            gap: .6rem;
            padding: .55rem .7rem;
            border: 1px solid var(--color-light);
            border-radius: var(--border-radius-2);
            background: #fff;
            margin-bottom: .5rem;
            align-items: center;
            text-decoration: none;
            color: inherit;
            transition: background .2s, box-shadow .2s;
        }

        .notification.slim:hover {
            background: var(--color-light);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .notification.slim.flagged {
            background: var(--color-white);
            border-color:var(--color-danger);
        }

        .notification.slim .icon {
            color: var(--color-warning);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
        }

        .notification.slim .title {
            margin: 0;
            font-size: .95rem;
            font-weight: 600;
            color: var(--color-dark);
        }

        .notification.slim .created {
            color: var(--color-info-dark);
            font-size: .8rem;
        }

        .reminder-empty {
            color: var(--color-info-dark);
            padding: .6rem;
            text-align: center;
        }
    </style>
    <a href="captain-reminders.php?id=<?= (int) $rem['reminder_id'] ?>"
        class="notification slim <?= $isFlagged ? 'flagged' : '' ?>" title="<?= $hoverMsg ?>">
        <div class="icon">
            <span class="material-icons-sharp"><?= $isFlagged ? 'flag' : 'notifications' ?></span>
        </div>

        <div class="content">
            <div class="row-1">
                <h4 class="title"><?= $title ?></h4>
                <small class="created"><?= $createdFmt ?></small>
            </div>
        </div>
    </a>
<?php endforeach; ?>
<?php
session_start();

$dbFile = 'database.sqlite';
try {
    $conn = new PDO("sqlite:" . $dbFile);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $conn->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        balance REAL DEFAULT 0.00
    )");

    $conn->exec("CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        player_id TEXT,
        package_name TEXT,
        amount REAL,
        status TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

$msg = "";
$msg_type = "";

if (isset($_POST['register'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->rowCount() > 0) {
        $msg = "এই ইমেইল দিয়ে ইতিমধ্যে অ্যাকাউন্ট রয়েছে!";
        $msg_type = "error";
    } else {
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, balance) VALUES (?, ?, ?, 0.00)");
        if ($stmt->execute([$username, $email, $password])) {
            $msg = "রেজিস্ট্রেশন সফল হয়েছে! এবার লগইন করুন।";
            $msg_type = "success";
        } else {
            $msg = "রেজিস্ট্রেশন ব্যর্থ হয়েছে।";
            $msg_type = "error";
        }
    }
}

if (isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        header("Location: index.php");
        exit();
    } else {
        $msg = "ইমেইল অথবা পাসওয়ার্ড ভুল রয়েছে!";
        $msg_type = "error";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

if (isset($_POST['add_balance']) && isset($_SESSION['user_id'])) {
    $amount_to_add = floatval($_POST['amount']);
    $trxId = strtoupper(trim($_POST['trxId']));
    $userId = $_SESSION['user_id'];

    if (!empty($trxId)) {
        $apiKey = "HPAY-89A4-62A1-9969";
        $apiUrl = "https://hasanpay-default-rtdb.firebaseio.com/api_keys/{$apiKey}/by_trx/{$trxId}.json";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($data && isset($data['amount'])) {
            if (floatval($data['amount']) >= $amount_to_add) {
                $stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$amount_to_add, $userId]);
                $msg = "সফল! ৳{$amount_to_add} আপনার ওয়ালেটে যোগ হয়েছে।";
                $msg_type = "success";
            } else {
                $msg = "পেমেন্টের টাকার পরিমাণ মিলেননি! পাঠানো হয়েছে: ৳" . $data['amount'];
                $msg_type = "error";
            }
        } else {
            $msg = "ডাটাবেজে এই TrxID ({$trxId}) খুঁজে পাওয়া যায়নি!";
            $msg_type = "error";
        }
    }
}

if (isset($_POST['topup_order']) && isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    $playerId = trim($_POST['playerId']);
    $packageName = $_POST['packageName'];
    $packagePrice = floatval($_POST['packagePrice']);

    $stmt = $conn->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($userData['balance'] >= $packagePrice) {
        $stmt = $conn->prepare("UPDATE users SET balance = balance - ? WHERE id = ?");
        $stmt->execute([$packagePrice, $userId]);

        $stmt = $conn->prepare("INSERT INTO orders (user_id, player_id, package_name, amount, status) VALUES (?, ?, ?, ?, 'Completed')");
        $stmt->execute([$userId, $playerId, $packageName, $packagePrice]);

        $msg = "টপ-আপ সফল হয়েছে! UID: {$playerId}";
        $msg_type = "success";
    } else {
        $msg = "ওয়ালেটে পর্যাপ্ত ব্যালান্স নেই!";
        $msg_type = "error";
    }
}

$current_balance = 0.00;
if (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $uData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($uData) {
        $current_balance = $uData['balance'];
    }
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gaming Top-Up BD</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background: #07090e; color: #fff; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 12px; }
        .app-container { width: 100%; max-width: 420px; background: #111622; border-radius: 24px; box-shadow: 0 15px 35px rgba(0,0,0,0.6); border: 1px solid rgba(255,255,255,0.08); overflow: hidden; }
        .app-header { background: linear-gradient(135deg, #1f293d, #111622); padding: 24px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .app-header h2 { font-size: 22px; font-weight: 800; color: #facc15; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .app-header p { font-size: 12px; color: #9ca3af; margin-top: 4px; }
        .app-body { padding: 20px; }
        .alert { padding: 12px; border-radius: 12px; font-size: 13px; text-align: center; margin-bottom: 16px; font-weight: 500; }
        .alert.success { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .alert.error { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); }
        .wallet-card { background: linear-gradient(135deg, #1e3a8a, #1e1b4b); padding: 20px; border-radius: 16px; text-align: center; margin-bottom: 20px; border: 1px solid rgba(59, 130, 246, 0.3); box-shadow: inset 0 1px 0 rgba(255,255,255,0.1); }
        .wallet-card .user-name { font-size: 13px; color: #93c5fd; }
        .wallet-card .balance { font-size: 28px; font-weight: 800; color: #4ade80; margin: 6px 0; }
        .wallet-card .label { font-size: 11px; color: #cbd5e1; text-transform: uppercase; letter-spacing: 1px; }
        .form-card { background: #182030; padding: 16px; border-radius: 16px; margin-bottom: 16px; border: 1px solid rgba(255,255,255,0.05); }
        .form-card h4 { font-size: 13px; font-weight: 700; margin-bottom: 12px; display: flex; align-items: center; gap: 6px; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-size: 11px; color: #9ca3af; margin-bottom: 5px; font-weight: 500; }
        .form-control { width: 100%; padding: 11px 14px; background: #0f141f; border: 1px solid #2a3447; border-radius: 10px; color: #fff; font-size: 13px; outline: none; transition: all 0.3s; }
        .form-control:focus { border-color: #facc15; box-shadow: 0 0 0 2px rgba(250,204,21,0.2); }
        .btn { width: 100%; padding: 12px; border: none; border-radius: 10px; font-weight: 700; font-size: 13px; cursor: pointer; transition: all 0.2s; }
        .btn-primary { background: #facc15; color: #0f172a; box-shadow: 0 4px 12px rgba(250,204,21,0.3); }
        .btn-primary:hover { background: #eab308; }
        .btn-info { background: #2563eb; color: #fff; box-shadow: 0 4px 12px rgba(37,99,235,0.3); }
        .btn-info:hover { background: #1d4ed8; }
        .switch-text { text-align: center; font-size: 12px; color: #60a5fa; margin-top: 14px; cursor: pointer; font-weight: 500; }
        .switch-text:hover { text-decoration: underline; }
        .logout-btn { display: block; text-align: center; font-size: 12px; color: #ef4444; margin-top: 15px; text-decoration: none; font-weight: 600; }
        .logout-btn:hover { text-decoration: underline; }
        .hidden { display: none; }
    </style>
</head>
<body>

    <div class="app-container">
        <div class="app-header">
            <h2><i class="fas fa-gem"></i> Gaming Top-Up BD</h2>
            <p>প্রফেশনাল অটোমেটেড ডায়মন্ড শপ</p>
        </div>

        <div class="app-body">
            <?php if (!empty($msg)): ?>
                <div class="alert <?php echo $msg_type; ?>">
                    <?php echo $msg; ?>
                </div>
            <?php endif; ?>

            <?php if (!isset($_SESSION['user_id'])): ?>
                <div id="authContainer">
                    <form method="POST" id="loginForm">
                        <div class="form-card">
                            <h4 style="color: #facc15;"><i class="fas fa-sign-in-alt"></i> অ্যাকাউন্টে প্রবেশ করুন</h4>
                            <div class="form-group">
                                <label>ইমেইল এড্রেস</label>
                                <input type="email" name="email" required class="form-control" placeholder="example@gmail.com">
                            </div>
                            <div class="form-group">
                                <label>পাসওয়ার্ড</label>
                                <input type="password" name="password" required class="form-control" placeholder="••••••••">
                            </div>
                            <button type="submit" name="login" class="btn btn-primary" style="margin-top: 6px;">লগইন করুন</button>
                        </div>
                        <p class="switch-text" onclick="toggleAuth()">অ্যাকাউন্ট নেই? নতুন রেজিস্ট্রেশন করুন</p>
                    </form>

                    <form method="POST" id="regForm" class="hidden">
                        <div class="form-card">
                            <h4 style="color: #facc15;"><i class="fas fa-user-plus"></i> নতুন অ্যাকাউন্ট তৈরি</h4>
                            <div class="form-group">
                                <label>আপনার নাম</label>
                                <input type="text" name="username" required class="form-control" placeholder="আপনার নাম লিখুন">
                            </div>
                            <div class="form-group">
                                <label>ইমেইল এড্রেস</label>
                                <input type="email" name="email" required class="form-control" placeholder="example@gmail.com">
                            </div>
                            <div class="form-group">
                                <label>পাসওয়ার্ড</label>
                                <input type="password" name="password" required class="form-control" placeholder="••••••••">
                            </div>
                            <button type="submit" name="register" class="btn btn-primary" style="margin-top: 6px;">রেজিস্ট্রেশন সম্পন্ন করুন</button>
                        </div>
                        <p class="switch-text" onclick="toggleAuth()">আগে থেকেই অ্যাকাউন্ট আছে? লগইন করুন</p>
                    </form>
                </div>
            <?php else: ?>
                <div>
                    <div class="wallet-card">
                        <span class="user-name">স্বাগতম, <b><?php echo $_SESSION['username']; ?></b></span>
                        <div class="balance">৳<?php echo number_format($current_balance, 2); ?></div>
                        <span class="label">প্রধান ওয়ালেট ব্যালান্স</span>
                    </div>

                    <form method="POST" class="form-card">
                        <h4 style="color: #60a5fa;"><i class="fas fa-wallet"></i> অটো ব্যালান্স অ্যাড (HasanPay)</h4>
                        <div class="form-group">
                            <label>টাকার পরিমাণ (BDT)</label>
                            <input type="number" name="amount" required class="form-control" placeholder="যেমন: 50">
                        </div>
                        <div class="form-group">
                            <label>TrxID (ট্রানজেকশন আইডি)</label>
                            <input type="text" name="trxId" required class="form-control" placeholder="বিকাশ/নগদ TrxID দিন" style="text-transform: uppercase;">
                        </div>
                        <button type="submit" name="add_balance" class="btn btn-info">পেমেন্ট ভেরিফাই ও ব্যালান্স যোগ করুন</button>
                    </form>

                    <form method="POST" class="form-card">
                        <h4 style="color: #facc15;"><i class="fas fa-gem"></i> গেম টপ-আপ অর্ডার</h4>
                        <div class="form-group">
                            <label>Free Fire Player UID</label>
                            <input type="text" name="playerId" required class="form-control" placeholder="আপনার গেম UID দিন">
                        </div>
                        <div class="form-group">
                            <label>প্যাকেজ নির্বাচন করুন</label>
                            <select name="packageName" id="pkgSelect" onchange="updatePrice()" class="form-control">
                                <option value="25 Diamonds" data-price="25">25 Diamonds - ৳25</option>
                                <option value="50 Diamonds" data-price="48">50 Diamonds - ৳48</option>
                                <option value="115 Diamonds" data-price="95">115 Diamonds - ৳95</option>
                                <option value="Weekly Membership" data-price="160">Weekly Membership - ৳160</option>
                            </select>
                        </div>
                        <input type="hidden" name="packagePrice" id="packagePrice" value="25">
                        <button type="submit" name="topup_order" class="btn btn-primary">ওয়ালেট থেকে অর্ডার কনফার্ম করুন</button>
                    </form>

                    <a href="index.php?logout=true" class="logout-btn"><i class="fas fa-sign-out-alt"></i> অ্যাকাউন্ট থেকে লগআউট করুন</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleAuth() {
            document.getElementById('loginForm').classList.toggle('hidden');
            document.getElementById('regForm').classList.toggle('hidden');
        }
        function updatePrice() {
            var select = document.getElementById('pkgSelect');
            var price = select.options[select.selectedIndex].getAttribute('data-price');
            document.getElementById('packagePrice').value = price;
        }
    </script>
</body>
</html>

<?php
session_start();

// SQLite ডাটাবেজ কানেকশন (Render-এর জন্য শতভাগ নিরাপদ ও পারফেক্ট)
$dbFile = 'database.sqlite';
try {
    $conn = new PDO("sqlite:" . $dbFile);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // অটো টেবিল তৈরি (না থাকলে)
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

// ১. রেজিস্ট্রেশন লজিক
if (isset($_POST['register'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->rowCount() > 0) {
        $msg = "এই ইমেইল দিয়ে ইতিমধ্যে একটি অ্যাকাউন্ট রয়েছে!";
        $msg_type = "error";
    } else {
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, balance) VALUES (?, ?, ?, 0.00)");
        if ($stmt->execute([$username, $email, $password])) {
            $msg = "রেজিস্ট্রেশন সফল হয়েছে! এখন লগইন করুন।";
            $msg_type = "success";
        } else {
            $msg = "রেজিস্ট্রেশন ব্যর্থ হয়েছে।";
            $msg_type = "error";
        }
    }
}

// ২. লগইন লজিক
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

// ৩. লগআউট
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

// ৪. অটো ব্যালান্স অ্যাড (HasanPay Firebase API ভেরিফিকেশন)
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
                $msg = "পেমেন্টের টাকার পরিমাণ মিলেনি! পাঠানো হয়েছে: ৳" . $data['amount'];
                $msg_type = "error";
            }
        } else {
            $msg = "ডাটাবেজে এই TrxID ({$trxId}) খুঁজে পাওয়া যায়নি!";
            $msg_type = "error";
        }
    }
}

// ৫. ডায়মন্ড টপ-আপ অর্ডার লজিক
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

        $msg = "টপ-আপ অর্ডার সফল হয়েছে! UID: {$playerId}";
        $msg_type = "success";
    } else {
        $msg = "পর্যাপ্ত ব্যালান্স নেই!";
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
    <title>Free Fire Auto Top-Up (Render)</title>
    <script src="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-950 text-white font-sans antialiased flex items-center justify-center min-h-screen p-4">

    <div class="w-full max-w-md bg-gray-900 border border-gray-800 p-6 rounded-2xl shadow-2xl">
        <div class="text-center mb-5">
            <h2 class="text-2xl font-extrabold text-yellow-400"><i class="fas fa-gem"></i> Gaming Top-Up BD</h2>
            <p class="text-xs text-gray-400 mt-1">রেন্ডার ক্লাউড হোস্টিং ও অটো পেমেন্ট সিস্টেম</p>
        </div>

        <?php if (!empty($msg)): ?>
            <div class="mb-4 p-3 rounded-lg text-xs text-center font-medium <?php echo $msg_type === 'success' ? 'bg-green-950 text-green-300 border border-green-800' : 'bg-red-950 text-red-300 border border-red-800'; ?>">
                <?php echo $msg; ?>
            </div>
        <?php endif; ?>

        <?php if (!isset($_SESSION['user_id'])): ?>
            <div id="authContainer">
                <form method="POST" id="loginForm" class="space-y-4">
                    <h3 class="text-yellow-400 font-semibold text-sm">লগইন করুন</h3>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">ইমেইল</label>
                        <input type="email" name="email" required class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-white focus:outline-none focus:border-yellow-400">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">পাসওয়ার্ড</label>
                        <input type="password" name="password" required class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-white focus:outline-none focus:border-yellow-400">
                    </div>
                    <button type="submit" name="login" class="w-full bg-yellow-500 hover:bg-yellow-400 text-gray-950 font-bold py-2.5 rounded-lg text-sm transition">লগইন</button>
                    <p class="text-xs text-center text-blue-400 cursor-pointer mt-2" onclick="toggleAuth()">অ্যাকাউন্ট নেই? রেজিস্ট্রেশন করুন</p>
                </form>

                <form method="POST" id="regForm" class="space-y-4 hidden">
                    <h3 class="text-yellow-400 font-semibold text-sm">নতুন অ্যাকাউন্ট</h3>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">নাম</label>
                        <input type="text" name="username" required class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-white focus:outline-none focus:border-yellow-400">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">ইমেইল</label>
                        <input type="email" name="email" required class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-white focus:outline-none focus:border-yellow-400">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">পাসওয়ার্ড</label>
                        <input type="password" name="password" required class="w-full px-3 py-2 bg-gray-800 border border-gray-700 rounded-lg text-sm text-white focus:outline-none focus:border-yellow-400">
                    </div>
                    <button type="submit" name="register" class="w-full bg-yellow-500 hover:bg-yellow-400 text-gray-950 font-bold py-2.5 rounded-lg text-sm transition">রেজিস্ট্রেশন</button>
                    <p class="text-xs text-center text-blue-400 cursor-pointer mt-2" onclick="toggleAuth()">লগইন করুন</p>
                </form>
            </div>
        <?php else: ?>
            <div class="space-y-4">
                <div class="bg-gray-800 border border-gray-700 p-3.5 rounded-xl text-center">
                    <p class="text-xs text-gray-400">স্বাগতম, <span class="text-white font-bold"><?php echo $_SESSION['username']; ?></span></p>
                    <p class="text-xl font-bold text-green-400 mt-1">৳<?php echo number_format($current_balance, 2); ?></p>
                    <span class="text-[10px] text-gray-400 uppercase tracking-widest">ওয়ালেট ব্যালান্স</span>
                </div>

                <form method="POST" class="space-y-3 bg-gray-800/40 p-3.5 rounded-xl border border-gray-700/50">
                    <h4 class="text-xs font-bold text-blue-400 uppercase">💳 অটো ব্যালান্স অ্যাড</h4>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">টাকার পরিমাণ</label>
                        <input type="number" name="amount" required placeholder="যেমন: 50" class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded-lg text-sm text-white focus:outline-none focus:border-blue-400">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">TrxID</label>
                        <input type="text" name="trxId" required placeholder="TrxID দিন" class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded-lg text-sm text-white uppercase focus:outline-none focus:border-blue-400">
                    </div>
                    <button type="submit" name="add_balance" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-2 rounded-lg text-sm transition">পেমেন্ট ভেরিফাই করুন</button>
                </form>

                <form method="POST" class="space-y-3 bg-gray-800/40 p-3.5 rounded-xl border border-gray-700/50">
                    <h4 class="text-xs font-bold text-yellow-400 uppercase">💎 গেম টপ-আপ কিনুন</h4>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">Free Fire UID</label>
                        <input type="text" name="playerId" required placeholder="UID দিন" class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded-lg text-sm text-white focus:outline-none focus:border-yellow-400">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 mb-1">প্যাকেজ</label>
                        <select name="packageName" id="pkgSelect" onchange="updatePrice()" class="w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded-lg text-sm text-white focus:outline-none focus:border-yellow-400">
                            <option value="25 Diamonds" data-price="25">25 Diamonds - ৳25</option>
                            <option value="50 Diamonds" data-price="48">50 Diamonds - ৳48</option>
                            <option value="115 Diamonds" data-price="95">115 Diamonds - ৳95</option>
                            <option value="Weekly Membership" data-price="160">Weekly - ৳160</option>
                        </select>
                    </div>
                    <input type="hidden" name="packagePrice" id="packagePrice" value="25">
                    <button type="submit" name="topup_order" class="w-full bg-yellow-500 hover:bg-yellow-400 text-gray-950 font-bold py-2 rounded-lg text-sm transition">অর্ডার করুন</button>
                </form>

                <div class="text-center pt-1">
                    <a href="index.php?logout=true" class="text-xs text-red-400 hover:underline">লগআউট</a>
                </div>
            </div>
        <?php endif; ?>
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

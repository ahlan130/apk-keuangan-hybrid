<?php
/*
Unified index.php
- Supports SQLite (default) and MySQL
- Auto-creates schema if missing
- Writes DB connection/debug logs to logs/db.log
- Adds a DB test page: ?page=dbtest
- Keeps product/cart/admin/checkout/user management & laporan features
- Single-file final version (merged & complete)
*/

// ---- Configuration ----
// Choose 'sqlite' or 'mysql'
const DB_TYPE = 'sqlite'; // change to 'mysql' to use MySQL

// SQLite settings (default)
const DB_FILE = __DIR__ . '/data/db.sqlite';

// MySQL settings (used if DB_TYPE === 'mysql')
const MYSQL_HOST = '127.0.0.1';
const MYSQL_DB   = 'kurniyahjaya_db';
const MYSQL_USER = 'root';
const MYSQL_PASS = '';

// Shop/admin
const SHOP_PHONE = '6281909898007';
const ADMIN_USER = 'admin';
const ADMIN_PASS = 'admin123';

// Uploads & logs
const UPLOAD_DIR = __DIR__ . '/uploads/produk/';
const LOG_DIR = __DIR__ . '/logs/';
const LOG_FILE = LOG_DIR . 'db.log';

// ensure folders
if (!is_dir(__DIR__ . '/data')) @mkdir(__DIR__ . '/data', 0777, true);
if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0777, true);
if (!is_dir(LOG_DIR)) @mkdir(LOG_DIR, 0777, true);

// session
session_start();

// simple logger helper
function log_db($msg) {
    $t = date('Y-m-d H:i:s');
    $line = "[$t] $msg\n";
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

// connect DB with fallback
$db = null;
$connected_via = '';
try {
    if (DB_TYPE === 'sqlite') {
        $dsn = 'sqlite:' . DB_FILE;
        $db = new PDO($dsn);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $connected_via = 'sqlite';
        log_db("Connected via SQLite: " . DB_FILE);
    } else {
        // mysql
        $dsn = "mysql:host=" . MYSQL_HOST . ";dbname=" . MYSQL_DB . ";charset=utf8mb4";
        $db = new PDO($dsn, MYSQL_USER, MYSQL_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $connected_via = 'mysql';
        log_db("Connected via MySQL: " . MYSQL_HOST . " / DB: " . MYSQL_DB);
    }
} catch (PDOException $e) {
    $err = "DB connection failed: " . $e->getMessage();
    log_db($err);
    // show a friendly error page for debugging
    echo "<h3>Database connection error</h3><pre>" . htmlspecialchars($err) . "</pre>";
    exit;
}

// Check & create schema if missing (idempotent)
function ensure_schema($db) {
    // We'll check for the existence of each table; if missing, create it
    // The SQL differs slightly for MySQL vs SQLite (AUTOINCREMENT).
    $isMysql = (DB_TYPE === 'mysql');

    // helper to check table exists
    $exists = function($tbl) use ($db, $isMysql) {
        try {
            if ($isMysql) {
                $q = $db->prepare("SHOW TABLES LIKE ?");
                $q->execute([$tbl]);
                return $q->rowCount() > 0;
            } else {
                $q = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
                $q->execute([$tbl]);
                return $q->fetchColumn() !== false;
            }
        } catch (Exception $e) {
            return false;
        }
    };

    // products
    if (!$exists('products')) {
        $sql = $isMysql ?
            "CREATE TABLE products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255),
                price INT,
                image VARCHAR(255),
                stock INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" :
            "CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                price INTEGER,
                image TEXT,
                stock INTEGER DEFAULT 0,
                created_at TEXT
            )";
        $db->exec($sql);
        log_db("Created table: products");

        // insert sample products for convenience
        $samples = [
            ['Lemineral 600ml',4000],['Lemineral 1,5Lt',7000],['Lemineral Galon',40000],['Aqua Gallon',45000],
            ['Vit 330',5000],['Ciremai Cup',6000],['Ciremai Botol 600Ml',8000],['SUI 600',5000],
            ['Aqua 600Ml',4500],['Aqua 1.5Lt',7000],['The Pucuk 250Ml',6000],['Nipis Madu',6500],
            ['The Botol Sosro Pet',7000],['Es Teler',10000],['Panter',8000],['The Gelas',5500],
            ['The Semesta',6000],['The Rio',6500]
        ];
        $stmt = $db->prepare($isMysql
            ? 'INSERT INTO products (name, price, image, stock, created_at) VALUES (?, ?, ?, ?, NOW())'
            : 'INSERT INTO products (name, price, image, stock, created_at) VALUES (?, ?, ?, ?, datetime(\'now\'))'
        );
        foreach ($samples as $s) {
            $name = $s[0]; $price = $s[1];
            $imgUrl = 'https://source.unsplash.com/800x600/?drink,' . urlencode($name);
            try { $stmt->execute([$name, $price, $imgUrl, 99]); } catch (Exception $e) { /* ignore */ }
        }
        log_db("Inserted sample products");
    }

    // orders
    if (!$exists('orders')) {
        $sql = $isMysql ?
            "CREATE TABLE orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cust_name VARCHAR(255),
                cust_wa VARCHAR(50),
                address TEXT,
                payment VARCHAR(50),
                total INT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" :
            "CREATE TABLE orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cust_name TEXT,
                cust_wa TEXT,
                address TEXT,
                payment TEXT,
                total INTEGER,
                created_at TEXT
            )";
        $db->exec($sql);
        log_db("Created table: orders");
    }

    // order_items
    if (!$exists('order_items')) {
        $sql = $isMysql ?
            "CREATE TABLE order_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT,
                product_id INT,
                name VARCHAR(255),
                price INT,
                qty INT,
                sub_total INT,
                FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" :
            "CREATE TABLE order_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER,
                product_id INTEGER,
                name TEXT,
                price INTEGER,
                qty INTEGER,
                sub_total INTEGER
            )";
        $db->exec($sql);
        log_db("Created table: order_items");
    }

    // users
    if (!$exists('users')) {
        $sql = $isMysql ?
            "CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) UNIQUE,
                password VARCHAR(255),
                role VARCHAR(20),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" :
            "CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE,
                password TEXT,
                role TEXT,
                created_at TEXT
            )";
        $db->exec($sql);
        log_db("Created table: users");

        // Insert default admin user (password hashed)
        $pw = password_hash(ADMIN_PASS, PASSWORD_DEFAULT);
        $stmt = $db->prepare($isMysql
            ? "INSERT INTO users (username,password,role,created_at) VALUES (?,?,?,NOW())"
            : "INSERT INTO users (username,password,role,created_at) VALUES (?,?,?,datetime('now'))"
        );
        try {
            $stmt->execute([ADMIN_USER, $pw, 'admin']);
            log_db("Inserted default admin user: " . ADMIN_USER);
        } catch (Exception $e) {
            log_db("Insert admin failed: " . $e->getMessage());
        }
    }
}

// ensure schema
ensure_schema($db);

// ---------------------------------------------------------------------------
// The remainder of the original app (products, cart, admin, checkout, laporan)
// is kept with improved error handling and a new dbtest page.
// ---------------------------------------------------------------------------

function route() { return $_GET['page'] ?? 'home'; }
function is_admin() { return isset($_SESSION['admin']) && $_SESSION['admin']===true; }
function h($s){ return htmlspecialchars($s, ENT_QUOTES); }

$action = $_POST['action'] ?? null;
// LOGIN
if ($action === 'login') {
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    if ($u === ADMIN_USER && $p === ADMIN_PASS) {
        $_SESSION['admin']=true;
        header('Location: ?page=admin'); exit;
    } else {
        $error = 'Login gagal';
    }
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: ?'); exit; }

// Add to cart
if ($action === 'add_cart') {
    $pid = (int)($_POST['product_id'] ?? 0);
    $qty = max(1, (int)($_POST['qty'] ?? 1));
    if ($pid>0) {
        if (!isset($_SESSION['cart'])) $_SESSION['cart']=[];
        if (!isset($_SESSION['cart'][$pid])) $_SESSION['cart'][$pid]=0;
        $_SESSION['cart'][$pid] += $qty;
    }
    header('Location: ?page=cart'); exit;
}

// Update cart
if ($action === 'update_cart') {
    $quant = $_POST['qty'] ?? [];
    $new = [];
    foreach($quant as $k=>$v){ $k=(int)$k; $v=(int)$v; if($v>0) $new[$k]=$v; }
    $_SESSION['cart'] = $new;
    header('Location: ?page=cart'); exit;
}

// Admin add product
if ($action === 'admin_add' && is_admin()) {
    $name = $_POST['name'] ?? '';
    $price = (int)($_POST['price'] ?? 0);
    $stock = (int)($_POST['stock'] ?? 0);
    $imgPath = '';
    if (!empty($_FILES['image']['tmp_name'])) {
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $fn = uniqid('p_') . '.' . $ext;
        move_uploaded_file($_FILES['image']['tmp_name'], UPLOAD_DIR . $fn);
        $imgPath = 'uploads/produk/' . $fn;
    }
    $stmt = $db->prepare(DB_TYPE === 'mysql'
        ? 'INSERT INTO products (name, price, image, stock, created_at) VALUES (?, ?, ?, ?, NOW())'
        : 'INSERT INTO products (name, price, image, stock, created_at) VALUES (?, ?, ?, ?, datetime(\'now\'))'
    );
    $stmt->execute([$name, $price, $imgPath, $stock]);
    header('Location: ?page=admin&msg=added'); exit;
}

// Admin edit product
if ($action === 'admin_edit' && is_admin()) {
    $id = (int)($_POST['id'] ?? 0);
    $name = $_POST['name'] ?? '';
    $price = (int)($_POST['price'] ?? 0);
    $stock = (int)($_POST['stock'] ?? 0);
    if (!empty($_FILES['image']['tmp_name'])) {
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $fn = uniqid('p_') . '.' . $ext;
        move_uploaded_file($_FILES['image']['tmp_name'], UPLOAD_DIR . $fn);
        $imgPath = 'uploads/produk/' . $fn;
        $db->prepare('UPDATE products SET image = ? WHERE id = ?')->execute([$imgPath, $id]);
    }
    $db->prepare('UPDATE products SET name=?, price=?, stock=? WHERE id=?')->execute([$name, $price, $stock, $id]);
    header('Location: ?page=admin&msg=updated'); exit;
}

// Admin delete product
if (isset($_GET['delete']) && is_admin()) {
    $id = (int)$_GET['delete'];
    $db->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
    header('Location: ?page=admin&msg=deleted'); exit;
}

// User management
if ($action === 'user_add' && is_admin()) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'staff';
    if ($username && $password) {
        $pw = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare(DB_TYPE === 'mysql'
            ? 'INSERT INTO users (username,password,role,created_at) VALUES (?,?,?,NOW())'
            : 'INSERT INTO users (username,password,role,created_at) VALUES (?,?,?,datetime(\'now\'))'
        );
        $stmt->execute([$username,$pw,$role]);
        header('Location: ?page=users&msg=added'); exit;
    } else $error = 'Username & password diperlukan';
}
if ($action === 'user_edit' && is_admin()) {
    $id = (int)($_POST['id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'staff';
    if ($id && $username) {
        if ($password) {
            $pw = password_hash($password, PASSWORD_DEFAULT);
            $db->prepare('UPDATE users SET username=?, password=?, role=? WHERE id=?')->execute([$username,$pw,$role,$id]);
        } else {
            $db->prepare('UPDATE users SET username=?, role=? WHERE id=?')->execute([$username,$role,$id]);
        }
        header('Location: ?page=users&msg=updated'); exit;
    } else $error = 'Data tidak lengkap';
}
if (isset($_GET['user_delete']) && is_admin()) {
    $uid = (int)$_GET['user_delete'];
    $db->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
    header('Location: ?page=users&msg=deleted'); exit;
}

// Checkout - save order and redirect to WA
if ($action === 'checkout') {
    $name = $_POST['cust_name'] ?? '';
    $wa = $_POST['cust_wa'] ?? '';
    $addr = $_POST['cust_addr'] ?? '';
    $method = $_POST['pay_method'] ?? 'COD';
    $cart = $_SESSION['cart'] ?? [];

    if (empty($cart)) { $err='Keranjang kosong'; }
    else {
        $ids = implode(',', array_map('intval', array_keys($cart)));
        $rows = $db->query("SELECT id,name,price FROM products WHERE id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC);

        $total = 0;
        $items = [];
        foreach ($rows as $r) {
            $q = $cart[$r['id']];
            $sub = $r['price'] * $q;
            $total += $sub;
            $items[] = ['product_id'=>$r['id'],'name'=>$r['name'],'price'=>$r['price'],'qty'=>$q,'sub'=>$sub];
        }

        $stmt = $db->prepare(DB_TYPE === 'mysql'
            ? "INSERT INTO orders (cust_name,cust_wa,address,payment,total,created_at) VALUES (?,?,?,?,?,NOW())"
            : "INSERT INTO orders (cust_name,cust_wa,address,payment,total,created_at) VALUES (?,?,?,?,?,datetime('now'))"
        );
        $stmt->execute([$name,$wa,$addr,$method,$total]);
        $order_id = $db->lastInsertId();

        $stmt2 = $db->prepare("INSERT INTO order_items (order_id,product_id,name,price,qty,sub_total) VALUES (?,?,?,?,?,?)");
        foreach ($items as $it) {
            $stmt2->execute([$order_id,$it['product_id'],$it['name'],$it['price'],$it['qty'],$it['sub']]);
        }

        // Build WA message starting with "Hubungi Kami"
        $lines = [];
        $lines[] = "Hubungi Kami";
        $lines[] = "";
        foreach ($items as $it) {
            $lines[] = "{$it['name']} x{$it['qty']} - Rp " . number_format($it['sub'],0,',','.');
        }
        $lines[] = "Total: Rp " . number_format($total,0,',','.');
        $lines[] = "Nama: $name";
        $lines[] = "No WA: $wa";
        $lines[] = "Alamat: $addr";
        $lines[] = "Pembayaran: $method";

        $msg = urlencode(implode("\n", $lines));
        unset($_SESSION['cart']);
        header('Location: https://wa.me/' . SHOP_PHONE . '?text=' . $msg);
        exit;
    }
}

// Load products & users (safe)
try {
    $products = $db->query('SELECT * FROM products ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $products = []; log_db("Failed loading products: ".$e->getMessage()); }
try {
    $users = $db->query('SELECT * FROM users ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $users = []; log_db("Failed loading users: ".$e->getMessage()); }

// ------------------------------------------------------------------
// HTML output below, including a DB test page (?page=dbtest)
// ------------------------------------------------------------------
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>KURNIYAH JAYA</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    :root{--orange:#ff6b00;--blue:#0d6efd;--gray:#6c757d}
    body{background:#f8f9fa}
    .brand{font-weight:700;color:var(--orange)}
    .card-title{min-height:48px}
    .price{font-weight:700;color:var(--blue)}
    .product-img{height:180px;object-fit:cover}
    footer{padding:18px 0}
    body.theme-orange .brand{color:var(--orange)}
    body.theme-blue .brand{color:var(--blue)}
    body.theme-gray .brand{color:var(--gray)}
    .btn-icon{ width:42px;height:38px;padding:0; display:inline-flex;align-items:center;justify-content:center;}
    .wa-icon{ width:42px;height:38px;padding:0; display:inline-flex;align-items:center;justify-content:center;border-radius:6px;}
    .product-card-actions{ display:flex; gap:8px; align-items:center }
    @media(max-width:480px){ .product-img{height:140px} }
  </style>
</head>
<body class="theme-orange">
<nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
  <div class="container">
    <a class="navbar-brand brand" href="?">KURNIYAH <span class="text-dark">JAYA</span></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="navMenu">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item"><a class="nav-link" href="?">Produk</a></li>
        <li class="nav-item"><a class="nav-link" href="?page=cart">Keranjang (<?php echo array_sum($_SESSION['cart'] ?? []); ?>)</a></li>
        <?php if(is_admin()): ?>
          <li class="nav-item"><a class="nav-link" href="?page=admin">Admin</a></li>
          <li class="nav-item"><a class="nav-link" href="?page=laporan">Laporan Penjualan</a></li>
          <li class="nav-item"><a class="nav-link" href="?page=users">Manajemen User</a></li>
          <li class="nav-item"><a class="nav-link" href="?logout=1">Logout</a></li>
        <?php else: ?>
          <li class="nav-item"><a class="nav-link" href="?page=login">Login Admin</a></li>
        <?php endif; ?>
        <li class="nav-item"><a class="nav-link" href="?page=dbtest">DB Test</a></li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Tema</a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item theme-switch" href="#" data-theme="orange">Oranye</a></li>
            <li><a class="dropdown-item theme-switch" href="#" data-theme="blue">Biru</a></li>
            <li><a class="dropdown-item theme-switch" href="#" data-theme="gray">Abu-abu</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>

<div class="container my-4">
<?php if(isset($error)): ?><div class="alert alert-danger"><?php echo h($error); ?></div><?php endif; ?>

<!-- DB TEST PAGE -->
<?php if(route()==='dbtest'): ?>
  <h3>Database Test</h3>
  <p><strong>Connection type:</strong> <?php echo h($connected_via); ?></p>
  <p><strong>Log file:</strong> <?php echo h(LOG_FILE); ?></p>

  <?php
    // Test queries and list table counts
    $tables = ['products','orders','order_items','users'];
    echo '<table class="table"><thead><tr><th>Tabel</th><th>Ada?</th><th>Count</th></tr></thead><tbody>';
    foreach ($tables as $t) {
        try {
            $res = $db->query("SELECT COUNT(*) as c FROM $t")->fetch(PDO::FETCH_ASSOC);
            $cnt = $res ? intval($res['c']) : 0;
            echo '<tr><td>'.h($t).'</td><td style="color:green">YA</td><td>'.$cnt.'</td></tr>';
        } catch (Exception $e) {
            echo '<tr><td>'.h($t).'</td><td style="color:red">TIDAK</td><td>-</td></tr>';
        }
    }
    echo '</tbody></table>';
    echo '<p>Isi log (terakhir 2000 karakter):</p><pre style="max-height:300px;overflow:auto;background:#f8f9fa;border:1px solid #ddd;padding:8px;">';
    if (file_exists(LOG_FILE)) {
        $log = file_get_contents(LOG_FILE);
        echo htmlspecialchars(mb_substr($log, -2000));
    } else {
        echo "Log file kosong atau belum dibuat.";
    }
    echo '</pre>';
  ?>
  <p><a class="btn btn-outline-primary" href="?">Kembali</a></p>
<?php endif; ?>

<!-- HOME / PRODUCTS -->
<?php if(route()==='home'): ?>
  <div class="row g-3">
    <?php foreach($products as $p): ?>
      <div class="col-6 col-md-4 col-lg-3">
        <div class="card h-100">
          <img src="<?php echo h($p['image']); ?>" class="card-img-top product-img" alt="<?php echo h($p['name']); ?>">
          <div class="card-body d-flex flex-column">
            <h5 class="card-title"><?php echo h($p['name']); ?></h5>
            <div class="mb-2 price">Rp <?php echo number_format($p['price'],0,',','.'); ?></div>
            <form method="post" class="mt-auto">
              <input type="hidden" name="action" value="add_cart">
              <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
              <div class="product-card-actions">
                <input type="number" name="qty" value="1" min="1" class="form-control form-control-sm" style="width:80px" aria-label="Jumlah">
                <button class="btn btn-primary btn-icon" title="Tambah ke Keranjang" aria-label="Tambah ke Keranjang"><i class="fas fa-shopping-cart"></i></button>
                <a class="btn wa-icon ms-auto" href="https://wa.me/<?php echo SHOP_PHONE; ?>?text=<?php echo urlencode('Hubungi Kami%0A%0AHalo, saya mau pesan: ' . $p['name']); ?>" target="_blank" style="background:#25D366;color:white" title="Order via WhatsApp" aria-label="Order via WhatsApp"><i class="fab fa-whatsapp"></i></a>
              </div>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- CART -->
<?php if(route()==='cart'): ?>
  <h3>Keranjang</h3>
  <form method="post">
    <input type="hidden" name="action" value="update_cart">
    <table class="table">
      <thead><tr><th>Produk</th><th>Harga</th><th>Qty</th><th>Subtotal</th></tr></thead>
      <tbody>
      <?php
        $cart = $_SESSION['cart'] ?? [];
        if (empty($cart)) echo '<tr><td colspan="4">Keranjang kosong</td></tr>';
        else {
          $ids = implode(',', array_map('intval', array_keys($cart)));
          $rows = $db->query("SELECT id,name,price FROM products WHERE id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC);
          $total=0;
          foreach($rows as $r){
            $q = $cart[$r['id']]; $sub = $r['price']*$q; $total += $sub;
            echo '<tr><td>'.h($r['name']).'</td><td>Rp '.number_format($r['price'],0,',','.').'</td>';
            echo '<td><input type="number" name="qty['.$r['id'].']" value="'.h($q).'" min="0" class="form-control form-control-sm" style="width:100px"></td>';
            echo '<td>Rp '.number_format($sub,0,',','.').'</td></tr>';
          }
          echo '<tr><td colspan="3" class="text-end"><strong>Total</strong></td><td><strong>Rp '.number_format($total,0,',','.').'</strong></td></tr>';
        }
      ?>
      </tbody>
    </table>
    <div class="d-flex gap-2">
      <button class="btn btn-secondary">Update Keranjang</button>
      <a class="btn btn-success" href="?page=checkout">Checkout</a>
      <a class="btn btn-outline-dark ms-auto" href="?">Lanjut Belanja</a>
    </div>
  </form>
<?php endif; ?>

<!-- CHECKOUT -->
<?php if(route()==='checkout'): ?>
  <h3>Checkout</h3>
  <?php if(empty($_SESSION['cart'])): ?>
    <div class="alert alert-warning">Keranjang kosong.</div>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="action" value="checkout">
      <div class="mb-3"><label>Nama</label><input name="cust_name" class="form-control" required></div>
      <div class="mb-3"><label>No WhatsApp</label><input name="cust_wa" class="form-control" required></div>
      <div class="mb-3"><label>Alamat</label><textarea name="cust_addr" class="form-control" required></textarea></div>
      <div class="mb-3"><label>Metode Pembayaran</label><select name="pay_method" class="form-select"><option>COD</option><option>Transfer</option></select></div>
      <button class="btn btn-primary">Kirim Pesanan via WhatsApp</button>
    </form>
  <?php endif; ?>
<?php endif; ?>

<!-- LOGIN -->
<?php if(route()==='login'): ?>
  <div class="col-md-4 mx-auto">
    <h3>Login Admin</h3>
    <?php if(isset($error)): ?><div class="alert alert-danger"><?php echo h($error); ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="action" value="login">
      <div class="mb-2"><input name="username" class="form-control" placeholder="username"></div>
      <div class="mb-2"><input name="password" type="password" class="form-control" placeholder="password"></div>
      <button class="btn btn-primary">Login</button>
    </form>
  </div>
<?php endif; ?>

<!-- ADMIN DASHBOARD (PRODUCTS) -->
<?php if(route()==='admin' && is_admin()): ?>
  <h3>Admin Dashboard</h3>
  <div class="mb-3">
    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addModal">Tambah Produk</button>
    <a class="btn btn-outline-secondary" href="?">Kelola Toko</a>
  </div>
  <table class="table">
    <thead><tr><th>ID</th><th>Nama</th><th>Harga</th><th>Stock</th><th>Gambar</th><th>Aksi</th></tr></thead>
    <tbody>
      <?php foreach($products as $p): ?>
        <tr>
          <td><?php echo $p['id']; ?></td>
          <td><?php echo h($p['name']); ?></td>
          <td>Rp <?php echo number_format($p['price'],0,',','.'); ?></td>
          <td><?php echo $p['stock']; ?></td>
          <td style="width:120px"><img src="<?php echo h($p['image']); ?>" style="width:100px;height:60px;object-fit:cover"></td>
          <td>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $p['id']; ?>">Edit</button>
            <a class="btn btn-sm btn-danger" href="?delete=<?php echo $p['id']; ?>" onclick="return confirm('Hapus?')">Hapus</a>
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#imgModal<?php echo $p['id']; ?>">G→Foto</button>
          </td>
        </tr>

        <!-- Edit Modal -->
        <div class="modal fade" id="editModal<?php echo $p['id']; ?>" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content">
              <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="admin_edit">
                <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                <div class="modal-header"><h5>Edit Produk</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                  <div class="mb-2"><label>Nama</label><input name="name" class="form-control" value="<?php echo h($p['name']); ?>"></div>
                  <div class="mb-2"><label>Harga</label><input name="price" class="form-control" value="<?php echo h($p['price']); ?>"></div>
                  <div class="mb-2"><label>Stock</label><input name="stock" class="form-control" value="<?php echo h($p['stock']); ?>"></div>
                  <div class="mb-2"><label>Ganti Gambar (opsional)</label><input type="file" name="image" class="form-control"></div>
                </div>
                <div class="modal-footer"><button class="btn btn-primary">Simpan</button></div>
              </form>
            </div>
          </div>
        </div>

        <!-- Image Modal -->
        <div class="modal fade" id="imgModal<?php echo $p['id']; ?>" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content">
              <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="admin_edit">
                <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                <div class="modal-header"><h5>Ganti Foto Produk</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body"><input type="file" name="image" class="form-control" required></div>
                <div class="modal-footer"><button class="btn btn-primary">Upload</button></div>
              </form>
            </div>
          </div>
        </div>

      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Add Modal -->
  <div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="admin_add">
          <div class="modal-header"><h5>Tambah Produk</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <div class="mb-2"><label>Nama</label><input name="name" class="form-control" required></div>
            <div class="mb-2"><label>Harga</label><input name="price" class="form-control" required></div>
            <div class="mb-2"><label>Stock</label><input name="stock" class="form-control" required></div>
            <div class="mb-2"><label>Gambar</label><input type="file" name="image" class="form-control"></div>
          </div>
          <div class="modal-footer"><button class="btn btn-success">Tambah</button></div>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- LAPORAN -->
<?php if(route()==='laporan' && is_admin()): ?>
  <h3>Laporan Penjualan</h3>
  <div class="mb-3 d-flex gap-2 align-items-center">
    <a class="btn btn-outline-secondary" href="?page=laporan">Refresh</a>
    <a class="btn btn-success" href="?page=laporan&export=csv">Export CSV</a>
    <a class="btn btn-outline-dark ms-auto" href="?">Kembali</a>
  </div>

  <?php
    if (isset($_GET['export']) && $_GET['export']==='csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=laporan_penjualan_' . date('Ymd_His') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Order ID','Tanggal','Nama','No WA','Alamat','Pembayaran','Total']);
        $ords = $db->query("SELECT * FROM orders ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ords as $o) {
            fputcsv($out, [$o['id'],$o['created_at'],$o['cust_name'],$o['cust_wa'],$o['address'],$o['payment'],$o['total']]);
        }
        fclose($out);
        exit;
    }
    $orders = $db->query("SELECT * FROM orders ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
  ?>

  <table class="table">
    <thead><tr><th>ID</th><th>Tanggal</th><th>Nama</th><th>No WA</th><th>Total</th><th>Aksi</th></tr></thead>
    <tbody>
      <?php foreach($orders as $o): ?>
        <tr>
          <td><?php echo $o['id']; ?></td>
          <td><?php echo $o['created_at']; ?></td>
          <td><?php echo h($o['cust_name']); ?></td>
          <td><?php echo h($o['cust_wa']); ?></td>
          <td>Rp <?php echo number_format($o['total'],0,',','.'); ?></td>
          <td><a class="btn btn-sm btn-primary" href="?page=laporan&view=<?php echo $o['id']; ?>">Detail</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if(isset($_GET['view'])):
      $oid = (int)$_GET['view'];
      $ord = $db->prepare("SELECT * FROM orders WHERE id=?");
      $ord->execute([$oid]);
      $ord = $ord->fetch(PDO::FETCH_ASSOC);
      $items = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
      $items->execute([$oid]);
      $items = $items->fetchAll(PDO::FETCH_ASSOC);
  ?>
    <h5>Detail Pesanan #<?php echo $oid; ?></h5>
    <p><strong>Nama:</strong> <?php echo h($ord['cust_name']); ?> — <strong>No WA:</strong> <?php echo h($ord['cust_wa']); ?></p>
    <p><strong>Alamat:</strong> <?php echo h($ord['address']); ?></p>
    <table class="table">
      <thead><tr><th>Produk</th><th>Harga</th><th>Qty</th><th>Subtotal</th></tr></thead>
      <tbody>
        <?php foreach($items as $it): ?>
          <tr>
            <td><?php echo h($it['name']); ?></td>
            <td>Rp <?php echo number_format($it['price'],0,',','.'); ?></td>
            <td><?php echo $it['qty']; ?></td>
            <td>Rp <?php echo number_format($it['sub_total'],0,',','.'); ?></td>
          </tr>
        <?php endforeach; ?>
        <tr><td colspan="3" class="text-end"><strong>Total</strong></td><td><strong>Rp <?php echo number_format($ord['total'],0,',','.'); ?></strong></td></tr>
      </tbody>
    </table>
  <?php endif; ?>
<?php endif; ?>

<!-- USERS -->
<?php if(route()==='users' && is_admin()): ?>
  <h3>Manajemen User</h3>
  <?php if (isset($_GET['msg'])) echo '<div class="alert alert-success">'.h($_GET['msg']).'</div>'; ?>
  <?php $all_users = $db->query("SELECT id,username,role,created_at FROM users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC); ?>
  <div class="mb-3"><button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addUserModal">Tambah User</button><a class="btn btn-outline-dark ms-auto" href="?">Kembali</a></div>
  <table class="table">
    <thead><tr><th>ID</th><th>Username</th><th>Role</th><th>Created</th><th>Aksi</th></tr></thead>
    <tbody>
      <?php foreach($all_users as $u): ?>
        <tr>
          <td><?php echo $u['id']; ?></td>
          <td><?php echo h($u['username']); ?></td>
          <td><?php echo h($u['role']); ?></td>
          <td><?php echo $u['created_at']; ?></td>
          <td>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo $u['id']; ?>">Edit</button>
            <a class="btn btn-sm btn-danger" href="?user_delete=<?php echo $u['id']; ?>" onclick="return confirm('Hapus user?')">Hapus</a>
          </td>
        </tr>

        <!-- Edit user modal -->
        <div class="modal fade" id="editUserModal<?php echo $u['id']; ?>" tabindex="-1">
          <div class="modal-dialog"><div class="modal-content">
            <form method="post">
              <input type="hidden" name="action" value="user_edit">
              <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
              <div class="modal-header"><h5>Edit User</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
              <div class="modal-body">
                <div class="mb-2"><label>Username</label><input name="username" class="form-control" value="<?php echo h($u['username']); ?>" required></div>
                <div class="mb-2"><label>Password (kosong = tidak diubah)</label><input name="password" type="password" class="form-control"></div>
                <div class="mb-2"><label>Role</label><select name="role" class="form-select"><option value="admin" <?php if($u['role']=='admin') echo 'selected'; ?>>admin</option><option value="staff" <?php if($u['role']=='staff') echo 'selected'; ?>>staff</option></select></div>
              </div>
              <div class="modal-footer"><button class="btn btn-primary">Simpan</button></div>
            </form>
          </div></div>
        </div>

      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Add User Modal -->
  <div class="modal fade" id="addUserModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="post"><input type="hidden" name="action" value="user_add">
    <div class="modal-header"><h5>Tambah User</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-2"><label>Username</label><input name="username" class="form-control" required></div>
      <div class="mb-2"><label>Password</label><input name="password" type="password" class="form-control" required></div>
      <div class="mb-2"><label>Role</label><select name="role" class="form-select"><option value="staff">staff</option><option value="admin">admin</option></select></div>
    </div>
    <div class="modal-footer"><button class="btn btn-success">Tambah</button></div>
    </form>
  </div></div></div>
<?php endif; ?>

<footer class="bg-white border-top mt-4"><div class="container d-flex justify-content-between align-items-center"><div>Copyright © <?php echo date('Y'); ?> KURNIYAH JAYA</div><div>GLORY JAYA ❤️</div></div></footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const savedTheme = localStorage.getItem("theme");
  if (savedTheme) {
    document.body.classList.remove('theme-orange','theme-blue','theme-gray');
    document.body.classList.add('theme-' + savedTheme);
  }
  document.querySelectorAll('.theme-switch').forEach(el=>{
    el.addEventListener('click', function(e){
      e.preventDefault();
      const theme = this.getAttribute('data-theme');
      document.body.classList.remove('theme-orange','theme-blue','theme-gray');
      document.body.classList.add('theme-' + theme);
      localStorage.setItem('theme', theme);
    });
  });
</script>
</body>
</html>

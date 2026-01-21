<?php
session_start();
include __DIR__ . '/config/db.php';

$error = '';

if(isset($_POST['login'])){
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];

    $query = mysqli_query($conn, "SELECT * FROM users WHERE username='$username' LIMIT 1");
    if(!$query) die("Query Error: ".mysqli_error($conn));

    if(mysqli_num_rows($query) > 0){
        $user = mysqli_fetch_assoc($query);
        $password_match = false;

        if(password_verify($password, $user['password']) || $user['password'] === $password){
            $password_match = true;
        }

if ($password_match) {

    session_regenerate_id(true);

    $_SESSION['id']   = $user['id'];
    $_SESSION['nama'] = $user['nama'];
    $_SESSION['role'] = $user['role'];

    /* ===============================
       KHUSUS ROLE GURU
    ================================ */
    if (strtolower($user['role']) === 'guru') {

        $username = mysqli_real_escape_string($conn, $user['username']);

        $qGuru = mysqli_query(
            $conn,
            "SELECT id FROM guru WHERE username = '$username' LIMIT 1"
        );

        if (!$qGuru || mysqli_num_rows($qGuru) === 0) {
            session_destroy();
            die("ERROR: Akun guru tidak ditemukan di tabel guru.");
        }

        $guru = mysqli_fetch_assoc($qGuru);
        $_SESSION['guru_id'] = (int)$guru['id'];
    }

    /* ===============================
       REDIRECT
    ================================ */
    switch (strtolower($user['role'])) {
        case 'admin':
            header("Location: admin/dashboard.php");
            exit;
        case 'guru':
            header("Location: guru/dashboard.php");
            exit;
        case 'siswa':
            header("Location: siswa/dashboard.php");
            exit;
        default:
            $error = "Role tidak dikenali!";
    }
}

        } else {
            $error = "Password salah!";
        }
    } else {
        $error = "Username tidak ditemukan!";
    }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login Exam System </title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');

/* --- BODY GRADIENT & DARK MODE --- */
body{
    margin:0; font-family:'Poppins',sans-serif; height:100vh; overflow:hidden;
    transition: all 0.5s;
}
body.light{
    background: linear-gradient(135deg,#6f42c1,#007bff);
    background-size: 400% 400%;
    animation: bgShift 20s ease infinite;
}
@keyframes bgShift{
    0%{background-position:0% 50%;}
    50%{background-position:100% 50%;}
    100%{background-position:0% 50%;}
}
body.dark{background:#0a0a0a;}

/* --- CARD LOGIN --- */
.card-login{
    position:relative; z-index:2; padding:45px 35px; max-width:400px; width:90%;
    margin:auto; top:50%; transform:translateY(-50%); border-radius:18px;
    text-align:center; backdrop-filter: blur(12px); transition: all 0.5s;
}
body.light .card-login{
    background: rgba(255,255,255,0.95); box-shadow:0 8px 25px rgba(0,0,0,0.1); color:#3a3a3a;
}
body.dark .card-login{
    background: rgba(0,0,0,0.75); color:#00f7ff; box-shadow:0 15px 40px rgba(0,255,255,0.6);
}

/* --- TITLE --- */
.card-login h3{
    margin-bottom:22px; font-weight:700; font-size:1.7rem; transition: color 0.5s;
}
body.light .card-login h3{ color:#6f42c1; }
body.dark .card-login h3{
    color:#00f7ff; animation: bounceText 2s infinite;
    text-shadow:0 0 5px #00f7ff,0 0 15px #00f7ff;
}
@keyframes bounceText{
    0%,100%{transform:translateY(0);}
    50%{transform:translateY(-8px);}
}

/* --- INPUTS --- */
.form-control{
    border-radius:12px; padding:12px 15px; border:none; font-weight:500; transition:0.4s;
}
body.light .form-control{
    background: #f0f4f8; color:#4a4a4a; box-shadow:0 0 6px rgba(0,0,0,0.05) inset;
}
body.dark .form-control{
    background: rgba(0,0,0,0.3); color:#00f7ff; box-shadow:0 0 5px #00f7ff,0 0 10px #00f7ff inset;
}
.form-control::placeholder{transition:0.5s;}
body.light .form-control::placeholder{color: rgba(74,74,74,0.5);}
body.dark .form-control::placeholder{color: rgba(0,247,255,0.6);}

/* --- SHOW PASSWORD --- */
.show-pass{position:absolute; right:15px; top:50%; transform:translateY(-50%); cursor:pointer; transition:0.3s;}
body.light .show-pass{color:#6c757d;}
body.dark .show-pass{color:#00f7ff;}

/* --- BUTTON --- */
.btn-neon{
    width:100%; padding:12px; border-radius:14px; font-weight:600; margin-top:18px;
    text-transform:uppercase; letter-spacing:1px; transition:0.4s, box-shadow 0.4s;
}
body.light .btn-neon{
    background: linear-gradient(90deg,#8fbcd4,#a3c5e2); color:#fff; border:none;
    box-shadow:0 4px 10px rgba(0,0,0,0.08);
}
body.light .btn-neon:hover{
    transform:scale(1.03); box-shadow:0 6px 16px rgba(0,0,0,0.12);
}
body.dark .btn-neon{
    background:none; border:2px solid #00f7ff; color:#00f7ff;
    box-shadow:0 0 10px #00f7ff,0 0 20px #00f7ff,0 0 30px #00f7ff;
}
body.dark .btn-neon:hover{
    color:#000; background:#00f7ff;
    box-shadow:0 0 20px #00f7ff,0 0 40px #00f7ff,0 0 60px #00f7ff;
    transform:translateY(-2px);
}

/* --- LINKS --- */
.text-danger{font-size:0.9rem;margin-bottom:10px;}
a.btn-link{transition:0.3s;}
body.light a.btn-link{color:#6c757d;}
body.light a.btn-link:hover{color:#007bff;}
body.dark a.btn-link{color:#00f7ff;}

/* --- MODE BUTTON --- */
#modeBtn{position:absolute; top:15px; right:15px; z-index:3;}

/* --- SNAKE CANVAS --- */
#snakeCanvas{position:absolute; inset:0; z-index:0; pointer-events:none;}
</style>
</head>
<body class="light">

<button id="modeBtn" class="btn btn-sm btn-outline-dark">Dark Mode</button>

<div class="card-login">
    <h3><i class="bi bi-box-arrow-in-right"></i> Login Exam System</h3>
    <?php if($error): ?>
        <div class="text-danger"><?= $error ?></div>
    <?php endif; ?>
    <form method="POST" style="position:relative;">
        <div class="mb-3 text-start">
            <label>Username</label>
            <input type="text" name="username" class="form-control" placeholder="Username" required>
        </div>
        <div class="mb-3 text-start position-relative">
            <label>Password</label>
            <input type="password" name="password" id="password" class="form-control" placeholder="Password" required>
            <i class="bi bi-eye show-pass" onclick="togglePassword()"></i>
        </div>
        <button type="submit" name="login" class="btn-neon"><i class="bi bi-box-arrow-in-right"></i> Login</button>
        <div class="mt-3">
            <a href="siswa/register.php" class="btn-link"><i class="bi bi-person-plus"></i> Daftar Siswa Baru</a>
        </div>
    </form>
</div>

<canvas id="snakeCanvas"></canvas>

<script>
// Toggle password
function togglePassword(){
    let pass = document.getElementById('password');
    pass.type = (pass.type === "password") ? "text" : "password";
}

// Toggle Dark/Light Mode
const modeBtn=document.getElementById('modeBtn');
modeBtn.addEventListener('click', ()=>{
    document.body.classList.toggle('dark');
    document.body.classList.toggle('light');
    if(document.body.classList.contains('dark')){
        modeBtn.innerText='Light Mode';
        modeBtn.classList.replace('btn-outline-dark','btn-outline-warning');
    } else {
        modeBtn.innerText='Dark Mode';
        modeBtn.classList.replace('btn-outline-warning','btn-outline-dark');
    }
});

// --- Snake Background Fun ---
const canvas = document.getElementById('snakeCanvas');
const ctx = canvas.getContext('2d');
canvas.width = window.innerWidth;
canvas.height = window.innerHeight;

let snake = [{x: canvas.width/2, y: canvas.height/2}];
let segments = 30;
let mouse = {x: canvas.width/2, y: canvas.height/2};
let foods = [];
const foodCount = 20;

function randomColor(){
    const colors = ['#ff4d4d','#ffb84d','#4dff4d','#4dffff','#b84dff','#ff4db8'];
    return colors[Math.floor(Math.random()*colors.length)];
}
function spawnFood(){
    foods = [];
    for(let i=0;i<foodCount;i++){
        foods.push({x: Math.random()*canvas.width, y: Math.random()*canvas.height, color: randomColor()});
    }
}
spawnFood();

window.addEventListener('mousemove', e=>{
    if(document.body.classList.contains('dark')){
        mouse.x = e.clientX;
        mouse.y = e.clientY;
    }
});

function animateSnake(){
    ctx.clearRect(0,0,canvas.width,canvas.height);
    if(document.body.classList.contains('dark')){
        for(let f of foods){
            ctx.beginPath();
            ctx.arc(f.x, f.y, 8, 0, Math.PI*2);
            ctx.fillStyle = f.color;
            ctx.shadowBlur = 10;
            ctx.shadowColor = f.color;
            ctx.fill();
        }
        ctx.shadowBlur = 0;

        let head = snake[0];
        head.x += (mouse.x - head.x)*0.07;
        head.y += (mouse.y - head.y)*0.07;

        snake.unshift({x: head.x, y: head.y});
        if(snake.length > segments) snake.pop();

        for(let i = foods.length-1; i>=0; i--){
            let f = foods[i];
            let dx = head.x - f.x;
            let dy = head.y - f.y;
            if(Math.sqrt(dx*dx+dy*dy)<12){
                segments += 4;
                foods[i] = {x: Math.random()*canvas.width, y: Math.random()*canvas.height, color: randomColor()};
            }
        }

        for(let i=0;i<snake.length;i++){
            ctx.beginPath();
            ctx.arc(snake[i].x, snake[i].y, 8*(i/segments),0,Math.PI*2);
            ctx.fillStyle=`rgba(0,247,255,${1 - i/segments})`;
            ctx.shadowBlur=15*(1-i/segments);
            ctx.shadowColor="cyan";
            ctx.fill();
        }
        ctx.shadowBlur=0;
    }
    requestAnimationFrame(animateSnake);
}
animateSnake();

window.addEventListener('resize', ()=>{
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

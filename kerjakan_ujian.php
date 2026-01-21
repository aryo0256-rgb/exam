    <?php
    if (session_status() === PHP_SESSION_NONE) session_start();
    error_reporting(E_ALL); ini_set('display_errors',1);
    include __DIR__.'/../config/db.php';
    if(file_exists(__DIR__.'/../vendor/autoload.php')) require_once __DIR__.'/../vendor/autoload.php';
    use PhpOffice\PhpWord\IOFactory;

    // security
    if(!isset($_SESSION['id'])||($_SESSION['role']??'')!=='siswa'){ header('Location: ../login.php'); exit; }

    $siswa_id=(int)$_SESSION['id'];
    $ujian_id=(int)($_GET['ujian_id']??0);
    $no=(int)($_POST['no']??($_GET['no']??1));
    if($ujian_id<=0) die('Ujian tidak ditemukan.');

    // ambil data ujian
    $st=$conn->prepare("SELECT * FROM ujian WHERE id=?");
    if(!$st) die('Query failed: '.$conn->error);
    $st->bind_param('i',$ujian_id); $st->execute(); $res=$st->get_result();
    $ujian=$res->fetch_assoc();
    if(!$ujian) die('Ujian tidak ditemukan.');
    $ujian_nama=$ujian['nama_ujian']??($ujian['nama']??'Ujian');

    // ===============================
    // RESET JIKA TES ULANG
    // ===============================
    if (isset($_GET['ulang']) && $_GET['ulang'] == 1) {
        unset($_SESSION['exam'][$ujian_id]);
    }

    // ===============================
    // INISIALISASI UJIAN (SEKALI PER SISWA)
    // ===============================
    if (!isset($_SESSION['exam'][$ujian_id])) {

        $ids = [];

        $q = $conn->prepare("SELECT id FROM ujian_soal WHERE ujian_id=?");
        $q->bind_param("i", $ujian_id);
        $q->execute();
        $r = $q->get_result();

        while ($row = $r->fetch_assoc()) {
            $ids[] = (int)$row['id'];
        }

        if (empty($ids)) {
            die("<div class='alert alert-warning'>Soal ujian tidak ditemukan.</div>");
        }

        shuffle($ids); // 🔀 ACAK SEKALI

        $_SESSION['exam'][$ujian_id] = [
            'order'      => $ids,
            'answers'    => [],
            'mapping'    => [],
            'start_time' => time()
        ];
    }
    // ===== SIMPAN JAWABAN KE jawaban_tmp =====
    if (!empty($_POST['jawaban']) && is_array($_POST['jawaban'])) {
        foreach ($_POST['jawaban'] as $sid => $jw) {
            $sid = (int)$sid;
            $jw  = strtoupper(trim($jw));
            if ($sid && $jw !== '') {
                $qSave = $conn->prepare("
                    INSERT INTO jawaban_tmp (siswa_id, ujian_id, soal_id, jawaban)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE jawaban = VALUES(jawaban)
                ");
                $qSave->bind_param("iiis", $siswa_id, $ujian_id, $sid, $jw);
                $qSave->execute();

                // update session juga
                $_SESSION['exam'][$ujian_id]['answers'][$sid] = $jw;
            }
        }
    }

    // HANDLE POST
    $action = $_POST['action'] ?? '';

    if ($action === 'next') {
        header("Location: ?ujian_id={$ujian_id}&no=".($no+1));
        exit;
    }

    if ($action === 'prev') {
        header("Location: ?ujian_id={$ujian_id}&no=".($no-1));
        exit;
    }

    // ===============================
    // AJAX AUTOSAVE (SATU FILE)
    // ===============================
    if (isset($_POST['ajax']) && $_POST['ajax'] === 'autosave') {
        header('Content-Type: application/json');

        $soal_id = (int)($_POST['soal_id'] ?? 0);
        $jawaban = strtoupper(trim($_POST['jawaban'] ?? ''));

        if ($soal_id > 0 && $jawaban !== '') {

            $q = $conn->prepare("
                INSERT INTO jawaban_tmp (siswa_id, ujian_id, soal_id, jawaban)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE jawaban = VALUES(jawaban)
            ");
            $q->bind_param("iiis", $siswa_id, $ujian_id, $soal_id, $jawaban);
            $q->execute();

            $_SESSION['exam'][$ujian_id]['answers'][$soal_id] = $jawaban;

            echo json_encode(['status' => 'ok']);
        } else {
            echo json_encode(['status' => 'invalid']);
        }
        exit;
    }

    if ($action === 'submit') {

        // pastikan ambil dari jawaban_tmp, bukan POST
    $cekTmp = $conn->prepare("
        SELECT COUNT(*) FROM jawaban_tmp
        WHERE siswa_id=? AND ujian_id=?
    ");
    $cekTmp->bind_param("ii", $siswa_id, $ujian_id);
    $cekTmp->execute();
    $cekTmp->bind_result($totalTmp);
    $cekTmp->fetch();
    $cekTmp->close();

    if ($totalTmp == 0) {
        die("❌ Tidak ada jawaban tersimpan.");
    }

        $conn->begin_transaction();

        // 1. SIMPAN HASIL
        $ins = $conn->prepare("
            INSERT INTO hasil_ujian (siswa_id, ujian_id, skor, tanggal)
            VALUES (?, ?, 0, NOW())
        ");
        $ins->bind_param("ii", $siswa_id, $ujian_id);
        $ins->execute();
        $hasil_ujian_id = $ins->insert_id;

    // 2. PINDAHKAN JAWABAN DARI jawaban_tmp → jawaban_siswa
    $qTmp = $conn->prepare("
        SELECT soal_id, jawaban
        FROM jawaban_tmp
        WHERE siswa_id = ? AND ujian_id = ?
    ");
    $qTmp->bind_param("ii", $siswa_id, $ujian_id);
    $qTmp->execute();
    $resTmp = $qTmp->get_result();
    if ($resTmp->num_rows === 0) {
        die("❌ jawaban_tmp kosong saat submit. Jawaban tidak tersimpan.");
    }

    $stmt = $conn->prepare("
        INSERT INTO jawaban_siswa (hasil_ujian_id, soal_id, jawaban)
        VALUES (?, ?, ?)
    ");

    if (!$stmt) {
        die("Prepare jawaban_siswa gagal: ".$conn->error);
    }

    while ($row = $resTmp->fetch_assoc()) {
        $stmt->bind_param(
            "iis",
            $hasil_ujian_id,
            $row['soal_id'],
            $row['jawaban']
        );
        if (!$stmt->execute()) {
            die("Insert jawaban_siswa gagal: ".$stmt->error);
        }
    }


        // 3. HITUNG SKOR
        $q2 = $conn->prepare("
            SELECT js.jawaban, us.jawaban_benar
            FROM jawaban_siswa js
            JOIN ujian_soal us ON js.soal_id = us.id
            WHERE js.hasil_ujian_id=?
        ");
        $q2->bind_param("i", $hasil_ujian_id);
        $q2->execute();
        $res = $q2->get_result();

    // TOTAL SOAL UJIAN (BUKAN JAWABAN)
    $qTotal = $conn->prepare("
        SELECT COUNT(*) 
        FROM ujian_soal 
        WHERE ujian_id = ?
    ");
    $qTotal->bind_param("i", $ujian_id);
    $qTotal->execute();
    $qTotal->bind_result($total_soal);
    $qTotal->fetch();
    $qTotal->close();

    // HITUNG BENAR
    $benar = 0;
    while ($r = $res->fetch_assoc()) {
        if ($r['jawaban'] === $r['jawaban_benar']) {
            $benar++;
        }
    }

    // HITUNG NILAI
    $skor = $total_soal > 0 
        ? round(($benar / $total_soal) * 100, 2) 
        : 0;


        $up = $conn->prepare("UPDATE hasil_ujian SET skor=? WHERE id=?");
        $up->bind_param("di", $skor, $hasil_ujian_id);
        $up->execute();

        // 4. BERSIHIN
        $conn->commit();
        unset($_SESSION['exam'][$ujian_id]);

        // HAPUS JAWABAN TMP SETELAH SUBMIT
    $del = $conn->prepare("
        DELETE FROM jawaban_tmp
        WHERE siswa_id = ? AND ujian_id = ?
    ");
    $del->bind_param("ii", $siswa_id, $ujian_id);
    $del->execute();

        header("Location: hasil_ujian.php?id=".$hasil_ujian_id);
        exit;
    }



    // GET rendering (tidak diubah)
    $current_order=&$_SESSION['exam'][$ujian_id]['order'];
    $total_soal = is_array($current_order) ? count($current_order) : 0;
    if($total_soal===0) die("<div class='alert alert-warning'>Soal ujian tidak ditemukan.</div>");
    if($no<1) $no=1; if($no>$total_soal) $no=$total_soal;
    $current_index=$no-1; $current_soal_id=(int)$current_order[$current_index];
    $qs=$conn->prepare("SELECT * FROM ujian_soal WHERE id=?");
    $qs->bind_param('i',$current_soal_id); $qs->execute(); $qres=$qs->get_result();
    $soal=$qres->fetch_assoc(); if(!$soal) die("<div class='alert alert-warning'>Soal tidak ditemukan.</div>");
    function rapikanSoal($text){
        if(!$text) return '';

        // ubah semua newline jadi spasi
        $text = str_replace(["\r\n", "\r", "\n"], " ", $text);

        // hapus spasi berlebih
        $text = preg_replace('/\s+/', ' ', $text);

        return htmlspecialchars(trim($text));
    }

    $soal_rapi = rapikanSoal($soal['soal_text'] ?? '');

    $display_labels=[];
    if($soal['tipe']==='pg'){
        // include opsi_e
        $base_opts=[
            'A'=>$soal['opsi_a'],
            'B'=>$soal['opsi_b'],
            'C'=>$soal['opsi_c'],
            'D'=>$soal['opsi_d'],
            'E'=>$soal['opsi_e']
        ];
        if(!empty($ujian['random_jawaban'])&&!empty($_SESSION['exam'][$ujian_id]['mapping'][$current_soal_id])) $perm=$_SESSION['exam'][$ujian_id]['mapping'][$current_soal_id];
        else $perm=['A','B','C','D','E'];
        $disp_index=0;
        foreach($perm as $orig){
            $text=$base_opts[$orig]??''; if(trim($text)==='') continue;
            $display_labels[]=['display'=>chr(65+$disp_index),'orig'=>$orig,'text'=>$text];
            $disp_index++;
        }
    }
    $prev_answer=$_SESSION['exam'][$ujian_id]['answers'][$current_soal_id]??'';
    $durasi_menit=(int)($ujian['durasi']??0);
    $started_at=$_SESSION['exam'][$ujian_id]['start_time']??time();
    $waktu_selesai=$started_at+($durasi_menit*60);
    ?>

    <!doctype html>
    <html>
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Kerjakan Ujian - <?= htmlspecialchars($ujian_nama) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
    body{
        background:#f1f5f9;
        font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
        color:#0f172a;
    }

    /* HEADER */
    .exam-header{
        background:linear-gradient(135deg,#1e40af,#1d4ed8);
        color:#fff;
        padding:16px 22px;
        border-radius:14px;
        margin-bottom:22px;
        box-shadow:0 12px 30px rgba(30,64,175,.35);
    }

    .exam-header h4{
        margin:0;
        font-weight:600;
        letter-spacing:.3px;
    }

    /* TIMER */
    .timer-box{
        background:#ffffff;
        color:#1e3a8a;
        padding:8px 16px;
        border-radius:12px;
        font-weight:600;
        font-size:.95rem;
        box-shadow:inset 0 0 0 1px #e5e7eb;
    }

    /* CARD */
    .card{
        border:none;
        border-radius:16px;
        box-shadow:0 15px 40px rgba(15,23,42,.08);
        background:#ffffff;
    }

    /* SOAL */
    .soal-title{
        font-weight:600;
        margin-bottom:10px;
        color:#1e293b;
    }

    .soal-text{
        font-size:16px;
        line-height:1.75;
        color:#0f172a;
    }

    /* OPSI */
    .option-item{
        border:1px solid #e5e7eb;
        border-radius:14px;
        padding:14px 16px;
        margin-bottom:12px;
        cursor:pointer;
        transition:.2s ease;
        display:flex;
        gap:12px;
        background:#fff;
    }

    .option-item:hover{
        background:#f8fafc;
    }

    .option-item input{
        margin-top:5px;
    }

    .option-item.checked{
        border-color:#2563eb;
        background:#eff6ff;
        box-shadow:0 0 0 1px #2563eb inset;
    }

    /* NAVIGATOR */
    .nav-num{
        display:grid;
        grid-template-columns:repeat(auto-fill, minmax(42px,1fr));
        gap:8px;
    }

    .nav-num a{
        height:42px;
        display:flex;
        align-items:center;
        justify-content:center;
        border-radius:10px;
        font-weight:600;
        background:#e5e7eb;
        color:#0f172a;
        text-decoration:none;
        transition:.15s;
    }

    .nav-num a:hover{
        background:#c7d2fe;
    }

    .nav-num a.active{
        background:#2563eb;
        color:#ffffff;
    }

    .nav-num a.answered{
        background:#22c55e;
        color:#ffffff;
    }

    /* BUTTON */
    .btn{
        border-radius:12px;
        font-weight:500;
    }

    /* WORD CONTENT */
    .word-content{
        background:#ffffff;
        padding:18px;
        border-radius:16px;
        box-shadow:0 10px 30px rgba(0,0,0,.06);
    }

    /* RESPONSIVE */
    @media(max-width:768px){
        .exam-header{
            flex-direction:column;
            gap:10px;
            text-align:center;
        }
    }
    /* ================= PROGRESS BAR ================= */
    .progress-wrap{
        background:#e5e7eb;
        height:8px;
        border-radius:999px;
        overflow:hidden;
        margin-top:12px;
    }

    .progress-bar{
        height:100%;
        width:0%;
        background:linear-gradient(90deg,#2563eb,#22c55e);
        transition:width .4s ease;
    }

    /* ================= TIMER WARNING ================= */
    .timer-danger{
        color:#dc2626 !important;
        font-weight:700;
        animation:blink 1s infinite;
    }

    @keyframes blink{
        0%,100%{opacity:1}
        50%{opacity:.4}
    }

    /* ================= OPTION ANIMATION ================= */
    .option-item{
        transition:.25s ease;
    }

    .option-item.checked{
        transform:scale(1.015);
    }

    /* ================= NAV ENHANCE ================= */
    .nav-num a{
        position:relative;
    }

    .nav-num a.answered::after{
        content:"";
        position:absolute;
        bottom:6px;
        width:6px;
        height:6px;
        background:#16a34a;
        border-radius:50%;
    }

    /* ================= DARK MODE ================= */
    body.dark{
        background:#020617;
        color:#e5e7eb;
    }

    body.dark .card,
    body.dark .word-content{
        background:#020617;
        box-shadow:none;
        border:1px solid #1e293b;
    }

    body.dark .option-item{
        background:#020617;
        border-color:#1e293b;
    }

    body.dark .nav-num a{
        background:#1e293b;
        color:#e5e7eb;
    }

    /* ================= ANTI BLOK SOAL ================= */
    .no-select {
        user-select: none;
        -webkit-user-select: none;
        -moz-user-select: none;
        -ms-user-select: none;
    }

    </style>
    </head>
    <body>
    <div class="container mt-4">
      <div class="exam-header d-flex justify-content-between align-items-center">
        <h4>📝 <?= htmlspecialchars($ujian_nama) ?></h4>
        <div class="timer-box">
            ⏱ <span id="timer">...</span>
            <div class="progress-wrap">
        <div class="progress-bar" id="progressBar"></div>
    </div>
        </div>
    </div>
      <div class="row">
        <div class="col-md-8">
          <div class="card p-3 mb-3">
            <div class="soal-title">
        Soal <?= ($current_index+1) ?> dari <?= $total_soal ?>
    </div>

    <div class="soal-text mb-3 no-select">
        <?= $soal_rapi ?>
    </div>

    <?php
    // ===== WORD VIEWER (AMAN & TIDAK BERISIK) =====
    if (
        !empty($soal['file_word']) &&
        class_exists('\\PhpOffice\\PhpWord\\IOFactory') &&
        file_exists(__DIR__ . '/../uploads/soal/' . $soal['file_word'])
    ):
    ?>
    <div class="word-content mb-3">
    <?php
    try {
        $phpWord = \PhpOffice\PhpWord\IOFactory::load(
            __DIR__ . '/../uploads/soal/' . $soal['file_word']
        );
        $htmlWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'HTML');
        ob_start();
        $htmlWriter->save('php://output');
        echo ob_get_clean();
    } catch (Exception $e) {
        // DIAMKAN
    }
    ?>
    </div>
    <?php endif; ?>


    <?php if ($soal['tipe'] === 'pg'): ?>

    <form method="post" id="answerForm">
    <input type="hidden" name="ujian_id" value="<?= $ujian_id ?>">
    <input type="hidden" name="no" value="<?= $no ?>">
    <input type="hidden" name="action" id="actionField" value="save">


    <?php foreach ($display_labels as $d): 
        $checked = ($prev_answer === $d['orig']);
    ?>
    <label class="option-item <?= $checked ? 'checked' : '' ?>">
        <input class="form-check-input"
            type="radio"
            name="jawaban[<?= $current_soal_id ?>]"
            value="<?= $d['orig'] ?>"
            <?= $checked ? 'checked' : '' ?>>
        <div>
            <strong><?= $d['display'] ?>.</strong>
            <?= htmlspecialchars($d['text']) ?>
        </div>
    </label>
    <?php endforeach; ?>

    </form>

    <?php elseif ($soal['tipe'] === 'bs'): ?>

    <form method="post" id="answerForm">
    <input type="hidden" name="ujian_id" value="<?= $ujian_id ?>">
    <input type="hidden" name="no" value="<?= $no ?>">
    <input type="hidden" name="action" id="actionField" value="save">

    <div class="form-check mb-2">
        <input class="form-check-input" type="radio"
               name="jawaban[<?= $current_soal_id ?>]"
               id="opt<?= $current_soal_id ?>T"
               value="T" <?= ($prev_answer==='T')?'checked':'' ?>>
        <label class="form-check-label" for="opt<?= $current_soal_id ?>T">Benar</label>
    </div>

    <div class="form-check mb-2">
        <input class="form-check-input" type="radio"
               name="jawaban[<?= $current_soal_id ?>]"
               id="opt<?= $current_soal_id ?>F"
               value="F" <?= ($prev_answer==='F')?'checked':'' ?>>
        <label class="form-check-label" for="opt<?= $current_soal_id ?>F">Salah</label>
    </div>
    <?php endif; ?>

    </form>
          </div>
        </div>

    <div class="col-md-4">
    <div class="card p-3 mb-3">
    <h6>Ringkasan</h6>
    <p>Soal ke: <strong><?= ($current_index+1) ?>/<?= $total_soal ?></strong></p>
    <p>Durasi: <?= $ujian['durasi'] ?> menit</p>
    <hr>
    <h6>Navigator</h6>
    <div class="nav-num mb-2">
    <?php for($i=0;$i<$total_soal;$i++): $sid=$current_order[$i]; $isAnswered=!empty($_SESSION['exam'][$ujian_id]['answers'][$sid]); $active=($i===$current_index); ?>
    <a href="?ujian_id=<?= $ujian_id ?>&no=<?= ($i+1) ?>"
       class="<?= $active?'active':'' ?> <?= $isAnswered?'answered':'' ?>">
       <?= $i+1 ?>
    </a>
    <?php endfor; ?>
    </div>
    </div>
    </div>
      </div>
    </div>
    <script>
    let autoMoving = false;

    document.addEventListener("change", function (e) {
        if (!e.target.matches("input[type=radio]")) return;
        if (autoMoving) return;

        autoMoving = true;

        const input = e.target;
        const match = input.name.match(/jawaban\[(\d+)\]/);
        if (!match) return;

        const soal_id = match[1];
        const jawaban = input.value;

        fetch("", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body:
                "ajax=autosave" +
                "&ujian_id=<?= $ujian_id ?>" +
                "&soal_id=" + soal_id +
                "&jawaban=" + encodeURIComponent(jawaban)
        })
        .finally(() => {
            setTimeout(() => {
                const total = <?= $total_soal ?>;
                const current = <?= $current_index + 1 ?>;

                    if (current < total) {
                        window.location.href =
                            "?ujian_id=<?= $ujian_id ?>&no=" + (current + 1);
                    }
                    else {
                    const f = document.getElementById("answerForm");
                    if (f) {
                        document.getElementById("actionField").value = "submit";
                        f.submit();
                    }
                }
            }, 300);
        });
    });
    </script>

    <script>
    document.querySelectorAll('.option-item input').forEach(radio=>{
        radio.addEventListener('change',()=>{
            document.querySelectorAll('.option-item').forEach(i=>i.classList.remove('checked'));
            radio.closest('.option-item').classList.add('checked');
        });
    });
    </script>
    <script>
    // ================= PROGRESS SOAL =================
    const navItems = document.querySelectorAll('.nav-num a');
    const progressBar = document.getElementById('progressBar');

    function updateProgress(){
        let answered = document.querySelectorAll('.nav-num a.answered').length;
        let total = navItems.length;
        let percent = total ? (answered/total)*100 : 0;
        progressBar.style.width = percent + "%";
    }
    updateProgress();

    // ================= OPTION EFFECT =================
    document.querySelectorAll('.option-item').forEach(opt=>{
        opt.addEventListener('click',()=>{
            document.querySelectorAll('.option-item').forEach(o=>o.classList.remove('checked'));
            opt.classList.add('checked');
        });
    });

    // ================= TIMER WARNING =================
    const timerBox = document.querySelector('.timer-box');
    if(timerBox){
        setInterval(()=>{
            let text = timerBox.innerText.match(/\d+/);
            if(text && parseInt(text[0]) <= 5){
                timerBox.classList.add('timer-danger');
            }
        },1000);
    }

    // ================= DARK MODE TOGGLE =================
    document.addEventListener('keydown',e=>{
        if(e.key === 'd'){
            document.body.classList.toggle('dark');
        }
    });

    // ================= ANTI COPY =================
    document.addEventListener('contextmenu', e => e.preventDefault());

    document.addEventListener('keydown', function(e) {
        if (
            (e.ctrlKey && ['c','x','v','a','s','p'].includes(e.key.toLowerCase())) ||
            e.key === 'F12'
        ) {
            e.preventDefault();
            alert("⚠️ Aksi ini tidak diperbolehkan saat ujian!");
        }
    });

    // ================= DETEKSI BACK / REFRESH =================
    history.pushState(null, null, location.href);

    window.addEventListener('popstate', function () {
        alert("⚠️ Tombol KEMBALI tidak diperbolehkan saat ujian!");
        history.pushState(null, null, location.href);
    });

    // ================= AUTO SUBMIT JIKA KELUAR =================
    window.addEventListener("unload", function () {
        navigator.sendBeacon(
            "../siswa/auto_submit.php",
            new URLSearchParams({
                ujian_id: "<?= $ujian_id ?>",
                hasil_id: "<?= $hasil_ujian_id ?>"
            })
        );
    });

    </script>
    <script>
    const EXAM_END_TIME = <?= $waktu_selesai ?> * 1000; // timestamp ms
    </script>
    <script>
    (function () {
        const timerEl = document.getElementById("timer");
        const progressBar = document.getElementById("progressBar");

        if (!timerEl || typeof EXAM_END_TIME === "undefined") return;

        function pad(n){ return n < 10 ? "0"+n : n; }

        function updateTimer() {
            const now = Date.now();
            let diff = Math.floor((EXAM_END_TIME - now) / 1000);

            if (diff <= 0) {
                timerEl.innerHTML = "00:00";
                autoSubmitExam();
                return;
            }

            const minutes = Math.floor(diff / 60);
            const seconds = diff % 60;

            timerEl.innerHTML = pad(minutes) + ":" + pad(seconds);

            // WARNING 5 menit terakhir
            if (minutes < 5) {
                timerEl.classList.add("timer-danger");
            }

            // progress waktu
            const total = <?= $durasi_menit ?> * 60;
            const used = total - diff;
            const percent = Math.min(100, (used / total) * 100);
            if (progressBar) progressBar.style.width = percent + "%";
        }

        function autoSubmitExam() {
            if (window.__submitted) return;
            window.__submitted = true;

            alert("⏰ Waktu habis! Jawaban akan otomatis dikumpulkan.");

            const form = document.getElementById("answerForm");

            if (form) {
                document.getElementById("actionField").value = "submit";
                form.submit();
            } else {
                // fallback pakai fetch
                fetch("", {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: "action=submit&ujian_id=<?= $ujian_id ?>"
                });
            }
        }

        updateTimer();
        setInterval(updateTimer, 1000);
    })();
    </script>

    </body>
    </html>
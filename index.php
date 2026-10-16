<?php
require_once __DIR__ . '/config.php';
$user = current_user();
if ($user) {
    header('Location: ' . BASE_URL . portal_path_for($user['role']));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GradePredict AI — Colegio de Santa Rita de San Carlos, Inc.</title>
<meta name="description" content="GradePredict AI helps Colegio de Santa Rita de San Carlos, Inc. forecast student academic performance using past records, attendance, and assignment data.">
<link rel="icon" type="image/png" href="assets/img/logo.png">
<link rel="stylesheet" href="<?= asset_url('/assets/css/style.css') ?>">
</head>
<body>

<header class="landing-header">
    <a href="index.php" style="display:flex;align-items:center;gap:10px;text-decoration:none;">
        <img src="assets/img/logo.png" alt="Colegio de Santa Rita de San Carlos, Inc." class="logo-img" style="width:38px;height:38px;">
        <div>
            <div style="color:white;font-family:var(--font-heading);font-weight:700;font-size:13.5px;line-height:1.2;">Colegio de Santa Rita<br>de San Carlos, Inc.</div>
        </div>
    </a>
    <nav class="landing-nav">
        <a href="#" class="active">Home</a>
        <a href="#features">Features</a>
        <a href="#how">How It Works</a>
        <a href="#why">About</a>
        <a href="#contact">Contact</a>
    </nav>
    <div class="landing-actions">
        <a href="login.php" class="btn btn-outline">Login</a>
        <a href="register.php" class="btn btn-primary">Get Started</a>
    </div>
</header>

<section class="hero">
    <div class="hero-copy">
        <div class="hero-pill">⚡ AI-Powered Grading Prediction</div>
        <h1>Predict Student Grades<br>with the Power of <span>AI</span></h1>
        <p>GradePredict AI helps Colegio de Santa Rita de San Carlos, Inc. forecast academic performance using past records, attendance, and assignment data — so teachers, students, and parents can plan, improve, and achieve better results together.</p>
        <div style="display:flex;gap:14px;flex-wrap:wrap;">
            <a href="register.php" class="btn btn-primary">Get Started Free →</a>
            <a href="#how" class="btn btn-outline" style="background:transparent;border:1px solid #334155;color:white;">Learn More</a>
        </div>
    </div>
    <div class="hero-mock" style="padding:0;overflow:hidden;background:transparent;box-shadow:none;">
        <img src="assets/img/dashboard-hero.jpg" alt="GradePredict AI dashboard preview showing a student grade prediction" style="width:100%;border-radius:16px;box-shadow:0 20px 50px rgba(0,0,0,.35);display:block;">
    </div>
</section>

<section class="features" id="features">
    <div class="feature-item">
        <div class="ic-badge">🧠</div>
        <h4>AI-Powered Prediction</h4>
        <p>Uses historical data and academic patterns to predict future grades with high accuracy.</p>
    </div>
    <div class="feature-item">
        <div class="ic-badge">📊</div>
        <h4>Track Performance</h4>
        <p>Monitor student progress and identify at-risk learners early.</p>
    </div>
    <div class="feature-item">
        <div class="ic-badge">👥</div>
        <h4>Easy to Use</h4>
        <p>Simple and intuitive interface for teachers, students, parents, and administrators.</p>
    </div>
    <div class="feature-item">
        <div class="ic-badge">🛡️</div>
        <h4>Secure &amp; Reliable</h4>
        <p>Keep student data safe with advanced security measures.</p>
    </div>
</section>

<section class="howitworks" id="how">
    <div class="eyebrow">HOW IT WORKS</div>
    <h2>Get Insights in 4 Simple Steps</h2>
    <p>From data input to accurate predictions — it's quick and easy.</p>
    <div class="steps">
        <div class="step"><div class="num">1</div><h4>Input Student Data</h4><p>Add grades, attendance, assignments, and other relevant information.</p></div>
        <div class="step-arrow">→</div>
        <div class="step"><div class="num">2</div><h4>Run Prediction</h4><p>Our AI analyzes the data and finds patterns.</p></div>
        <div class="step-arrow">→</div>
        <div class="step"><div class="num">3</div><h4>View Results</h4><p>Get predicted grades, performance insights, and at-risk alerts.</p></div>
        <div class="step-arrow">→</div>
        <div class="step"><div class="num">4</div><h4>Take Action</h4><p>Help students improve with timely support and guidance.</p></div>
    </div>
</section>

<section class="why-section" id="why">
    <div class="why-img" style="padding:0;overflow:hidden;">
        <img src="assets/img/about-hero.jpg" alt="Better Data. Better Decisions. — student using GradePredict AI" style="width:100%;height:100%;object-fit:cover;display:block;">
    </div>
    <div class="why-copy">
        <div class="eyebrow">ABOUT GRADEPREDICT AI</div>
        <h2>Empower Students. Support Educators.</h2>
        <p>Built for Colegio de Santa Rita de San Carlos, Inc., GradePredict AI is designed to make academic planning smarter, faster, and more data-driven. Whether you're a teacher, student, parent, or school administrator, our platform gives you the insights you need — with a transparent formula behind every prediction, not a black box.</p>
        <div class="why-stats">
            <div><b>95%</b><span>Prediction Accuracy</span></div>
            <div><b>Save 70%</b><span>Time on Grading Analysis</span></div>
            <div><b>100%</b><span>Secure Data Handling</span></div>
            <div><b>Better</b><span>Student Outcomes</span></div>
        </div>
    </div>
</section>

<footer class="site-footer" id="contact">
    <div style="display:flex;align-items:center;gap:10px;">
        <img src="assets/img/logo.png" alt="" class="logo-img" style="width:32px;height:32px;">
        <div style="color:white;font-family:var(--font-heading);font-weight:700;font-size:12.5px;line-height:1.2;">Colegio de Santa Rita<br>de San Carlos, Inc.</div>
    </div>
    <nav class="landing-nav">
        <a href="#">Home</a><a href="#features">Features</a><a href="#how">How It Works</a><a href="#why">About</a><a href="#contact">Contact</a>
        <a href="terms.php">Terms</a><a href="privacy.php">Privacy</a>
    </nav>
    <div>© <?= date('Y') ?> Colegio de Santa Rita de San Carlos, Inc. — GradePredict AI. All rights reserved.</div>
</footer>

</body>
</html>

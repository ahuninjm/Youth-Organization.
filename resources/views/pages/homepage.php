<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    header('Location: login.php');
    exit;
}

$member = $_SESSION['user'];
$currentPage = 'home';
?>
<!DOCTYPE html>

<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Youth Organization of Purok New Binangcaan</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body {
    background: #f5f7f6;
    color: #18251f;
}
.hero{
    background: linear-gradient(90deg, rgba(8, 28, 21, 0.88), rgba(18, 53, 42, 0.68) 55%, rgba(18, 53, 42, 0.26)),
                url('uploads/gallery/P2.jpg');
    background-size: cover;
    background-position: center;
    min-height: calc(100vh - 58px);
    color: white;
    display: flex;
    align-items: center;
    padding: 5rem 0 6rem;
}
.hero-panel {
    max-width: 720px;
}
.section-title{
    font-weight: 800;
    margin-bottom: 16px;
}
.activity-card{
    transition: 0.3s;
    border: 1px solid #e3ece7;
}
.activity-card:hover{
    transform: translateY(-5px);
    box-shadow: 0 10px 24px rgba(18, 41, 32, 0.09);
}
footer{
    background:#17322a;
    color:white;
    padding:24px;
    text-align:center;
}
.hero .btn {
    background: #f2b94b;
    border-color: #f2b94b;
    color: #17311f;
}
.hero-kicker {
    color: #f2d58b;
    font-size: 0.78rem;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}
.home-band {
    background: #ffffff;
}
.feature-card {
    min-height: 100%;
}
</style>
<link href="assets/css/app.css" rel="stylesheet">

</head>

<body>

<?php require_once __DIR__ . '/topnav.php'; ?>

<section class="hero">
    <div class="container">
        <div class="hero-panel">
            <div class="hero-kicker mb-3">Purok New Binangcaan</div>
            <h1 class="display-4 fw-bold mb-3">Youth Organization</h1>
            <p class="lead mb-4">
                We inspire young leaders in Purok New Binangcaan to take part in meaningful service, teamwork, and community growth.
            </p>
            <a href="activities.php" class="btn btn-success btn-lg">Explore Activities</a>
        </div>
    </div>
</section>

<section class="container py-5" id="about">
    <div class="text-center mb-4">
        <h2 class="section-title">About Our Organization</h2>
        <p class="text-muted mx-auto" style="max-width: 760px;">
            The Youth Organization of Purok New Binangcaan is committed to shaping responsible, active, and community-oriented young leaders through service, learning, and shared experiences.
        </p>
    </div>
    <div class="row g-4">
        <div class="col-md-6">
            <div class="feature-card h-100">
                <h5 class="fw-bold mb-2">Our mission</h5>
                <p class="mb-0">To create opportunities for young people to lead, serve, and contribute meaningfully to the welfare of their community.</p>
            </div>
        </div>
        <div class="col-md-6">
            <div class="feature-card h-100">
                <h5 class="fw-bold mb-2">What members gain</h5>
                <p class="mb-0">Leadership confidence, stronger teamwork, and a greater sense of belonging through regular involvement and support.</p>
            </div>
        </div>
    </div>
</section>

<section class="home-band py-5" id="activities">
    <div class="container">
        <div class="text-center mb-4">
            <h2 class="section-title">Upcoming Activities</h2>
            <p class="text-muted">Join the next events and make your time with the organization count.</p>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="card activity-card h-100">
                    <div class="card-body">
                        <h5 class="fw-bold">Community Clean-Up Drive</h5>
                        <p class="text-muted mb-2">July 15, 2026</p>
                        <p>Bring your energy and help keep our surroundings safe, clean, and welcoming.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card activity-card h-100">
                    <div class="card-body">
                        <h5 class="fw-bold">Youth Leadership Seminar</h5>
                        <p class="text-muted mb-2">July 25, 2026</p>
                        <p>Learn practical leadership skills and sharpen your teamwork in a supportive setting.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card activity-card h-100">
                    <div class="card-body">
                        <h5 class="fw-bold">Sports Festival</h5>
                        <p class="text-muted mb-2">August 5, 2026</p>
                        <p>Celebrate sportsmanship, unity, and friendly competition across the youth group.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="container py-5" id="agenda">
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4 p-lg-5">
            <h2 class="section-title text-center">Latest Meeting Agenda</h2>
            <div class="row g-4 align-items-start">
                <div class="col-lg-7">
                    <h5 class="fw-bold">Monthly Youth Meeting</h5>
                    <p class="text-muted mb-3">A time to reflect, plan, and keep everyone informed about the next steps.</p>
                    <ul class="mb-0">
                        <li>Review of recent activities</li>
                        <li>Budget planning for upcoming projects</li>
                        <li>Community outreach ideas</li>
                        <li>Open forum for member concerns and suggestions</li>
                    </ul>
                </div>
                <div class="col-lg-5">
                    <div class="bg-light rounded p-4">
                        <h6 class="fw-bold mb-2">Why attend?</h6>
                        <p class="small text-muted mb-0">Your voice helps shape the direction of the organization and strengthens the whole group.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="bg-light py-5" id="announcements">
    <div class="container">
        <div class="text-center mb-4">
            <h2 class="section-title">Announcements</h2>
            <p class="text-muted">The latest news and reminders for members.</p>
        </div>
        <div class="alert alert-success shadow-sm">
            Registration for the Youth Leadership Seminar is now open. Reserve your slot early.
        </div>
        <div class="alert alert-warning shadow-sm">
            Members are encouraged to attend the monthly meeting and bring their ideas for future projects.
        </div>
    </div>
</section>

<footer>
    <p class="mb-0">&copy; 2026 Youth Organization of Purok New Binangcaan</p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

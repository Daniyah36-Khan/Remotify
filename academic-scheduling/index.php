<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SlotSmart | Intelligent Timetable Solutions</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/landing.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
</head>

<body>

    <nav class="navbar navbar-expand-lg fixed-top navbar-dark" id="mainNav">
        <div class="container">
            <a class="navbar-brand fw-bold" href="#">
                <i class="fa-solid fa-calendar-check text-warning"></i> SlotSmart
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item"><a class="nav-link" href="#home">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="#features">Features</a></li>
                    <li class="nav-item"><a class="nav-link" href="#pricing">Pricing</a></li>
                    <li class="nav-item"><a class="nav-link" href="#about">About Us</a></li>
                    <li class="nav-item"><a class="nav-link" href="#contact">Contact</a></li>
                    <li class="nav-item ms-lg-3">
                        <button class="btn btn-warning fw-bold px-4 rounded-pill shine-effect" data-bs-toggle="modal"
                            data-bs-target="#demoModal">
                            Get Demo
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <header id="home" class="hero-section d-flex align-items-center text-center text-white">
        <div class="overlay"></div>
        <div class="container position-relative z-2">
            <h1 class="display-3 fw-bold mb-4 animate-up">Scheduling Solved. Finally.</h1>
            <p class="lead mb-5 animate-up delay-1">
                The AI-powered timetabling solution for modern universities. <br>
                Conflict-free. Optimized. Effortless.
            </p>
            <div class="animate-up delay-2">
                <button class="btn btn-primary btn-lg px-5 py-3 rounded-pill me-3 shadow-lg" data-bs-toggle="modal"
                    data-bs-target="#demoModal">
                    Start Free Trial
                </button>
                <a href="#features" class="btn btn-outline-light btn-lg px-5 py-3 rounded-pill">Learn More</a>
            </div>
        </div>
    </header>

    <section id="features" class="py-5 bg-light">
        <div class="container py-5">
            <div class="text-center mb-5">
                <h6 class="text-primary text-uppercase fw-bold ls-2">Why Choose Us</h6>
                <h2 class="fw-bold">Intelligent Features for Smart Campus</h2>
            </div>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="feature-card p-4 bg-white rounded-4 shadow-sm h-100 text-center">
                        <div class="icon-box mb-3 text-primary"><i class="fa-solid fa-microchip fa-3x"></i></div>
                        <h4>AI Genetic Algorithm</h4>
                        <p class="text-muted">Our core engine uses advanced evolutionary algorithms to generate
                            conflict-free schedules in seconds.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card p-4 bg-white rounded-4 shadow-sm h-100 text-center">
                        <div class="icon-box mb-3 text-success"><i class="fa-solid fa-sliders fa-3x"></i></div>
                        <h4>Load Balancing</h4>
                        <p class="text-muted">No more burdened Mondays. We distribute classes evenly across the week for
                            happier faculty and students.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="feature-card p-4 bg-white rounded-4 shadow-sm h-100 text-center">
                        <div class="icon-box mb-3 text-warning"><i class="fa-solid fa-print fa-3x"></i></div>
                        <h4>Print Ready Exports</h4>
                        <p class="text-muted">Generate professional PDF views for notice boards with a single click.
                            Formatting handled automatically.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="pricing" class="py-5">
        <div class="container py-5">
            <div class="text-center mb-5">
                <h6 class="text-primary text-uppercase fw-bold ls-2">Our Rates</h6>
                <h2 class="fw-bold">Flexible Plans for Every Institution</h2>
            </div>
            <div class="row align-items-center">
                <div class="col-lg-4 mb-4">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-body p-5 text-center">
                            <h5 class="fw-bold text-muted">Starter</h5>
                            <h2 class="display-4 fw-bold">$0<small class="fs-6 text-muted">/mo</small></h2>
                            <ul class="list-unstyled my-4 text-start mx-auto" style="max-width: 200px;">
                                <li class="mb-2"><i class="fa-solid fa-check text-success me-2"></i> Manual Entry</li>
                                <li class="mb-2"><i class="fa-solid fa-check text-success me-2"></i> 1 Department</li>
                                <li class="mb-2 text-muted"><i class="fa-solid fa-xmark me-2"></i> AI Generation</li>
                            </ul>
                            <button class="btn btn-outline-primary rounded-pill w-100">Sign Up Free</button>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 mb-4">
                    <div
                        class="card border-0 shadow rounded-4 position-relative overflow-hidden bg-primary text-white scale-up">
                        <div class="ribbon">POPULAR</div>
                        <div class="card-body p-5 text-center">
                            <h5 class="fw-bold text-white-50">University Pro</h5>
                            <h2 class="display-4 fw-bold">$299<small class="fs-6 text-white-50">/mo</small></h2>
                            <ul class="list-unstyled my-4 text-start mx-auto" style="max-width: 220px;">
                                <li class="mb-2"><i class="fa-solid fa-check text-warning me-2"></i> AI Auto-Schedule
                                </li>
                                <li class="mb-2"><i class="fa-solid fa-check text-warning me-2"></i> Unlimited Rooms
                                </li>
                                <li class="mb-2"><i class="fa-solid fa-check text-warning me-2"></i> Conflict Detection
                                </li>
                                <li class="mb-2"><i class="fa-solid fa-check text-warning me-2"></i> Print Exports</li>
                            </ul>
                            <button class="btn btn-light text-primary fw-bold rounded-pill w-100" data-bs-toggle="modal"
                                data-bs-target="#demoModal">Get Started</button>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 mb-4">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-body p-5 text-center">
                            <h5 class="fw-bold text-muted">Enterprise</h5>
                            <h2 class="display-4 fw-bold">Custom</h2>
                            <ul class="list-unstyled my-4 text-start mx-auto" style="max-width: 200px;">
                                <li class="mb-2"><i class="fa-solid fa-check text-success me-2"></i> Multi-Campus</li>
                                <li class="mb-2"><i class="fa-solid fa-check text-success me-2"></i> Dedicated Support
                                </li>
                                <li class="mb-2"><i class="fa-solid fa-check text-success me-2"></i> Custom Integrations
                                </li>
                            </ul>
                            <button class="btn btn-outline-dark rounded-pill w-100">Contact Sales</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="about" class="py-5 bg-light">
        <div class="container py-5">
            <div class="row align-items-center">
                <div class="col-lg-6 mb-4 mb-lg-0">
                    <img src="https://images.unsplash.com/photo-1522071820081-009f0129c71c?ixlib=rb-1.2.1&auto=format&fit=crop&w=800&q=80"
                        alt="Team" class="img-fluid rounded-4 shadow-lg">
                </div>
                <div class="col-lg-6 ps-lg-5">
                    <h6 class="text-primary fw-bold text-uppercase">About SlotSmart</h6>
                    <h2 class="fw-bold mb-4">Empowering Education Through Technology</h2>
                    <p class="text-muted lead">We are a team of developers and educators passionate about solving the
                        complex problem of resource allocation.</p>
                    <p class="text-muted">SlotSmart was founded with a single mission: to eliminate the chaos of manual
                        scheduling. By leveraging cutting-edge Genetic Algorithms, we save universities hundreds of
                        administrative hours every semester.</p>
                    <div class="mt-4">
                        <div class="d-flex align-items-center mb-3">
                            <div class="bg-primary text-white rounded-circle p-2 me-3"><i class="fa-solid fa-check"></i>
                            </div>
                            <div><strong>Trusted by 50+ Institutes</strong></div>
                        </div>
                        <div class="d-flex align-items-center">
                            <div class="bg-primary text-white rounded-circle p-2 me-3"><i class="fa-solid fa-check"></i>
                            </div>
                            <div><strong>99.9% Conflict Free Guarantee</strong></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="contact" class="py-5 bg-dark text-white">
        <div class="container py-5">
            <div class="row justify-content-center">
                <div class="col-lg-8 text-center">
                    <h2 class="fw-bold mb-3">Ready to optimize your campus?</h2>
                    <p class="lead text-white-50 mb-5">Get in touch with our team for a personalized walkthrough.</p>
                </div>
            </div>
            <div class="row justify-content-center">
                <div class="col-lg-6">
                    <form class="p-4 bg-white rounded-4 shadow text-dark">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Full Name</label>
                            <input type="text" class="form-control" placeholder="Dr. John Doe">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">University Email</label>
                            <input type="email" class="form-control" placeholder="john@university.edu">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Message</label>
                            <textarea class="form-control" rows="4"
                                placeholder="Tell us about your requirements..."></textarea>
                        </div>
                        <button type="button" class="btn btn-primary w-100 fw-bold py-2"
                            onclick="alert('Thank you! We will contact you shortly.')">Send Message</button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    <footer class="py-4 bg-black text-white-50 text-center">
        <div class="container">
            <p class="mb-0">&copy; 2026 SlotSmart Inc. All Rights Reserved.</p>
        </div>
    </footer>


    <div class="modal fade" id="demoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 overflow-hidden">
                <div class="modal-header bg-primary text-white border-0">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-rocket me-2"></i> Get Instant Demo Access</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <!-- Step 1: Email -->
                <div class="modal-body p-4" id="demoStep1">
                    <p class="text-muted mb-4 text-center">Enter your email to access your personal demo sandbox. Your data is saved and waiting for you every time you return.</p>
                    <div class="form-floating mb-3">
                        <input type="email" class="form-control" id="demoEmail" placeholder="name@example.com">
                        <label for="demoEmail">Email address</label>
                    </div>
                    <div id="demoEmailError" class="text-danger small mb-2 d-none"></div>
                    <button class="btn btn-primary w-100 py-3 fw-bold rounded-pill" onclick="demoCheckEmail()">
                        Continue <i class="fa-solid fa-arrow-right ms-2"></i>
                    </button>
                </div>

                <!-- Step 2A: New user — set password -->
                <div class="modal-body p-4 d-none" id="demoStep2New">
                    <div class="text-center mb-3">
                        <i class="fa-solid fa-user-plus fa-3x text-primary mb-2"></i>
                        <h5 class="fw-bold">Create Your Sandbox</h5>
                        <p class="text-muted small">First time here! Set a password to secure your private demo environment.</p>
                        <div class="badge bg-light text-dark border mb-3 px-3 py-2" id="demoEmailBadgeNew"></div>
                    </div>
                    <div class="form-floating mb-2">
                        <input type="password" class="form-control" id="demoPassNew" placeholder="Password">
                        <label>Password (min 6 characters)</label>
                    </div>
                    <div class="form-floating mb-3">
                        <input type="password" class="form-control" id="demoPassConfirm" placeholder="Confirm">
                        <label>Confirm Password</label>
                    </div>
                    <div id="demoNewError" class="text-danger small mb-2 d-none"></div>
                    <button class="btn btn-success w-100 py-3 fw-bold rounded-pill" onclick="demoSubmit(true)">
                        <i class="fa-solid fa-rocket me-2"></i> Create & Launch
                    </button>
                    <button class="btn btn-link btn-sm w-100 mt-2 text-muted" onclick="demoGoBack()">Back</button>
                </div>

                <!-- Step 2B: Returning user — enter password -->
                <div class="modal-body p-4 d-none" id="demoStep2Return">
                    <div class="text-center mb-3">
                        <i class="fa-solid fa-door-open fa-3x text-success mb-2"></i>
                        <h5 class="fw-bold">Welcome Back!</h5>
                        <p class="text-muted small">Your demo data is right where you left it.</p>
                        <div class="badge bg-light text-dark border mb-3 px-3 py-2" id="demoEmailBadgeReturn"></div>
                    </div>
                    <div class="form-floating mb-3">
                        <input type="password" class="form-control" id="demoPassReturn" placeholder="Password">
                        <label>Your Password</label>
                    </div>
                    <div id="demoReturnError" class="text-danger small mb-2 d-none"></div>
                    <button class="btn btn-success w-100 py-3 fw-bold rounded-pill" onclick="demoSubmit(false)">
                        <i class="fa-solid fa-arrow-right me-2"></i> Enter My Sandbox
                    </button>
                    <button class="btn btn-link btn-sm w-100 mt-2 text-muted" onclick="demoGoBack()">Back</button>
                </div>

                <!-- Step 3: Loading -->
                <div class="modal-body p-5 text-center d-none" id="demoStep3">
                    <div class="spinner-border text-primary mb-3" style="width:3rem;height:3rem;"></div>
                    <h5 class="fw-bold" id="demoLoadingMsg">Setting up your sandbox...</h5>
                    <p class="text-muted small">This only takes a moment.</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 1. NAVBAR SCROLL LOGIC
        const navbar = document.getElementById('mainNav');
        window.addEventListener('scroll', function () {
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
                navbar.classList.remove('navbar-dark');
                navbar.classList.add('navbar-light'); // Switches text to dark
            } else {
                navbar.classList.remove('scrolled');
                navbar.classList.add('navbar-dark'); // Switches text back to white
                navbar.classList.remove('navbar-light');
            }
        });

        // 2. DEMO MODAL LOGIC
        let demoEmail = '';

        function demoShow(stepId) {
            ['demoStep1','demoStep2New','demoStep2Return','demoStep3'].forEach(id => {
                document.getElementById(id).classList.add('d-none');
            });
            document.getElementById(stepId).classList.remove('d-none');
        }

        function demoGoBack() { demoShow('demoStep1'); }

        function demoShowError(elId, msg) {
            const el = document.getElementById(elId);
            el.textContent = msg;
            el.classList.remove('d-none');
        }
        function demoClearErrors() {
            ['demoEmailError','demoNewError','demoReturnError'].forEach(id => {
                document.getElementById(id).classList.add('d-none');
            });
        }

        function demoCheckEmail() {
            demoClearErrors();
            demoEmail = document.getElementById('demoEmail').value.trim();
            if (!demoEmail) { demoShowError('demoEmailError', 'Please enter your email.'); return; }

            const fd = new FormData();
            fd.append('action', 'check_email');
            fd.append('email', demoEmail);

            fetch('demo_handler.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) { demoShowError('demoEmailError', data.message); return; }
                    if (data.is_admin) { window.location.href = data.redirect; return; }
                    if (data.is_new) {
                        document.getElementById('demoEmailBadgeNew').textContent = demoEmail;
                        demoShow('demoStep2New');
                    } else {
                        document.getElementById('demoEmailBadgeReturn').textContent = demoEmail;
                        demoShow('demoStep2Return');
                    }
                })
                .catch(() => demoShowError('demoEmailError', 'Connection error. Please try again.'));
        }

        function demoSubmit(isNew) {
            demoClearErrors();
            const password = document.getElementById(isNew ? 'demoPassNew' : 'demoPassReturn').value;
            const errEl    = isNew ? 'demoNewError' : 'demoReturnError';

            if (password.length < 6) { demoShowError(errEl, 'Password must be at least 6 characters.'); return; }

            if (isNew) {
                const confirm = document.getElementById('demoPassConfirm').value;
                if (password !== confirm) { demoShowError(errEl, 'Passwords do not match.'); return; }
            }

            demoShow('demoStep3');
            document.getElementById('demoLoadingMsg').textContent = isNew
                ? 'Creating your private sandbox...'
                : 'Loading your sandbox...';

            const fd = new FormData();
            fd.append('action',           'access');
            fd.append('email',            demoEmail);
            fd.append('password',         password);
            if (isNew) fd.append('confirm_password', document.getElementById('demoPassConfirm').value);

            fetch('demo_handler.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        window.location.href = data.redirect;
                    } else {
                        demoShow(isNew ? 'demoStep2New' : 'demoStep2Return');
                        demoShowError(errEl, data.message);
                    }
                })
                .catch(() => {
                    demoShow(isNew ? 'demoStep2New' : 'demoStep2Return');
                    demoShowError(errEl, 'Connection error. Please try again.');
                });
        }

        // Allow Enter key in email field
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('demoEmail').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') demoCheckEmail();
            });
        });
    </script>
</body>

</html>
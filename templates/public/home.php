<?php $pageTitle = 'Home'; ?>
<?php require APP_ROOT . '/templates/layouts/header.php'; ?>

<nav class="navbar">
    <div class="container">
        <a href="<?= APP_URL ?>/" class="navbar-brand">
            <span class="brand-tch">TCH</span> Placements
        </a>
        <ul class="navbar-nav">
            <li><a href="#services">Services</a></li>
            <li><a href="#caregivers">Caregivers</a></li>
            <li><a href="#clients">Clients</a></li>
            <li><a href="#contact">Contact</a></li>
            <li><a href="<?= APP_URL ?>/login" class="btn btn-sm btn-primary">Admin</a></li>
        </ul>
    </div>
</nav>

<!-- Hero -->
<section class="hero">
    <div class="container">
        <h1>Compassionate Care, <span class="highlight">Professionally Placed</span></h1>
        <p>TCH Placements sources, vets, and places qualified caregivers with families and care providers across Gauteng. Trained through the QCTO-registered Tuniti Care Hero programme.</p>
        <div class="hero-actions">
            <a href="#clients" class="btn btn-primary">I Need a Caregiver</a>
            <a href="#caregivers" class="btn btn-outline" style="color:#fff;border-color:#fff;">I'm a Caregiver</a>
        </div>
    </div>
</section>

<!-- Stats -->
<section class="stats-bar">
    <div class="container">
        <div class="stats-grid">
            <div class="stat-item">
                <h3>140+</h3>
                <p>Caregivers in our pipeline</p>
            </div>
            <div class="stat-item">
                <h3>60+</h3>
                <p>Active client accounts</p>
            </div>
            <div class="stat-item">
                <h3>Gauteng</h3>
                <p>Based &amp; operating</p>
            </div>
            <div class="stat-item">
                <h3>QCTO</h3>
                <p>Registered training</p>
            </div>
        </div>
    </div>
</section>

<!-- Services -->
<section class="section" id="services">
    <div class="container">
        <div class="section-header">
            <h2>What We Do</h2>
            <p>A complete caregiver placement service — from recruitment and training through to placement and ongoing support.</p>
        </div>
        <div class="grid grid-3">
            <div class="card">
                <div class="card-icon">&#9734;</div>
                <h3>Recruitment &amp; Vetting</h3>
                <p>We recruit caregivers locally across Gauteng and put every candidate through rigorous background checks, qualification verification, and suitability assessments.</p>
            </div>
            <div class="card">
                <div class="card-icon">&#9998;</div>
                <h3>Certified Training</h3>
                <p>Our caregivers are trained through the Tuniti Care Hero programme — a QCTO-registered course covering full-time, post-operative, palliative, and respite care.</p>
            </div>
            <div class="card">
                <div class="card-icon">&#10003;</div>
                <h3>Placement &amp; Matching</h3>
                <p>We match caregivers to roles based on skill fit, availability, and location — ensuring the right person for every family and care situation.</p>
            </div>
        </div>
    </div>
</section>

<!-- CTA Split: Caregivers / Clients -->
<section id="caregivers">
    <div class="cta-split">
        <div class="cta-block cta-caregivers">
            <h2>For Caregivers</h2>
            <p>Looking for placement opportunities in Gauteng? Join our pipeline of trained, vetted caregivers and get matched with families who need your skills. We handle documentation, onboarding, and placement coordination.</p>
            <div>
                <a href="#contact" class="btn">Register Your Interest</a>
            </div>
        </div>
        <div class="cta-block cta-clients" id="clients">
            <h2>For Families &amp; Care Providers</h2>
            <p>Need a qualified, reliable caregiver? We source from a pre-vetted pipeline of trained professionals. Whether you need full-time, day shift, or live-in care — we'll find the right match.</p>
            <div>
                <a href="#contact" class="btn btn-outline">Find a Caregiver</a>
            </div>
        </div>
    </div>
</section>

<!-- How It Works -->
<section class="section section-alt">
    <div class="container">
        <div class="section-header">
            <h2>How It Works</h2>
        </div>
        <div class="grid grid-3">
            <div class="card" style="text-align:center;">
                <div class="card-icon" style="margin:0 auto 1.25rem;font-size:1.5rem;font-weight:700;background:#10B2B4;color:#fff;">1</div>
                <h3>Tell Us What You Need</h3>
                <p>Contact us with your care requirements — type of care, schedule, location, and any specific needs.</p>
            </div>
            <div class="card" style="text-align:center;">
                <div class="card-icon" style="margin:0 auto 1.25rem;font-size:1.5rem;font-weight:700;background:#10B2B4;color:#fff;">2</div>
                <h3>We Match &amp; Vet</h3>
                <p>We select suitable caregivers from our pipeline, verify availability, and confirm the fit for your situation.</p>
            </div>
            <div class="card" style="text-align:center;">
                <div class="card-icon" style="margin:0 auto 1.25rem;font-size:1.5rem;font-weight:700;background:#10B2B4;color:#fff;">3</div>
                <h3>Placement Begins</h3>
                <p>Your caregiver starts. We coordinate onboarding and remain available for ongoing support and replacements.</p>
            </div>
        </div>
    </div>
</section>

<!-- Contact -->
<section class="section" id="contact">
    <div class="container">
        <div class="section-header">
            <h2>Get In Touch</h2>
            <p>Whether you're a caregiver looking for work or a family in need of care, we'd love to hear from you.</p>
        </div>
        <div style="text-align:center;">
            <a href="mailto:info@tch.intelligentae.co.uk" class="btn btn-primary btn-lg" style="padding:1rem 2.5rem;font-size:1.1rem;">Email Us</a>
        </div>
    </div>
</section>

<?php require APP_ROOT . '/templates/layouts/footer.php'; ?>

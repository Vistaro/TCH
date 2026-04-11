<?php
/**
 * TCH Placements — Public homepage.
 *
 * Loads the primary region from the database so all contact details and
 * service-area copy come from config rather than hardcoded markup. Future
 * per-region pages (Western Cape, KZN, etc.) will reuse this same template
 * with a different region row.
 */
$pageTitle = 'Trusted Caregiver Placement in Gauteng';

// CSRF token for the inquiry form
initSession();
$csrfToken = generateCsrfToken();

// Pull the primary region (Gauteng for now). All contact details and the
// service area description come from this row so they are configurable
// without code changes.
$db = getDB();
$region = $db->query(
    "SELECT * FROM regions WHERE is_active = 1 AND is_primary = 1 LIMIT 1"
)->fetch();

if (!$region) {
    // Fallback so the page never crashes if the seed has been removed.
    $region = [
        'name'                     => 'Gauteng',
        'phone_primary'            => 'XXX XXX XXXX',
        'email_primary'            => 'hello@tch.intelligentae.co.uk',
        'physical_address'         => 'Pretoria, Gauteng',
        'service_area_description' => 'Within 25 miles of Pretoria — expanding across Gauteng',
        'office_hours'             => 'Mon-Fri 8:00-17:00',
        'id'                       => null,
    ];
}

// Pull live caregiver/client counts for the stats bar
$pipelineCount = (int)$db->query(
    "SELECT COUNT(*) FROM persons WHERE FIND_IN_SET('caregiver', person_type)"
)->fetchColumn();
// "Active client" is derived from recent revenue rather than a stored
// status flag — see the single-source-of-truth standing rule in
// C:\ClaudeCode\CLAUDE.md. A client counts as active if they have
// any revenue row in the current or previous 2 calendar months.
$clientCount = (int)$db->query(
    "SELECT COUNT(DISTINCT cr.client_id)
     FROM client_revenue cr
     INNER JOIN persons p ON p.id = cr.client_id
                          AND FIND_IN_SET('client', p.person_type)
     WHERE cr.month_date >= DATE_SUB(DATE_FORMAT(CURRENT_DATE, '%Y-%m-01'), INTERVAL 2 MONTH)"
)->fetchColumn();

// Optional: success / error flag set by the form handler
$enquiryResult = $_GET['enquiry'] ?? null;
?>
<?php require APP_ROOT . '/templates/layouts/header.php'; ?>

<button class="public-menu-toggle"
        aria-label="Open menu"
        aria-controls="publicNav"
        aria-expanded="false"
        onclick="document.body.classList.toggle('public-menu-open');this.setAttribute('aria-expanded',document.body.classList.contains('public-menu-open'))">&#9776;</button>

<nav class="navbar">
    <div class="container">
        <a href="<?= APP_URL ?>/" class="navbar-brand">
            <span class="brand-tch">TCH</span> Placements
        </a>
        <ul class="navbar-nav" id="publicNav">
            <li><a href="#services" onclick="document.body.classList.remove('public-menu-open')">Care Services</a></li>
            <li><a href="#why-tch" onclick="document.body.classList.remove('public-menu-open')">Why TCH</a></li>
            <li><a href="#how-it-works" onclick="document.body.classList.remove('public-menu-open')">How It Works</a></li>
            <li><a href="#enquire" onclick="document.body.classList.remove('public-menu-open')">Find a Caregiver</a></li>
            <li><a href="<?= APP_URL ?>/login" class="btn btn-sm btn-primary">Admin</a></li>
        </ul>
    </div>
</nav>
<div class="public-menu-overlay" onclick="document.body.classList.remove('public-menu-open')"></div>

<!-- ============================================================
     Hero
     ============================================================ -->
<section class="hero hero-image">
    <div class="container">
        <h1>Trusted Caregivers, <span class="highlight">Placed Where You Need Them</span></h1>
        <p>
            TCH Placements connects families across <?= htmlspecialchars($region['name']) ?>
            with verified, professionally trained caregivers — every one of them
            backed by the Tuniti Care Hero programme. When life is hard, you should
            never have to wonder if the person at the door can be trusted.
        </p>
        <div class="hero-actions">
            <a href="#enquire" class="btn btn-primary btn-lg">Find a Care Hero</a>
            <a href="#services" class="btn btn-outline" style="color:#fff;border-color:#fff;">See Our Care Services</a>
        </div>
    </div>
</section>

<!-- ============================================================
     Stats / Trust Bar
     ============================================================ -->
<section class="stats-bar">
    <div class="container">
        <div class="stats-grid">
            <div class="stat-item">
                <h3><?= max(140, $pipelineCount) ?>+</h3>
                <p>Verified caregivers in our network</p>
            </div>
            <div class="stat-item">
                <h3><?= max(60, $clientCount) ?>+</h3>
                <p>Families and care providers served</p>
            </div>
            <div class="stat-item">
                <h3>QCTO</h3>
                <p>Accredited training programme</p>
            </div>
            <div class="stat-item">
                <h3><?= htmlspecialchars($region['name']) ?></h3>
                <p><?= htmlspecialchars($region['service_area_description']) ?></p>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     Care Services — the 5 named services we deliver
     ============================================================ -->
<section class="section" id="services">
    <div class="container">
        <div class="section-header">
            <h2>The Care You're Looking For</h2>
            <p>
                From a few hours of help while you take a break, to round-the-clock
                live-in care for someone who needs constant support. Every placement
                is matched to your situation by people who understand that no two
                families are the same.
            </p>
        </div>
        <div class="grid grid-3 services-grid">
            <div class="card service-card">
                <div class="service-icon">&#9728;</div>
                <h3>Full-Time Care</h3>
                <p>
                    Daily, ongoing support for a loved one who needs help getting through
                    each day. Choose between <strong>permanent</strong> placement or
                    <strong>temporary</strong> cover when your usual carer is unavailable.
                </p>
                <a href="#enquire" class="btn btn-sm btn-outline">Enquire</a>
            </div>
            <div class="card service-card">
                <div class="service-icon">&#10010;</div>
                <h3>Post-Operative Care</h3>
                <p>
                    Recovering at home after surgery or hospitalisation? Short-term
                    caregiving support during the recovery window so you can heal
                    without worrying about the basics.
                </p>
                <a href="#enquire" class="btn btn-sm btn-outline">Enquire</a>
            </div>
            <div class="card service-card">
                <div class="service-icon">&#10084;</div>
                <h3>Palliative Care</h3>
                <p>
                    Gentle, dignified support for those facing serious illness or end-of-life
                    care. Our caregivers are trained to bring comfort, calm and respect to
                    the most difficult moments.
                </p>
                <a href="#enquire" class="btn btn-sm btn-outline">Enquire</a>
            </div>
            <div class="card service-card">
                <div class="service-icon">&#9749;</div>
                <h3>Respite Care</h3>
                <p>
                    Caring for a family member is rewarding — but even heroes need a break.
                    We step in for a few hours, a day, a week, so you can rest, travel or
                    simply breathe.
                </p>
                <a href="#enquire" class="btn btn-sm btn-outline">Enquire</a>
            </div>
            <div class="card service-card">
                <div class="service-icon">&#9971;</div>
                <h3>Errand Care</h3>
                <p>
                    Help with the everyday: shopping, pharmacy runs, doctor's appointments,
                    light housekeeping. The kind of practical support that lets independent
                    people stay independent for longer.
                </p>
                <a href="#enquire" class="btn btn-sm btn-outline">Enquire</a>
            </div>
            <div class="card service-card service-card-highlight">
                <div class="service-icon">&#9758;</div>
                <h3>Not Sure What You Need?</h3>
                <p>
                    Tell us a little about the situation and we'll help you work out which
                    type of care fits best. There's no obligation — just a conversation.
                </p>
                <a href="#enquire" class="btn btn-sm btn-primary">Talk to us</a>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     Why TCH — differentiators
     ============================================================ -->
<section class="section section-alt" id="why-tch">
    <div class="container">
        <div class="section-header">
            <h2>Why Families Choose TCH</h2>
            <p>The difference between hiring an individual and partnering with a platform.</p>
        </div>
        <div class="grid grid-4">
            <div class="card why-card">
                <div class="why-icon">&#10003;</div>
                <h3>Verified, Vetted, Trained</h3>
                <p>
                    Every caregiver in our network has been through identity checks,
                    background verification, and the QCTO-accredited Tuniti Care Hero
                    training programme. You're never hiring a stranger off the street.
                </p>
            </div>
            <div class="card why-card">
                <div class="why-icon">&#9881;</div>
                <h3>Matched, Not Just Sent</h3>
                <p>
                    We pair clients and caregivers with intention — looking at skill,
                    schedule, language, location and personality. The goal is a fit
                    that works on day 90, not just day one.
                </p>
            </div>
            <div class="card why-card">
                <div class="why-icon">&#10145;</div>
                <h3>Cover When Life Happens</h3>
                <p>
                    The biggest worry with hiring an individual carer is what happens
                    when they're sick or move on. Because TCH manages a network of
                    caregivers, we cover absences and find replacements — you're never
                    left in the lurch.
                </p>
            </div>
            <div class="card why-card">
                <div class="why-icon">&#9836;</div>
                <h3>One Trusted Brand</h3>
                <p>
                    Every caregiver placed through TCH represents the Tuniti Care Hero
                    brand. They're held to a standard, supported when they need it, and
                    accountable to a team — not just to themselves.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     How It Works
     ============================================================ -->
<section class="section" id="how-it-works">
    <div class="container">
        <div class="section-header">
            <h2>How It Works</h2>
            <p>Three simple steps to a caregiver in your home.</p>
        </div>
        <div class="grid grid-3">
            <div class="card how-card">
                <div class="how-step">1</div>
                <h3>Tell Us What You Need</h3>
                <p>
                    Use the form below or give us a call. Tell us about the person who
                    needs care, the kind of support they need, where you are, and when
                    you'd like cover to start.
                </p>
            </div>
            <div class="card how-card">
                <div class="how-step">2</div>
                <h3>We Find the Right Match</h3>
                <p>
                    We search our network for caregivers who fit your situation,
                    confirm their availability, and put forward the best candidates
                    for you to meet — usually within 48 hours.
                </p>
            </div>
            <div class="card how-card">
                <div class="how-step">3</div>
                <h3>Care Begins, We Stay With You</h3>
                <p>
                    Your caregiver starts. We handle the paperwork and stay in touch
                    throughout the placement — covering absences, answering questions,
                    and making sure things stay on track.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ============================================================
     Trust block — the platform value proposition
     ============================================================ -->
<section class="section section-trust" id="trust">
    <div class="container">
        <div class="trust-block">
            <h2>You're Not Just Hiring a Person.<br><span class="highlight">You're Joining a Network.</span></h2>
            <p>
                When you place a caregiver through TCH, you get more than one individual.
                You get the whole platform standing behind them — the training that prepared
                them, the team that supports them, and the network that covers for them when
                life happens. That's the value of doing this through us instead of going it alone.
            </p>
            <a href="#enquire" class="btn btn-primary btn-lg">Find a Caregiver Today</a>
        </div>
    </div>
</section>

<!-- ============================================================
     Enquiry form
     ============================================================ -->
<section class="section section-alt" id="enquire">
    <div class="container">
        <div class="section-header">
            <h2>Find a Care Hero</h2>
            <p>
                Tell us a little about what you're looking for and we'll be in touch
                — usually the same day during office hours.
            </p>
        </div>

        <?php if ($enquiryResult === 'success'): ?>
            <div class="alert alert-success">
                <strong>Thank you.</strong> Your enquiry has been received. A member of
                our team will be in touch shortly. If your situation is urgent, please
                call us on <strong><?= htmlspecialchars($region['phone_primary']) ?></strong>.
            </div>
        <?php elseif ($enquiryResult === 'error'): ?>
            <div class="alert alert-error">
                Sorry — there was a problem submitting your enquiry. Please try again
                or email us directly at <a href="mailto:<?= htmlspecialchars($region['email_primary']) ?>"><?= htmlspecialchars($region['email_primary']) ?></a>.
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= APP_URL ?>/enquire" class="enquiry-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="region_id" value="<?= (int)($region['id'] ?? 0) ?>">
            <!-- Honeypot — bots fill this in, humans don't see it -->
            <div style="position:absolute;left:-9999px;" aria-hidden="true">
                <label>If you're a human, leave this blank<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="enq_name">Full Name <span class="req">*</span></label>
                    <input type="text" id="enq_name" name="full_name" required maxlength="200">
                </div>
                <div class="form-group">
                    <label for="enq_phone">Phone Number <span class="req">*</span></label>
                    <input type="tel" id="enq_phone" name="phone" required maxlength="30" placeholder="e.g. 082 123 4567">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="enq_email">Email Address</label>
                    <input type="email" id="enq_email" name="email" maxlength="150">
                </div>
                <div class="form-group">
                    <label for="enq_area">Suburb / Area Where Care Is Needed</label>
                    <input type="text" id="enq_area" name="suburb_or_area" maxlength="150" placeholder="e.g. Hatfield, Pretoria">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="enq_care_type">Type of Care Needed <span class="req">*</span></label>
                    <select id="enq_care_type" name="care_type" required>
                        <option value="">— Please choose —</option>
                        <option value="permanent">Full-Time Care — Permanent</option>
                        <option value="temporary">Full-Time Care — Temporary</option>
                        <option value="post_op">Post-Operative Care</option>
                        <option value="palliative">Palliative Care</option>
                        <option value="respite">Respite Care</option>
                        <option value="errand">Errand Care</option>
                        <option value="other">I'm not sure — please advise</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="enq_urgency">When Do You Need Care?</label>
                    <select id="enq_urgency" name="urgency">
                        <option value="">— Please choose —</option>
                        <option value="immediate">Immediately</option>
                        <option value="within_week">Within a week</option>
                        <option value="within_month">Within a month</option>
                        <option value="planning">Just planning ahead</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="enq_message">Tell us a little about the situation</label>
                <textarea id="enq_message" name="message" rows="4" maxlength="2000" placeholder="The more we know, the better we can match you. There's no rush — write as much or as little as you like."></textarea>
            </div>

            <div class="form-group form-checkbox">
                <label>
                    <input type="checkbox" name="consent_terms" value="1" required>
                    I agree to TCH Placements contacting me about this enquiry and storing
                    my details in line with the South African POPIA regulations. <span class="req">*</span>
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">Send My Enquiry</button>
                <p class="form-note">
                    We'll get back to you usually within a few hours during office hours.
                    If your situation is urgent, please phone <strong><?= htmlspecialchars($region['phone_primary']) ?></strong>.
                </p>
            </div>
        </form>
    </div>
</section>

<!-- ============================================================
     Contact / Footer details — pulled from regions table
     ============================================================ -->
<section class="section" id="contact">
    <div class="container">
        <div class="contact-grid">
            <div>
                <h3>Phone</h3>
                <p class="contact-big"><?= htmlspecialchars($region['phone_primary']) ?></p>
                <?php if (!empty($region['phone_secondary'])): ?>
                    <p><?= htmlspecialchars($region['phone_secondary']) ?></p>
                <?php endif; ?>
                <?php if (!empty($region['office_hours'])): ?>
                    <p class="muted"><?= htmlspecialchars($region['office_hours']) ?></p>
                <?php endif; ?>
            </div>
            <div>
                <h3>Email</h3>
                <p class="contact-big">
                    <a href="mailto:<?= htmlspecialchars($region['email_primary']) ?>">
                        <?= htmlspecialchars($region['email_primary']) ?>
                    </a>
                </p>
                <?php if (!empty($region['email_secondary'])): ?>
                    <p><a href="mailto:<?= htmlspecialchars($region['email_secondary']) ?>"><?= htmlspecialchars($region['email_secondary']) ?></a></p>
                <?php endif; ?>
            </div>
            <div>
                <h3>Where We Operate</h3>
                <?php if (!empty($region['physical_address'])): ?>
                    <p><?= htmlspecialchars($region['physical_address']) ?></p>
                <?php endif; ?>
                <p class="muted"><?= htmlspecialchars($region['service_area_description']) ?></p>
            </div>
        </div>
    </div>
</section>

<?php
// Pass region data to the footer for consistent contact details
$footerRegion = $region;
require APP_ROOT . '/templates/layouts/footer.php';
?>

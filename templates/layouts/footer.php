<?php
// $footerRegion is set by the home page; fall back to a fresh query so the
// footer also works on standalone pages where the variable wasn't passed in.
if (!isset($footerRegion) || !is_array($footerRegion)) {
    try {
        $footerRegion = getDB()->query(
            "SELECT * FROM regions WHERE is_active = 1 AND is_primary = 1 LIMIT 1"
        )->fetch() ?: [];
    } catch (\Throwable $e) {
        $footerRegion = [];
    }
}
$_phone = $footerRegion['phone_primary'] ?? 'XXX XXX XXXX';
$_email = $footerRegion['email_primary'] ?? 'hello@tch.intelligentae.co.uk';
$_area  = $footerRegion['service_area_description'] ?? 'Gauteng, South Africa';
?>
<footer class="footer">
    <div class="container">
        <div class="footer-grid">
            <div>
                <h4><span class="brand-tch" style="color:#10B2B4">TCH</span> Placements</h4>
                <p>Trusted caregiver placement across <?= htmlspecialchars($footerRegion['name'] ?? 'Gauteng') ?>. Every caregiver in our network is verified, trained through the QCTO-accredited Tuniti Care Hero programme, and supported by the TCH platform.</p>
            </div>
            <div>
                <h4>Quick Links</h4>
                <ul class="footer-links">
                    <li><a href="<?= APP_URL ?>/">Home</a></li>
                    <li><a href="<?= APP_URL ?>/#services">Care Services</a></li>
                    <li><a href="<?= APP_URL ?>/#why-tch">Why TCH</a></li>
                    <li><a href="<?= APP_URL ?>/#how-it-works">How It Works</a></li>
                    <li><a href="<?= APP_URL ?>/#enquire">Find a Caregiver</a></li>
                </ul>
            </div>
            <div>
                <h4>Contact</h4>
                <ul class="footer-links">
                    <li><strong><?= htmlspecialchars($_phone) ?></strong></li>
                    <li><a href="mailto:<?= htmlspecialchars($_email) ?>"><?= htmlspecialchars($_email) ?></a></li>
                    <li><?= htmlspecialchars($_area) ?></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <span>&copy; <?= date('Y') ?> TCH Placements. All rights reserved.</span>
            <span>An <a href="https://intelligentae.co.uk" style="color:#10B2B4">Intelligentae</a> Platform Partner</span>
        </div>
    </div>
</footer>
</body>
</html>

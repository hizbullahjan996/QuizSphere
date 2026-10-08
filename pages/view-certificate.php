<?php
/**
 * QuizSphere - View Certificate
 * Authenticated. Displays a single earned certificate with its unique ID,
 * QR verification code, and a "Download PDF" button (jsPDF + html2canvas + QRCode.js).
 */
require_once __DIR__ . '/../includes/bootstrap.php';

try {
    $client = SupabaseClient::fromConfig();
    $auth = new Auth($client);
} catch (SupabaseException $e) {
    $auth = null;
}

if (!$auth || !$auth->check()) {
    set_flash('error', 'Please log in to view this certificate.');
    redirect('/login.php');
}

$certId = (string) ($_GET['id'] ?? '');
if (!preg_match('/^[0-9a-fA-F-]{36}$/', $certId)) {
    set_flash('error', 'Invalid certificate.');
    redirect('/pages/certificates.php');
}

$logoPath = __DIR__ . '/../assets/images/logo.png';
$logoDataUri = file_exists($logoPath)
    ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPath))
    : url('/assets/images/logo.png');

$page_title = 'Official Certificate of Achievement';
$active_page = 'pages';
require __DIR__ . '/../includes/head.php';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800;900&family=Playfair+Display:ital,wght@0,600;0,700;1,400;1,600&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">

<body>
<div class="dashboard-shell">
  <?php $dashboard_active = 'certificates'; require __DIR__ . '/../includes/sidebar.php'; ?>

  <main class="dashboard-main" id="certificate-view" data-cert-id="<?php echo e($certId); ?>">

    <!-- Certificate Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 cert-view-toolbar">
      <div>
        <div class="d-inline-flex align-items-center gap-2 badge-soft text-brand mb-1">
          <i class="bi bi-patch-check-fill"></i> Verified Academic Credential
        </div>
        <h1 class="h4 fw-bold text-ink-900 mb-0">Official Certificate of Achievement</h1>
        <p class="text-muted-ink small mb-0">Cryptographically verifiable credential issued by QuizSphere AI Assessment Engine.</p>
      </div>
      <div class="d-flex flex-wrap gap-2 cert-action-buttons">
        <button class="btn btn-brand d-inline-flex align-items-center" id="cert-download">
          <i class="bi bi-file-earmark-pdf-fill me-2"></i>Download PDF
        </button>
        <button class="btn btn-outline-brand d-inline-flex align-items-center" onclick="window.print()">
          <i class="bi bi-printer-fill me-2"></i>Print
        </button>
        <a href="<?php echo url('/verify-certificate.php?id=' . e($certId)); ?>" target="_blank" rel="noopener" class="btn btn-outline-brand d-inline-flex align-items-center">
          <i class="bi bi-shield-check me-2"></i>Public Ledger
        </a>
        <a href="<?php echo url('/pages/certificates.php'); ?>" class="btn btn-ghost text-muted-ink d-inline-flex align-items-center">
          <i class="bi bi-arrow-left me-1"></i>All Certificates
        </a>
      </div>
    </div>

    <div class="form-alert mt-0 mb-4" id="cert-error" hidden></div>

    <!-- Loading State -->
    <div id="cert-loading" class="text-center text-muted-ink py-5 card shadow-xs border-0">
      <div class="spinner-border text-brand mb-3" role="status"></div>
      <div class="fw-semibold text-ink-800">Retrieving certified credential from QuizSphere registry...</div>
      <div class="small text-muted-ink mt-1">Preparing high-resolution vector assets &amp; signature verification</div>
    </div>

    <!-- Certificate Display Panel -->
    <div id="cert-panel" class="d-none">
      <div class="cert-canvas-container">
        
        <!-- The Canva-Grade Certificate Sheet -->
        <div class="certificate-sheet" id="certificate-print-sheet">
          
          <!-- Outer Navy & Inner Gold Dual Border Frames -->
          <div class="cert-border-outer"></div>
          <div class="cert-border-inner"></div>

          <!-- 4 Luxury Corner Ornaments (Art-Deco Gold Filigree SVG) -->
          <div class="cert-corner cert-corner-tl" aria-hidden="true">
            <svg viewBox="0 0 80 80" width="70" height="70" fill="none">
              <path d="M4 76V24C4 12.9543 12.9543 4 24 4H76" stroke="#c5a059" stroke-width="2.5" stroke-linecap="round"/>
              <path d="M12 76V26C12 18.268 18.268 12 26 12H76" stroke="#c5a059" stroke-width="1" stroke-dasharray="2 3"/>
              <path d="M20 76V28C20 23.5817 23.5817 20 28 20H76" stroke="#c5a059" stroke-width="1"/>
              <circle cx="28" cy="28" r="3.5" fill="#c5a059"/>
              <path d="M8 8L28 28" stroke="#c5a059" stroke-width="1.5"/>
            </svg>
          </div>
          <div class="cert-corner cert-corner-tr" aria-hidden="true">
            <svg viewBox="0 0 80 80" width="70" height="70" fill="none">
              <path d="M76 76V24C76 12.9543 67.0457 4 56 4H4" stroke="#c5a059" stroke-width="2.5" stroke-linecap="round"/>
              <path d="M68 76V26C68 18.268 61.732 12 54 12H4" stroke="#c5a059" stroke-width="1" stroke-dasharray="2 3"/>
              <path d="M60 76V28C60 23.5817 56.4183 20 52 20H4" stroke="#c5a059" stroke-width="1"/>
              <circle cx="52" cy="28" r="3.5" fill="#c5a059"/>
              <path d="M72 8L52 28" stroke="#c5a059" stroke-width="1.5"/>
            </svg>
          </div>
          <div class="cert-corner cert-corner-bl" aria-hidden="true">
            <svg viewBox="0 0 80 80" width="70" height="70" fill="none">
              <path d="M4 4V56C4 67.0457 12.9543 76 24 76H76" stroke="#c5a059" stroke-width="2.5" stroke-linecap="round"/>
              <path d="M12 4V54C12 61.732 18.268 68 26 68H76" stroke="#c5a059" stroke-width="1" stroke-dasharray="2 3"/>
              <path d="M20 4V52C20 56.4183 23.5817 60 28 60H76" stroke="#c5a059" stroke-width="1"/>
              <circle cx="28" cy="52" r="3.5" fill="#c5a059"/>
              <path d="M8 72L28 52" stroke="#c5a059" stroke-width="1.5"/>
            </svg>
          </div>
          <div class="cert-corner cert-corner-br" aria-hidden="true">
            <svg viewBox="0 0 80 80" width="70" height="70" fill="none">
              <path d="M76 4V56C76 67.0457 67.0457 76 56 76H4" stroke="#c5a059" stroke-width="2.5" stroke-linecap="round"/>
              <path d="M68 4V54C68 61.732 61.732 68 54 68H4" stroke="#c5a059" stroke-width="1" stroke-dasharray="2 3"/>
              <path d="M60 4V52C60 56.4183 56.4183 60 52 60H4" stroke="#c5a059" stroke-width="1"/>
              <circle cx="52" cy="52" r="3.5" fill="#c5a059"/>
              <path d="M72 72L52 52" stroke="#c5a059" stroke-width="1.5"/>
            </svg>
          </div>

          <!-- Certificate Inner Flow -->
          <div class="cert-body">
            
            <!-- Top Header: Official QuizSphere Brand Logo -->
            <div class="cert-header-block">
              <div class="cert-brand-logo-wrap">
                <img class="brand-logo-cert" id="cert-brand-logo" src="<?php echo e($logoDataUri); ?>" alt="QuizSphere" crossorigin="anonymous">
              </div>
            </div>

            <!-- Certificate Title -->
            <div class="cert-title-block">
              <h1 class="cert-title">CERTIFICATE OF ACHIEVEMENT</h1>
              <div class="cert-separator-ornament">
                <span class="cert-sep-bar"></span>
                <span class="cert-sep-gem">◆</span>
                <span class="cert-sep-bar"></span>
              </div>
            </div>

            <!-- Recipient Conferred Presentation -->
            <div class="cert-recipient-block">
              <div class="cert-sub">THIS CREDENTIAL IS PROUDLY PRESENTED TO</div>
              <div class="cert-name" id="cert-name">—</div>
              <div class="cert-name-gold-line"></div>
            </div>

            <!-- Accomplishment Description & Quiz Title -->
            <div class="cert-accomplishment-block">
              <p class="cert-prose">for demonstrating intellectual rigor, critical thinking, and verified mastery in the AI-curated curriculum of</p>
              <div class="cert-accomplishment" id="cert-quiz">—</div>
            </div>

            <!-- 2 Stat Badges Row (Score & Assessment Level) -->
            <div class="cert-stats-badges">
              <div class="cert-stat-item">
                <span class="cert-stat-label">SCORE</span>
                <span class="cert-stat-value" id="cert-score">—</span>
              </div>
              <div class="cert-stat-item cert-stat-highlight">
                <span class="cert-stat-label">ASSESSMENT LEVEL</span>
                <span class="cert-stat-value" id="cert-level">HIGHEST HONORS</span>
              </div>
            </div>

            <!-- Footer: Issuance Metadata, Official Rosette Seal, QR Verification -->
            <div class="cert-footer-row">
              
              <!-- Left: Issuance & Identification Metadata -->
              <div class="cert-footer-side cert-footer-left">
                <div class="cert-meta-container">
                  <div class="cert-meta-group">
                    <span class="cert-meta-label">ISSUED ON</span>
                    <strong class="cert-meta-val" id="cert-date">—</strong>
                  </div>
                  <div class="cert-meta-group mt-2">
                    <span class="cert-meta-label">CERTIFICATE ID</span>
                    <code class="cert-meta-id" id="cert-id">—</code>
                  </div>
                  <div class="cert-meta-registry mt-2">
                    <i class="bi bi-shield-check text-success me-1"></i>Official Institutional Registry
                  </div>
                </div>
              </div>

              <!-- Center: QuizSphere Official Medallion Rosette Seal -->
              <div class="cert-footer-center">
                <div class="cert-seal-badge-wrap">
                  <div class="cert-gold-seal">
                    <svg viewBox="0 0 120 120" width="114" height="114" fill="none" class="cert-seal-svg">
                      <defs>
                        <linearGradient id="goldRadial" x1="0" y1="0" x2="1" y2="1">
                          <stop offset="0%" stop-color="#fffbeb"/>
                          <stop offset="25%" stop-color="#fde68a"/>
                          <stop offset="50%" stop-color="#d4af37"/>
                          <stop offset="75%" stop-color="#b48220"/>
                          <stop offset="100%" stop-color="#78350f"/>
                        </linearGradient>
                        <linearGradient id="goldRim" x1="0" y1="0" x2="0" y2="1">
                          <stop offset="0%" stop-color="#fef08a"/>
                          <stop offset="50%" stop-color="#d4af37"/>
                          <stop offset="100%" stop-color="#92400e"/>
                        </linearGradient>
                      </defs>
                      <circle cx="60" cy="60" r="56" fill="url(#goldRim)" stroke="#78350f" stroke-width="1"/>
                      <circle cx="60" cy="60" r="50" fill="#fdfbf7" stroke="url(#goldRadial)" stroke-width="2"/>
                      <circle cx="60" cy="60" r="46" fill="none" stroke="#d4af37" stroke-width="0.75" stroke-dasharray="2.5 2"/>
                      <path d="M60 26L63 35H72.5L64.8 40.5L67.8 49.5L60 44L52.2 49.5L55.2 40.5L47.5 35H57L60 26Z" fill="url(#goldRadial)" stroke="#92400e" stroke-width="0.5"/>
                      <circle cx="60" cy="60" r="41" fill="none" stroke="#e0be77" stroke-width="0.5"/>
                    </svg>
                    <div class="cert-seal-caption">
                      <span class="seal-txt-1">QUIZSPHERE</span>
                      <span class="seal-txt-2">OFFICIAL SEAL</span>
                      <span class="seal-txt-3">VERIFIED</span>
                    </div>
                    <!-- Two downward-draping silk ribbons -->
                    <div class="cert-ribbons-wrapper">
                      <div class="cert-ribbon cert-ribbon-l"></div>
                      <div class="cert-ribbon cert-ribbon-r"></div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Right: High-Contrast Scannable QR Code & Instructions -->
              <div class="cert-footer-side cert-footer-right">
                <div class="cert-qr-container">
                  <div class="cert-qr-frame">
                    <div id="cert-qr"></div>
                  </div>
                  <div class="cert-qr-scan-hint">
                    <i class="bi bi-qr-code-scan me-1 text-brand"></i>Scan to Verify
                  </div>
                  <div class="cert-qr-subtext">
                    Public Verification Ledger
                  </div>
                </div>
              </div>

            </div>

          </div><!-- /.cert-body -->

        </div><!-- /.certificate-sheet -->

      </div><!-- /.cert-canvas-container -->
    </div><!-- /#cert-panel -->

  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="<?php echo url('/assets/js/app.js'); ?>"></script>
</body>
</html>

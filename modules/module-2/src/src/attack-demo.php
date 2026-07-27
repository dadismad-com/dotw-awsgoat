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

    #attack-demo-panel .adp-header {
        padding: 18px 20px;
        background: #b91c1c;
        position: sticky;
        top: 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    #attack-demo-panel .adp-header h2 { margin: 0; font-size: 17px; }
    #attack-demo-panel .adp-close {
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
    #attack-demo-panel .adp-card h3 {
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
    #attack-demo-panel p.adp-desc {
        font-size: 13px;
        line-height: 1.45;
        color: #cfcfcf;
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
    #attack-demo-panel .adp-btn.secondary {
        background: #374151;
    }
    #attack-demo-panel .adp-btn.secondary:hover { background: #1f2937; }
    #attack-demo-panel .adp-note {
        font-size: 12px;
        color: #9ca3af;
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
    .adp-highlight-row {
        outline: 3px solid #dc2626 !important;
        animation: adp-pulse 1.6s ease-in-out 2;
    }
    @keyframes adp-pulse {
        0%, 100% { background-color: transparent; }
        50% { background-color: rgba(220, 38, 38, 0.15); }
    }
</style>

<button id="attack-demo-toggle" type="button" onclick="AttackDemo.togglePanel()">⚔ Attack Demos</button>

<div id="attack-demo-panel">
    <div class="adp-header">
        <h2>⚔ AWSGoat Attack Demos</h2>
        <button class="adp-close" type="button" onclick="AttackDemo.togglePanel()">&times;</button>
    </div>

    <div class="adp-card">
        <span class="adp-badge live">LIVE DEMO</span>
        <h3>1. SQL Injection &mdash; Login Bypass</h3>
        <p class="adp-desc">
            The login query builds SQL by directly concatenating the <code>email</code> field.
            Ending the input with <code>#</code> comments out the password check, and
            <code>LIMIT</code>/<code>ORDER BY</code> control which account you're logged in as.
        </p>
        <code class="adp-payload">email = ' or '1'='1'#</code>
        <div>
            <button class="adp-btn" type="button" onclick="AttackDemo.goRunSqli('bypass')">Run: Bypass Login</button>
            <button class="adp-btn" type="button" onclick="AttackDemo.goRunSqli('manager')">Run: Login as Manager</button>
            <button class="adp-btn" type="button" onclick="AttackDemo.goRunSqli('admin')">Run: Login as Admin</button>
        </div>
        <p class="adp-note">Safe & reversible &mdash; it only signs you in as a demo account. Use Logout to reset.</p>
    </div>

    <div class="adp-card">
        <span class="adp-badge live">LIVE DEMO</span>
        <h3>2. Unrestricted File Upload</h3>
        <p class="adp-desc">
            The Manager/Admin payslip &amp; reimbursement uploads never check file type, unlike the
            Normal User upload which only allows PDF/JPEG/PNG. This demo uploads a harmless
            <code>.php</code> proof-of-concept (no shell, no network access) to prove the server
            will happily store and execute server-side code here.
        </p>
        <?php if ($attackDemoPage === 'admin-payslips' || $attackDemoPage === 'admin-reimbursment' || $attackDemoPage === 'superadmin-payslips') { ?>
            <button class="adp-btn" type="button" onclick="AttackDemo.runUploadDemo()">Run: Upload PHP proof-of-concept</button>
            <p class="adp-note">After it uploads, click "View File" on the highlighted row to watch it execute.</p>
        <?php } elseif ($attackDemoRole === 1) { ?>
            <a class="adp-btn" href="<?php echo htmlspecialchars($attackDemoBase); ?>admin/payslips.php">Go to Manager Payslips &rarr;</a>
        <?php } elseif ($attackDemoRole === 2) { ?>
            <a class="adp-btn" href="<?php echo htmlspecialchars($attackDemoBase); ?>superadmin/payslips.php">Go to Admin Payslips &rarr;</a>
        <?php } else { ?>
            <p class="adp-note">Log in as a Manager or Admin first (use the SQL injection demo above), then come back to this menu on their Payslips page.</p>
        <?php } ?>
        <details>
            <summary>Where does this attack lead next? (not run here)</summary>
            <ol>
                <li>A real attacker uploads a PHP <em>reverse shell</em> instead of a harmless file.</li>
                <li>Opening "View File" executes it, handing the attacker a shell on the ECS container.</li>
                <li>From there: container breakout via a misconfigured <code>sudo</code> rule + the
                    <code>SYS_PTRACE</code> capability, then reading the EC2/ECS instance metadata
                    service for real IAM credentials.</li>
            </ol>
            <p class="adp-note">This chain grants real, persistent access to your AWS account, so it is intentionally never executed from this button. See "ECS Container Breakout" below.</p>
        </details>
    </div>

    <div class="adp-card">
        <span class="adp-badge explainer">EXPLAINER ONLY</span>
        <h3>3. ECS Container Breakout</h3>
        <p class="adp-desc">
            Not executed here: this needs an actual reverse shell and a second attacker-controlled
            EC2 instance, and it permanently exposes the ECS host's IAM credentials.
        </p>
        <details>
            <summary>How it works</summary>
            <ol>
                <li>From the reverse shell (see attack #2), the attacker is a low-privilege
                    <code>www-data</code> user with no access to <code>/root</code> or the
                    instance metadata endpoint.</li>
                <li><code>sudo -l</code> reveals they can run <code>vim</code> as root with no
                    password &mdash; vim's <code>:! /bin/sh</code> command spawns a root shell.</li>
                <li>As root, the container's <code>SYS_PTRACE</code> capability lets it inject
                    shellcode into a root-owned process on the <em>host</em> instance, breaking
                    out of the container entirely.</li>
                <li>From the host, <code>curl http://169.254.169.254/latest/meta-data/iam/security-credentials/&lt;role&gt;</code>
                    returns real, temporary AWS credentials for the EC2 instance role.</li>
            </ol>
        </details>
    </div>

    <div class="adp-card">
        <span class="adp-badge explainer">EXPLAINER ONLY</span>
        <h3>4. IAM Privilege Escalation</h3>
        <p class="adp-desc">
            Not executed here: this would create a real, permanent IAM admin user on the live
            AWS account for anyone who clicked the button.
        </p>
        <details>
            <summary>How it works</summary>
            <ol>
                <li>Using the instance credentials from attack #3, the attacker lists their own
                    role's policies and finds broad IAM permissions, but a permissions boundary
                    blocks direct privilege escalation (e.g. <code>iam:CreateUser</code> is denied).</li>
                <li>The boundary still allows <code>iam:PassRole</code>, <code>ec2:RunInstances</code>
                    and <code>ssm:SendCommand</code>. The attacker finds another IAM role with an
                    unrestricted admin policy attached.</li>
                <li>They launch a new EC2 instance and pass it that more-privileged role, then use
                    SSM to run a command on it and read its instance-metadata credentials.</li>
                <li>With those unrestricted credentials, they create a new IAM user, attach
                    <code>AdministratorAccess</code>, and now own the AWS account.</li>
            </ol>
        </details>
    </div>
</div>

<div id="attack-demo-toast"></div>

<script>
    window.AttackDemo = (function () {
        var ctx = {
            base: <?php echo json_encode($attackDemoBase); ?>,
            page: <?php echo json_encode($attackDemoPage); ?>,
        };

        var SQLI_PAYLOADS = {
            bypass: "' or '1'='1'#",
            manager: "' or '1'='1' LIMIT 3#",
            admin: "' or '1'='1' ORDER BY id DESC#",
        };

        var POC_PHP_SOURCE =
            "<" + "?php\n" +
            "echo '<h1>Unrestricted File Upload PoC</h1>';\n" +
            "echo '<p>This .php file was accepted and executed because this upload endpoint never checks the file type or extension.</p>';\n" +
            "?" + ">\n";

        function togglePanel() {
            document.getElementById('attack-demo-panel').classList.toggle('open');
        }

        function runSqliDemo(kind) {
            var form = document.querySelector('form.login-email') || document.querySelector('input[name="email"]').closest('form');
            var email = form.querySelector('input[name="email"]');
            var password = form.querySelector('input[name="password"]');
            email.type = 'text';
            email.value = SQLI_PAYLOADS[kind];
            password.value = 'attack-demo';
            form.submit();
        }

        function goRunSqli(kind) {
            if (ctx.page === 'login') {
                runSqliDemo(kind);
            } else {
                window.location.href = ctx.base + 'login.php?attack_demo=' + kind;
            }
        }

        function buildPocFile() {
            return new File([POC_PHP_SOURCE], 'attack-demo-poc.php', { type: 'application/x-php' });
        }

        function runUploadDemo() {
            var formSelector = null;
            if (ctx.page === 'admin-payslips' || ctx.page === 'superadmin-payslips') {
                formSelector = '#uploadpayslip form';
            } else if (ctx.page === 'admin-reimbursment') {
                formSelector = '#applyreimbursment form';
            }
            if (!formSelector) return;

            var form = document.querySelector(formSelector);
            if (!form) return;

            var fileInput = form.querySelector('input[type="file"]');
            if (window.DataTransfer && fileInput) {
                var dt = new DataTransfer();
                dt.items.add(buildPocFile());
                fileInput.files = dt.files;
            }

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

            form.submit();
        }

        function showToast(message) {
            var toast = document.getElementById('attack-demo-toast');
            toast.textContent = message;
            toast.style.display = 'block';
            setTimeout(function () { toast.style.display = 'none'; }, 8000);
        }

        function handleIncomingDemoParams() {
            var params = new URLSearchParams(window.location.search);

            if (ctx.page === 'login') {
                var kind = params.get('attack_demo');
                if (kind && SQLI_PAYLOADS[kind]) {
                    document.getElementById('attack-demo-panel').classList.add('open');
                    setTimeout(function () { runSqliDemo(kind); }, 400);
                }
            }

            if (params.get('demo') === 'upload_done') {
                var row = document.querySelector('.tablebodyrows tr');
                if (row) {
                    row.classList.add('adp-highlight-row');
                    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                showToast('Upload succeeded: the .php proof-of-concept was accepted with no type check. Click "View File" on the highlighted row above to watch it execute.');
            }
        }

        document.addEventListener('DOMContentLoaded', handleIncomingDemoParams);

        return {
            togglePanel: togglePanel,
            goRunSqli: goRunSqli,
            runUploadDemo: runUploadDemo,
        };
    })();
</script>

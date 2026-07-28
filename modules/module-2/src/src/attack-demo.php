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
        gap: 10px;
    }
    #attack-demo-panel .adp-header h2 { margin: 0; font-size: 17px; }
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
    #attack-demo-toast a { color: #04220f; }
</style>

<button id="attack-demo-toggle" type="button" onclick="AttackDemo.togglePanel()" data-i18n="toggle.label">⚔ Attack Demos</button>

<div id="attack-demo-panel">
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
        <code class="adp-payload">email = ' or '1'='1'#</code>
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
        <span class="adp-badge explainer" data-i18n="badge.explainer">EXPLAINER ONLY</span>
        <h3 data-i18n="c3.title">3. ECS Container Breakout</h3>
        <p class="adp-desc" data-i18n="c3.desc">
            Not executed here: this needs an actual reverse shell and a second attacker-controlled
            EC2 instance, and it permanently exposes the ECS host's IAM credentials.
        </p>
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
                'panel.title': '⚔ AWSGoat Attack Demos',
                'badge.live': 'LIVE DEMO',
                'badge.explainer': 'EXPLAINER ONLY',
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
                'c3.desc': 'Not executed here: this needs an actual reverse shell and a second attacker-controlled EC2 instance, and it permanently exposes the ECS host\'s IAM credentials.',
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
            },
            es: {
                'toggle.label': '⚔ Demos de Ataques',
                'panel.title': '⚔ Demos de Ataques AWSGoat',
                'badge.live': 'DEMO EN VIVO',
                'badge.explainer': 'SOLO EXPLICACI\u00d3N',
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
                'c3.desc': 'No se ejecuta aqu\u00ed: esto requiere una reverse shell real y una segunda instancia EC2 controlada por el atacante, y expone permanentemente las credenciales IAM del host ECS.',
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

        function showToast(html) {
            var toast = document.getElementById('attack-demo-toast');
            toast.innerHTML = html;
            toast.style.display = 'block';
            setTimeout(function () { toast.style.display = 'none'; }, 10000);
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
                var filePath = params.get('file');
                var html = t('toast.uploadSuccess');
                if (filePath) {
                    html += ' <a href="' + filePath + '" target="_blank">' + t('toast.viewFileLink') + ' \u2192</a>';
                }
                showToast(html);
            }
        }

        document.addEventListener('DOMContentLoaded', handleIncomingDemoParams);

        initLanguage();

        return {
            togglePanel: togglePanel,
            goRunSqli: goRunSqli,
            runUploadDemo: runUploadDemo,
            setLanguage: setLanguage,
        };
    })();
</script>

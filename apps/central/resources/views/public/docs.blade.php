<x-site-layout title="Documentation">
    <section class="section-tight" style="padding-top:56px">
        <div class="container">
            <div class="grid" style="grid-template-columns:minmax(200px,240px) minmax(0,1fr);gap:48px;align-items:start">
                <aside style="position:sticky;top:88px">
                    <div class="eyebrow mb-1">On this page</div>
                    <nav class="stack" style="--stack:2px">
                        <a class="nav-item" style="color:var(--ink-2)" href="#status"><x-icon name="activity" :size="15" /> Project status</a>
                        <a class="nav-item" style="color:var(--ink-2)" href="#requirements"><x-icon name="check" :size="15" /> Requirements</a>
                        <a class="nav-item" style="color:var(--ink-2)" href="#install"><x-icon name="terminal" :size="15" /> Installation</a>
                        <a class="nav-item" style="color:var(--ink-2)" href="#ports"><x-icon name="globe" :size="15" /> Ports &amp; URLs</a>
                        <a class="nav-item" style="color:var(--ink-2)" href="#compatibility"><x-icon name="layers" :size="15" /> Compatibility</a>
                        <a class="nav-item" style="color:var(--ink-2)" href="#licensing"><x-icon name="key" :size="15" /> Licensing behaviour</a>
                        <a class="nav-item" style="color:var(--ink-2)" href="#recovery"><x-icon name="refresh" :size="15" /> Repair &amp; rollback</a>
                    </nav>
                </aside>

                <div class="prose">
                    <h1 class="h1" style="font-size:2rem">Documentation</h1>
                    <p class="lead mt-1">Everything needed to go from a clean VPS to a live hosted site. Written to be read before you run anything as root.</p>

                    <h2 id="status">Project status</h2>
                    <div class="alert alert-warn">
                        <x-icon name="alert" :size="17" style="flex:none;margin-top:1px" />
                        <div>
                            <strong>This is an early build.</strong> The central commercial platform (accounts, licences, plans, staff console)
                            is functional. The VPS installer, server agent and hosting panels are in development (Phases 3–6).
                            Commands on this page describe the intended interface and are not yet executable.
                        </div>
                    </div>

                    <h2 id="requirements">Requirements</h2>
                    <table>
                        <thead><tr><th>Item</th><th>Minimum</th><th>Recommended</th></tr></thead>
                        <tbody>
                            <tr><td>OS</td><td>Ubuntu 24.04 LTS</td><td>Ubuntu 24.04 or 26.04 LTS, x86_64</td></tr>
                            <tr><td>CPU</td><td>1 vCPU</td><td>2+ vCPU</td></tr>
                            <tr><td>RAM</td><td>2 GB</td><td>4 GB+ (8 GB with mail)</td></tr>
                            <tr><td>Disk</td><td>20 GB SSD</td><td>40 GB+ SSD</td></tr>
                            <tr><td>Access</td><td colspan="2">Root or passwordless sudo</td></tr>
                            <tr><td>Network</td><td colspan="2">Outbound HTTPS; ports 80/443 free. Port 25 for mail (provider-dependent)</td></tr>
                        </tbody>
                    </table>
                    <p>The installer refuses to continue on: cPanel/Plesk/DirectAdmin/CyberPanel/Webuzo already present, existing MySQL/MariaDB with data, OpenVZ 6, or a kernel without cgroup v2.</p>

                    <h2 id="install">Installation</h2>
                    <p>One command, on a clean server. The bootstrap script is short by design: it verifies a pinned checksum and an Ed25519 signature, then hands over to the signed installer binary.</p>

                    <div class="codeblock" style="margin:16px 0">
                        <span class="comment"># 1. Download the bootstrap — inspect it before running</span><br>
                        <span class="prompt">$</span> curl -fsSL https://downloads.example.com/install.sh -o install.sh<br>
                        <span class="prompt">$</span> less install.sh<br><br>
                        <span class="comment"># 2. Run the installer with your licence key</span><br>
                        <span class="prompt">$</span> sudo bash install.sh --license=<span data-copy-source>ATLS-XXXX-XXXX-XXXX-XXXX</span><br><br>
                        <span class="comment"># 3. Optional: inspect the plan first, change nothing</span><br>
                        <span class="prompt">$</span> sudo atlas-install --dry-run
                        <button type="button" class="copy-btn" data-copy="sibling">copy</button>
                    </div>

                    <p>The installer is resumable. If a step fails, the state file at <code>/var/lib/atlas/install-state.json</code> lets you continue with:</p>
                    <div class="codeblock" style="margin:14px 0">
                        <span class="prompt">$</span> sudo atlas-install --resume
                    </div>

                    <h2 id="ports">Ports &amp; URLs</h2>
                    <table>
                        <thead><tr><th>Surface</th><th>Default</th><th>Notes</th></tr></thead>
                        <tbody>
                            <tr><td>Admin panel</td><td><code>https://host:2087</code></td><td>Server owner; TOTP required</td></tr>
                            <tr><td>Hosting panel</td><td><code>https://host:2083</code></td><td>One per hosting account</td></tr>
                            <tr><td>Webmail</td><td><code>https://host:2096</code></td><td>Only when mail is installed</td></tr>
                            <tr><td>HTTP/HTTPS</td><td><code>80 / 443</code></td><td>Customer websites</td></tr>
                            <tr><td>DNS</td><td><code>53/udp, 53/tcp</code></td><td>Authority server</td></tr>
                            <tr><td>Mail</td><td><code>25, 587, 465, 993, 995</code></td><td>Optional module</td></tr>
                        </tbody>
                    </table>
                    <p>All ports are configurable at install time and from the admin panel afterwards.</p>

                    <h2 id="compatibility">Compatibility</h2>
                    <p>Support is claimed only after the full stack has been tested — web server, PHP versions, database, DNS, mail, firewall, SSL, agent, backup and upgrade. Anything else is marked as "evaluated later", not "supported".</p>

                    <h2 id="licensing">Licensing behaviour</h2>
                    <ul>
                        <li><strong>Activation</strong> binds your licence to a server fingerprint (machine ID, DMI, primary disk).</li>
                        <li><strong>Verification</strong> happens daily in the background — never on a page request.</li>
                        <li><strong>Offline grace:</strong> if our licence server is unreachable, the panel keeps full operation for 14 days.</li>
                        <li><strong>After grace:</strong> admin becomes read-only. Websites, mail, DNS, cron and backups continue. Nothing is deleted, ever.</li>
                        <li><strong>Transfers</strong> are self-service: deactivate, then activate the new server.</li>
                    </ul>

                    <h2 id="recovery">Repair &amp; rollback</h2>
                    <div class="codeblock" style="margin:14px 0">
                        <span class="comment"># health matrix — 40 checks, machine-readable</span><br>
                        <span class="prompt">$</span> sudo atlas-install verify --json<br><br>
                        <span class="comment"># re-apply a single failed task (idempotent)</span><br>
                        <span class="prompt">$</span> sudo atlas-install repair --task=php.pool.apply<br><br>
                        <span class="comment"># roll back one minor version using the pre-update snapshot</span><br>
                        <span class="prompt">$</span> sudo atlas-install rollback --to=0.4.2<br><br>
                        <span class="comment"># redacted support bundle for tickets</span><br>
                        <span class="prompt">$</span> sudo atlas-install support-bundle
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-site-layout>

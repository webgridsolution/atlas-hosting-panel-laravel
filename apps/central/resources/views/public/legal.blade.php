<x-site-layout title="Legal">
    <section class="section-tight" style="padding-top:56px">
        <div class="container" style="max-width:860px">
            <span class="eyebrow">Legal</span>
            <h1 class="h1 mt-1" style="font-size:2rem">Terms, privacy and refunds</h1>

            <div class="alert alert-warn mt-3">
                <x-icon name="alert" :size="17" style="flex:none;margin-top:1px" />
                <div>
                    <strong>Draft.</strong> These summaries are engineering placeholders. Before selling anything, counsel must review
                    the final terms, the GST/tax treatment and the refund policy against Indian consumer law and, if you sell abroad, GDPR.
                </div>
            </div>

            <div class="prose mt-3">
                <h2 id="terms">Terms of service — key points</h2>
                <ul>
                    <li><strong>Licence, not hosting.</strong> We license software. You operate the server, and you are responsible for its content, backups and security.</li>
                    <li><strong>One licence, one server.</strong> Each licence activates on a single fingerprint. Running multiple servers on one licence breaches the agreement.</li>
                    <li><strong>No affiliation.</strong> This product is independent and not endorsed by or affiliated with cPanel, WHM, WebPros, Plesk, DirectAdmin or Webuzo. Third-party marks belong to their owners.</li>
                    <li><strong>Acceptable use.</strong> No hosting of phishing, malware, CSAM or other illegal content on infrastructure operated with our software where we are legally compelled to act.</li>
                    <li><strong>Support scope.</strong> Support covers the panel software. It does not cover your hardware, network, or third-party applications installed on your server.</li>
                    <li><strong>Liability.</strong> Software is provided as-is; you are advised to keep independent backups. Any liability cap should be tied to fees paid in the preceding 12 months.</li>
                </ul>

                <h2 id="privacy">Privacy — what the central platform stores</h2>
                <table>
                    <thead><tr><th>Data</th><th>Purpose</th><th>Notes</th></tr></thead>
                    <tbody>
                        <tr><td>Name, email, company</td><td>Account, licensing, invoices</td><td>Stored in the central database</td></tr>
                        <tr><td>GSTIN / tax ID</td><td>Invoicing</td><td>Optional, only if you are a business</td></tr>
                        <tr><td>Payment metadata</td><td>Billing history and disputes</td><td>Card data is handled by Razorpay only — never stored by us</td></tr>
                        <tr><td>Server fingerprint hash</td><td>Licence binding</td><td>One-way hash; raw hardware identifiers are not transmitted</td></tr>
                        <tr><td>Panel telemetry counters</td><td>Licence limits and support</td><td>Counts only (accounts, domains), never customer site data</td></tr>
                    </tbody>
                </table>
                <p><strong>Not stored centrally:</strong> website files, mailbox contents, database contents, or database credentials from your server.</p>

                <h2 id="refunds">Refunds and cancellation</h2>
                <ul>
                    <li>Cancel any time; access continues to the end of the paid period.</li>
                    <li>Full refund within 7 days if the licence was never activated on a server.</li>
                    <li>After activation: 48 hours, minus payment processing fees, if the installer could not complete on a supported OS (support must be given a chance to diagnose).</li>
                    <li>Refunds are processed to the original payment method through Razorpay, with a credit note against the GST invoice.</li>
                    <li>When a subscription lapses, the panel goes read-only after the grace period. <strong>Customer data is never deleted by a billing event.</strong></li>
                </ul>
            </div>
        </div>
    </section>
</x-site-layout>

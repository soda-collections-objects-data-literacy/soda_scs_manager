# Drupal.org project page copy

Paste into the project description on drupal.org. Short summary (Edit summary): ≤200 characters.

## Short summary

Self-service portal for the SODa Semantic Co-Working Space: provision WissKI, Nextcloud, JupyterHub, databases, and triplestores per project via Keycloak SSO—without needing deep Drupal or DevOps knowledge.

## Project page body (HTML)

```html
<p>SODa SCS Manager turns Drupal into a <strong>self-service control panel</strong> for the <a href="https://sammlungen.io">SODa</a> Semantic Co-Working Space (SCS). Researchers and collection staff can create projects, invite collaborators, and spin up research apps—WissKI environments, Nextcloud file spaces, JupyterHub notebooks, SQL databases, OpenGDB triplestores, and WebProtégé—without installing software locally or learning Docker.</p>

<p>If you are new to Drupal: you mainly use a dashboard and wizards. Drupal hosts the UI, users, and configuration; the module talks to Keycloak (login), Portainer/Docker (containers), MariaDB, Nextcloud, and related services in the background.</p>

<p><strong>What solution does this module provide?</strong> A project-centric platform to provision, share, monitor, and tear down SCS applications and resources with SSO, membership sync, snapshots, and connected-account management—so cultural-heritage and research teams get FAIR-oriented tooling without a dedicated ops person for every instance.</p>

<p><strong>Try it / run it:</strong> Production/reference deployment at <a href="https://scs.sammlungen.io">scs.sammlungen.io</a>. Full stack (Drupal + Keycloak, Nextcloud, JupyterHub, OpenGDB, Traefik, etc.): <a href="https://github.com/soda-collections-objects-data-literacy/soda_scs_manager_deployment">soda_scs_manager_deployment</a>. For most adopters, that Compose environment is the supported way to run SODa SCS Manager—not a plain core Drupal site alone.</p>

<h3 id="module-project--features">Features</h3>

<p><strong>Basic functionality</strong></p>
<ul>
  <li><strong>Project-centric dashboard</strong> — central services (e.g. Nextcloud, Jupyter, WebProtégé) plus “Your projects” as cards (status, members); create apps from the project page.</li>
  <li><strong>Stacks (environments)</strong> — e.g. WissKI Environment (Drupal/WissKI + DB + triplestore), JupyterHub Environment, Nextcloud Environment (files, OnlyOffice, SCS-Share).</li>
  <li><strong>Components (resources/accounts/instances)</strong> — SQL databases (phpMyAdmin), OpenGDB triplestores, WebProtégé accounts, plain WissKI instances, etc.</li>
  <li><strong>Identity &amp; access</strong> — OpenID Connect / Keycloak SSO; project membership sync to groups; service keys and connected accounts.</li>
  <li><strong>Lifecycle</strong> — create/delete provisioning, health/online status, snapshots for backup/restore, secure logging (credential redaction).</li>
  <li><strong>Collaboration</strong> — projects with owners/members; Nextcloud Team Folders tied to projects; shared access across apps.</li>
</ul>

<p><strong>When / why use it</strong></p>
<ul>
  <li>You run (or plan) a multi-tenant research/coworking platform for collections, digital humanities, or museum data.</li>
  <li>Users need self-service WissKI / Jupyter / Nextcloud / SPARQL / SQL without ticket-driven VM setup.</li>
  <li>You want one Drupal-facing admin UX wired to Keycloak and container orchestration.</li>
</ul>

<p><strong>Typical use cases</strong></p>
<ul>
  <li>A research group creates a project, adds a WissKI Environment with the default data model, and shares the project Team Folder via Nextcloud.</li>
  <li>A curator gets a SQL database and phpMyAdmin access via SSO for structured imports.</li>
  <li>A data scientist starts a JupyterHub environment that can use files from SCS-Share / Nextcloud.</li>
  <li>An ontology editor uses a WebProtégé account managed through the same dashboard and login.</li>
</ul>

<h3 id="module-project--post-installation">Post-Installation</h3>

<p>This module is meant to run inside the <strong>SODa SCS Manager deployment</strong> (or an equivalent stack), not as a drop-in on a bare Drupal site.</p>

<ol>
  <li><strong>Deploy the stack</strong> — follow <a href="https://github.com/soda-collections-objects-data-literacy/soda_scs_manager_deployment">soda_scs_manager_deployment</a> (clone, submodules, <code>.env</code> from <code>example-env</code>, <code>./start.sh</code>, <code>docker compose up -d</code>, then MkDocs post-configuration for Keycloak, Nextcloud, DBMS SSO, etc.).</li>
  <li><strong>Enable the module and theme</strong> — enable <code>soda_scs_manager</code> and its companion theme <code>soda_scs_manager_theme</code>; rebuild theme assets (<code>npm install</code> / <code>npm run build</code> in the theme) if you maintain CSS.</li>
  <li><strong>Configure settings</strong> — go to <em>Administration → Configuration → SODa SCS Manager</em> (<code>/admin/config/soda-scs-manager</code> or the route from the module’s configure link). Set integration endpoints/secrets (Keycloak, Portainer/Docker, Nextcloud, mail/SMTP, security/logging, etc.).</li>
  <li><strong>OpenID Connect</strong> — configure the OpenID Connect client so users log in via Keycloak (no separate “local-only” SCS workflow).</li>
  <li><strong>Permissions &amp; roles</strong> — grant SCS users permissions such as managing own stacks/components/projects and “Manage own connected accounts”; keep admin permissions restricted.</li>
  <li><strong>User-facing entry</strong> — after login, users land on the <strong>Dashboard</strong> (not classic Drupal content types). Create projects there; add applications with “(+)” on a project. Optional: Connected Accounts for Nextcloud mount status and related links.</li>
  <li><strong>Translations</strong> — German UI strings ship as <code>translations/soda_scs_manager.de.po</code>; import via Locale/Drush if needed.</li>
</ol>

<p><strong>Special considerations:</strong> Applications belong to at most one project. Provisioning needs working Docker/Portainer and service APIs. Nextcloud Team Folders and Keycloak groups are synced on project create/update/delete. Post-install hooks in companion services (e.g. Nextcloud) may need one-time <code>occ</code>/manual steps on existing installs—see the deployment docs.</p>

<h3 id="module-project--additional-requirements">Additional Requirements</h3>

<ul>
  <li><strong>Drupal</strong> 10 or 11 (module: <code>^10 | ^11</code>).</li>
  <li><strong>Drupal dependencies</strong> (declared): Language (core), <a href="https://www.drupal.org/project/openid_connect">OpenID Connect</a>, <a href="https://www.drupal.org/project/smtp">SMTP</a>, Field Group, and the <strong>soda_scs_manager_theme</strong> theme.</li>
  <li><strong>Composer-related</strong> (module <code>composer.json</code>): e.g. <code>drupal/openid_connect</code>, <code>drupal/smtp</code>, Tailwind-related packages for the theme stack.</li>
  <li><strong>Infrastructure (required for real use)</strong>: Keycloak (SSO/groups/attributes), Docker + Portainer (or equivalent) for container lifecycle, MariaDB, Traefik (or similar reverse proxy), Nextcloud (+ integration app where used), JupyterHub, OpenGDB/triplestore services, WebProtégé as configured in the deployment. The reference Compose wiring is in <a href="https://github.com/soda-collections-objects-data-literacy/soda_scs_manager_deployment">soda_scs_manager_deployment</a>.</li>
  <li><strong>Host tools for operators</strong>: Docker, Git (submodules), <code>jq</code>, <code>curl</code>; Node/npm on the host for theme builds.</li>
</ul>

<h3 id="module-project--recommended-libraries">Recommended modules/libraries</h3>

<ul>
  <li><strong>soda_scs_manager_theme</strong> — required companion theme (Tailwind/PostCSS UI); styling lives there, not in the module.</li>
  <li><strong>OpenID Connect</strong> + Keycloak realm/clients as documented in the deployment MkDocs.</li>
  <li><strong>SMTP</strong> — reliable outbound mail for invitations/notifications.</li>
  <li>Platform companions from the SODa stacks (Nextcloud <code>scs_manager_integration</code>, WissKI base images/packages, default WissKI data model) as used by the deployment—these extend what the manager can provision.</li>
</ul>

<h3 id="module-project--similar-projects">Similar projects</h3>

<ul>
  <li><strong>Generic Drupal multisite / Aegir / Pantheon-style hosting panels</strong> — manage Drupal sites; they do not provision WissKI+triplestore+Nextcloud+Jupyter as one research coworking product with Keycloak project groups.</li>
  <li><strong>Cloud control panels (Rancher, Portainer UI alone)</strong> — ops-focused container management; SODa SCS Manager is an end-user research dashboard on top of that stack.</li>
  <li><strong>WissKI / Islandora / other DH Drupal distributions</strong> — focus on a single research CMS or repository; this module orchestrates <em>many</em> user-owned environments and shared central services around SODa SCS.</li>
</ul>

<p>Differentiation: project-scoped self-service for a defined SCS toolset (WissKI, Jupyter, Nextcloud, SQL, OpenGDB, WebProtégé) with SSO and membership sync, not a general hosting or single-CMS distribution.</p>

<h3 id="module-project--support">Supporting this Module</h3>

<p>SODa SCS Manager is developed in the context of the <a href="https://sammlungen.io">SODa</a> project (Sammlungen – Objekte – Datenkompetenzen). For collaboration, issues, and contributions, use the project’s GitHub organisation <a href="https://github.com/soda-collections-objects-data-literacy">soda-collections-objects-data-literacy</a> and the deployment/module repositories linked there. (Add Patreon/OpenCollective links here if you create them later.)</p>

<h3 id="module-project--community-documentation">Community Documentation</h3>

<ul>
  <li><strong>Live platform:</strong> <a href="https://scs.sammlungen.io">https://scs.sammlungen.io</a></li>
  <li><strong>Deployment + operator docs (MkDocs):</strong> <a href="https://github.com/soda-collections-objects-data-literacy/soda_scs_manager_deployment">https://github.com/soda-collections-objects-data-literacy/soda_scs_manager_deployment</a> — clone and run MkDocs Material as described in the README to browse the full setup/post-configuration guide.</li>
  <li><strong>SODa / SCS background:</strong> <a href="https://sammlungen.io">https://sammlungen.io</a> · conceptual record: <a href="https://zenodo.org/records/14627710">SODa Semantic Co-Working Space (Zenodo)</a></li>
  <li><strong>Module README</strong> (hooks, content sync, secure logging, theming notes) in the module repository.</li>
</ul>

<h3>Additional information</h3>

<ul>
  <li>Licensed under <strong>GPL-2.0-or-later</strong> (GPL-2.0+), matching Drupal core.</li>
  <li>UI language: English with German translations maintained in-module.</li>
  <li>Security: prefer the module’s secure logging helpers; never leave DB passwords/app secrets in plain logs. Nextcloud app passwords are handled as encrypted Drupal user data in current versions—follow CHANGELOG/migration notes when upgrading.</li>
  <li>Not a “content type” module for site builders: expect custom entities (projects, stacks, components, snapshots, service keys) and a dashboard-first UX.</li>
</ul>
```

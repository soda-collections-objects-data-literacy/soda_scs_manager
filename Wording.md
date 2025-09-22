# SCS Wording

## Environment
### Definition
A dedicated, isolated deployment that bundles multiple dependent services/resources that work together as one cohesive system.

### Examples
- WissKI Environment (WissKI/Drupal container + Database + Triplestore working together).
- JupyterHub Environment (JupyterHub container + user spawning + storage).

### Typical action
Provision environment.

## Instance
### Definition
A dedicated container running a single application without external dependencies.

### Examples
- WissKI/Drupal Instance (standalone container, no database/triplestore connection).
- Standalone application containers.

### Typical action
Deploy instance.

## Account
### Definition
A user account in a shared, multi-tenant hosted service.

### Examples
Nextcloud account, WebProtégé account.

### Typical action
Create account.

## Resource
### Definition
A shared service allocation you get "as-is" inside an existing platform/service.

### Examples
Storage volume (disk space), SQL database/schema in a DBMS, RDF4J triplestore repository.

### Typical action
Allocate resource.

## How this maps to your current terms
- "Stack" → Environment (dependent services working together) OR Instance (single container).
- "Component" → Application with subtypes:
  - Component (Account) → Account.
  - Component (Resource) → Resource.
  - Component (Instance) → Instance.

## Short mapping of your examples
- Filesystem, Database, Triplestore → Resources (Managed resource).
- WissKI Environment → Environment (Dependent services).
- WissKI/Drupal Instance → Instance (Single container).
- JupyterHub → Environment (Container + spawning + storage).
- Nextcloud, WebProtégé → Accounts (Managed account).

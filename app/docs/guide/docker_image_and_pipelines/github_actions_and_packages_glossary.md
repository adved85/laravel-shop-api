# GitHub Actions & Packages — Glossary

| Abbreviation | Means |
|---|---|
| GA | GitHub Actions |
| GW | GitHub Workflow |
| GP | GitHub Packages |

---

## GitHub Workflow

> A GitHub workflow is an automated process configured on your repo to
> **build, test, package, release, or deploy — using GitHub Actions.**

GitHub Actions workflows are automated pipelines defined in YAML files,
stored in the `.github/workflows` directory of your repo.

Or, more formally:

> A workflow is a configurable automated process that will run one or more
> **jobs**, which can run in sequential order or in parallel.

**Workflow runs can be triggered by:**

- **an event on your repo** — e.g. a push or a pull request:
  ```yaml
  on:
    push:
      branches: [main, develop]
  ```
  This repo's own `ci.yml` uses exactly this, for both `push` and
  `pull_request`.

- **manually:**
  ```yaml
  on:
    workflow_dispatch:
  ```
  Adds a **"Run workflow"** button in the Actions tab, letting you trigger
  the workflow on demand — no push required at all. This repo's
  `docker-publish.yml` uses this as a manual fallback, for rebuilding the
  image without cutting a release tag.

- **at a defined schedule:**
  ```yaml
  on:
    schedule:
      - cron: '0 2 * * *'
  ```
  GitHub automatically starts this workflow every day at 02:00 UTC — it
  has a cron. The five fields are `minute hour day-of-month month
  day-of-week` (standard cron syntax), so `0 2 * * *` reads as "at minute
  0 of hour 2, every day, every month, any weekday." Not used anywhere in
  this repo yet, but this is the trigger you'd reach for to run something
  like a nightly dependency audit.

  > Two quirks worth knowing: a scheduled workflow only runs against the
  > **default branch** (whatever's on it at the time), and GitHub can
  > delay the exact start time during periods of high load — `cron`
  > sets *when it's eligible to run*, not a guaranteed exact-second
  > trigger.

```text
.github/workflows/
    ci.yml
```

A repository can have multiple workflows, each performing a different set
of tasks (jobs). For example:
- one for testing pushes/pull requests
- one for publishing a release
- one for adding a label to an opened issue

**This repo has two:**

| Workflow | Jobs |
|---|---|
| `.github/workflows/ci.yml` | `test`, `docker-build` |
| `.github/workflows/docker-publish.yml` | `verify`, `build-and-push` |

See [`docker_image_and_release_pipeline.md`](../docker_image_and_release_pipeline.md)
for how the two relate.

---

## GitHub Actions

GitHub Actions (GA) is a CI/CD platform that lets you automate pipelines
for testing, building, and deploying your app.

GA also acts *like* an event listener on your GitHub repo: it watches for
activity and triggers the workflows configured to respond to it.

GitHub provides Linux, Windows, and macOS virtual machines to run your
workflows.

---

## Events

Any activity on your repo is treated as an event, and some of them can
trigger a workflow run.

Full reference: [Events that trigger workflows](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows)

---

## Jobs

A **job** is a set of steps in a workflow, executed inside its own runner
(normally a fresh virtual machine).

```yaml
jobs:
  test:
    steps:
      - name: Checkout code
        uses: actions/checkout@v7
  lint:
  security:
  build:
```

> `test`/`lint`/`security`/`build` here are just illustrative job names
> side by side — a real job needs at least `runs-on` and `steps` to be
> valid.

`actions/checkout@v7` → something like `git clone` your repo, at the exact
commit the workflow run was triggered from.

---

## Steps

A **step** is either:
- a shell script that gets executed (a `run:` step), or
- an action that gets run (a `uses:` step)

**Examples of shell script steps:**
- `deploy.sh`
- `rollback.sh`
- `healthcheck.sh`

**Examples of runnable actions** — all six are actually used somewhere in
this repo's own workflows:

| Action | What it does, in one line |
|---|---|
| `actions/checkout@v7` | Clones your repo onto the runner, at the commit the workflow was triggered from. |
| `actions/cache@v6` | Saves/restores files (e.g. Composer's cache dir) between runs, keyed on something like `composer.lock`'s hash. |
| `shivammathur/setup-php@v2` | Installs and configures a specific PHP version + extensions on the runner. |
| `docker/setup-buildx-action@v4` | Sets up Buildx, the modern Docker build engine (multi-stage builds, layer caching). |
| `docker/login-action@v4` | Logs Docker in to a registry (here, GHCR) using the credentials you give it. |
| `docker/metadata-action@v6` | Computes Docker image tags/labels from the triggering git ref — e.g. turns tag `v1.2.3` into image tags `1.2.3`, `1.2`, `latest`. |

> **Pinning policy here: major tags.** `@v7` is a *moving* tag — it
> follows every patch and minor release inside that major, so security
> fixes arrive without a commit from us, while a breaking new major never
> lands unannounced. The trade-off: a pinned major eventually goes stale —
> GitHub periodically deprecates the Node.js runtime an older major was
> built on, and every step using it starts printing a deprecation warning
> in the Actions log. That warning is the actual signal to bump: check
> each action's release notes, then update the `.yml` files and this
> doc's tables together, in the same commit — otherwise the docs quietly
> start lying about what's really pinned. (The stricter alternative —
> pinning an exact commit SHA — is immune to a compromised or force-moved
> tag, but turns every routine update into a manual diff. Worth it for an
> action many other projects depend on; unnecessary overhead here.)

---

## Runners

A **runner** is a server that runs your workflow. Each GitHub-hosted
runner runs only one job at a time.

---

## Git tags

A git tag is a named pointer to one specific commit.

```bash
git commit -m "L-CI-GHCR: created docker image and release pipeline"
git push origin L-CI-GHCR
```

> Always make and push the tag *after* pushing the actual changes, to
> keep the tagged version in sync with the real code.

```bash
git tag v1.2.0            # created tag locally
git push origin v1.2.0
```

---

## GitHub Packages

It's a package/container storage service. You can publish a package (npm,
NuGet, RubyGems, a Docker/container image, …) and store it here, alongside
your repo.

---

## GHCR = GitHub Container Registry

> **Why is it called a "Container Registry" if what we're storing is
> Docker images?**
>
> Because "container image" is the general term for what Docker builds
> (Docker is one implementation of the wider OCI container image spec) —
> a "Container Registry" is a registry *for* container images, the same
> way an "Image Registry" would be. This isn't a GitHub-specific naming
> quirk: AWS (**E**lastic **C**ontainer **R**egistry), Google (**G**oogle
> **C**ontainer **R**egistry), and Azure (**A**zure **C**ontainer
> **R**egistry) all use the identical term — that's the actual industry
> name for this kind of service.

```text
GitHub Actions
      │
      │ docker build
      ▼
 Docker Image
      │
      │ docker push
      ▼
GitHub Container Registry
      │
      ├── laravel-shop-api:1.0
      └── laravel-shop-api:latest
```

```text
ghcr.io/adved85/laravel-shop-api:1.0   ← the real path of this image
```

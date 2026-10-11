# Running pipeline runs inside the project's own Docker setup

Status: **proposal, nothing implemented yet.** This collects the problems found
with the current setup/prepare step, the options discussed, and the open
decisions.

## 1. Where things stand today

### What already changed (implemented, tested)

- **Fetch before branching** (`Worktree::resolveBaseCommit()` / `fetchBaseBranch()`):
  a new run fetches the base branch first and branches off the remote's
  version, unless the local branch is ahead of it (e.g. a merge whose push
  failed). The fetch is best-effort: an unreachable remote, or one without the
  branch yet, falls back to the local branch. GitHub projects fetch through the
  same explicit-URL, token-redacted path as pushing.
- **The agent commits its own work.** The build/fix prompts now ask for small,
  logical commits with descriptive messages (still no pushing). The pipeline's
  own final commit stays as a safety net for anything left uncommitted.
- **Git identity for agent commits.** `SubprocessEnvironment` sets
  `GIT_AUTHOR_*`/`GIT_COMMITTER_*` from `PIPELINE_GIT_NAME`/`PIPELINE_GIT_EMAIL`,
  so a plain `git commit` by the agent no longer fails with "Author identity
  unknown" inside the container.

### What setup does now

`Pipeline::setup()` creates the worktree, then `Worktree::prepare()`:

1. copies `vendor/` from the main clone (copied, not symlinked, so the
   autoloader resolves to the worktree's own classes);
2. runs `composer dump-autoload`, or `composer install` when there was no
   `vendor/` to copy;
3. writes `.env` from the project's `.env` (or `.env.example`) with
   `env_overrides` forced in (default: `APP_ENV=testing`, SQLite `:memory:`),
   and runs `key:generate` if `APP_KEY` is empty.

The checks are hard-coded in `config/pipeline.php` and tied to the `Phase` enum:
`pint --test`, `phpstan analyse`, `php artisan test`.

### Works for

A Laravel project that has Pint and PHPStan installed and whose test suite runs
on SQLite in memory without built frontend assets.

### Breaks for

- **Non-Laravel / non-PHP projects** (e.g. C#): no dependency install, and every
  check fails as a missing tool.
- **Laravel projects without Pint or PHPStan**: those checks fail every run.
- **Database-specific code** (Postgres `jsonb`, MySQL-only migrations): runs
  against the forced SQLite instead.
- **Other services** (Redis, queues, search, mail): not available.
- **Frontend assets**: no `npm ci` / `npm run build`, so tests that render a
  page using `@vite` can fail with "Unable to locate file in Vite manifest".
- **Project-specific setup**: migrations, seeders, `storage:link`, generated
  code (Wayfinder, IDE helpers), private Composer repositories.

### Services commonly paired with Laravel

Usually fine, because Laravel tests rarely touch external services for real. A
standard `phpunit.xml` swaps them for in-memory fakes:

```xml
<env name="CACHE_STORE" value="array"/>
<env name="SESSION_DRIVER" value="array"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="MAIL_MAILER" value="array"/>
<env name="BROADCAST_CONNECTION" value="null"/>
<env name="TELESCOPE_ENABLED" value="false"/>
```

These win over the worktree's `.env`, because Laravel never overwrites
environment variables that are already set, and the pipeline doesn't pass any
of these keys down from the orchestrator. So even with `CACHE_STORE=redis` in
`.env`, tests use `array` and never connect to Redis.

Where it breaks:

| Service | Breaks when |
|---|---|
| **Redis** | The project needs PHP's `redis` extension (`ext-redis` in `composer.json`, usual with phpredis) and the orchestrator's PHP doesn't have it: `composer install` fails and the run stops in setup. Also when tests use Redis directly (`Redis::` calls, Redis-backed rate limiting or locks), or when `phpunit.xml` doesn't override `CACHE_STORE`/`QUEUE_CONNECTION` and `.env` points at Redis: connection refused. |
| **Horizon** | Needs Redis and the `pcntl` extension. Its own tests rarely run, but `ext-pcntl` can block `composer install`. |
| **MySQL / Postgres** | SQLite in memory is forced. Postgres- or MySQL-only migrations (`jsonb`, `fullText`, raw SQL) fail. |
| **Scout + Meilisearch/Typesense** | Fine if `phpunit.xml` sets `SCOUT_DRIVER=collection` or `null`; otherwise it tries to connect to the search server. |
| **Mailpit / SMTP** | Fine with `MAIL_MAILER=array`. |
| **S3 / MinIO** | Fine if tests use `Storage::fake()` (common); otherwise it hits real S3. |
| **Reverb / Pusher** | Fine with `BROADCAST_CONNECTION=null`. |
| **Telescope / Pulse** | Usually turned off in `phpunit.xml`; Pulse can still need its tables or Redis ingest. |
| **Octane / Swoole / FrankenPHP** | Needs the `swoole` extension or a binary; `composer install` can fail on it. |
| **Dusk / Pest browser tests** | Need Chrome or Playwright and a running app server. Not available, so they fail. |
| **Vite / Inertia** | Tests rendering a page with `@vite` fail with "Unable to locate file in Vite manifest", since nothing runs `npm run build`. |

The underlying problem: the orchestrator's own container decides which PHP
version and extensions every project gets (8.5 plus whatever Sail installs). A
project locked to PHP 8.3 dependencies, or needing `imagick`, `swoole` or
`redis`, fails at `composer install`, before the agent even starts. Option B
removes this: services come from the project's compose file, PHP and its
extensions from the project's own image, and `.env` hostnames like
`REDIS_HOST=redis` resolve on the run's network.

## 2. Option A: a setup/checks config file in the repo

A `.pipeline.json` (or similar) in the target repo lists the setup commands,
extra env and checks; today's Laravel steps stay the fallback.

```json
{
  "setup": [["composer", "install", "--no-interaction"], ["npm", "ci"], ["npm", "run", "build"]],
  "env": { "DB_CONNECTION": "pgsql" },
  "checks": { "lint": ["npm", "run", "lint"], "tests": ["php", "artisan", "test"] }
}
```

- Pro: small change, language-agnostic commands.
- Con: still runs on the orchestrator's own machine/container, so the right
  runtimes (PHP version, .NET SDK, Node) and services (Postgres, Redis) must be
  installed there. That doesn't scale across projects.

## 3. Option B (preferred): run each run inside the project's compose setup

Prerequisite: the target repo has a compose file. Every run does
`docker compose up -d` for its own worktree and works inside those containers.

**Solves:** runtimes and toolchains, databases, Redis, queues, system packages,
any language. The forced SQLite overrides and the hard-coded composer step go
away.

**Still needed per project:** which service is "the app", the setup commands
(images usually don't install dependencies into a bind-mounted folder), and the
checks (`dotnet test` vs `php artisan test`).

### 3.1 The contract: `x-pipeline` inside the compose file

Compose ignores top-level `x-` keys, so the pipeline config can live inside the
compose file itself — one prerequisite file instead of two.

```yaml
x-pipeline:
  service: app
  setup:
    - composer install --no-interaction
    - php artisan migrate --force
  checks:
    lint: vendor/bin/pint --test
    tests: php artisan test
```

C# example: `service: api`, `setup: [dotnet restore]`,
`checks: {build: dotnet build, tests: dotnet test}`.

At registration, look for the compose file in Compose's own order
(`compose.yaml`, `compose.yml`, `docker-compose.yaml`, `docker-compose.yml`)
and validate it with `docker compose config`: the `x-pipeline` block exists,
`service` exists, and that service **bind-mounts the project folder**. Images
that `COPY` the source in at build time would never see the agent's edits.
Reject the project with a clear error otherwise.

### 3.2 Problems this introduces, and their fixes

| Problem | Fix |
|---|---|
| The orchestrator container has no `docker` and no `/var/run/docker.sock` (checked). Mounting the socket = root on the host, and the agent runs in that container with `--dangerously-skip-permissions`. | A separate docker runner (3.3). |
| Paths: a target's `volumes: - .:/app` is resolved by the Docker daemon **on the host**, not inside the orchestrator container. | Worktrees live at the **same absolute path** on the host and in the containers (e.g. mount `/srv/pipeline` at `/srv/pipeline`), and `PIPELINE_WORKTREE_PATH` / `github_projects_path` point there. |
| Parallel runs collide on published ports (`80:80`, `5432:5432`) and fixed `container_name`s. | Its own compose project per run (`-p pipeline-run-<id>`) plus a generated `compose.pipeline.yaml` override that does `ports: !reset []` and drops `container_name`. |
| Containers often run as root and leave root-owned files (`vendor/`, `bin/`, `obj/`) in the worktree, so the orchestrator can't commit or delete them. | `chown` back to the host user (exec as root in the container) before commit/cleanup, or exec as the host UID where the image allows it. |
| Abandoned runs leave containers running. | `down -v --remove-orphans` in cleanup, in the crash safety net in `PipelineRunCommand`, and in `pipeline:cleanup`. |
| The agent edits files directly (works through the bind mount) but its commands need to run in the container. | `pexec` through the runner (3.4). |

### 3.3 The docker runner

Docker has no "only these containers" permission: whatever can reach the
socket controls every container, image and volume on the machine, and in
practice is root on the host (`docker run -v /:/host ...`). So neither the
orchestrator nor the agent gets the socket.

Instead, add one extra service to the orchestrator's own `compose.yaml` (e.g.
`docker-runner`): the same image and codebase, the only container with the
socket mounted, reachable only on the internal Docker network. It serves a few
fixed operations and refuses everything else, like a bank teller in front of a
vault:

- `up(run)` → `docker compose -p pipeline-run-<id> up -d --build --wait`
- `exec(run, service, command)`
- `logs(run, service)`, `restart(run, service)`, `ps(run)`
- `down(run)`

Rules it enforces:

- It only ever builds compose project names itself (`pipeline-run-<id>`); the
  caller passes a run id and structured arguments (command as an argument
  list), never raw docker flags, container names or shell strings.
- The orchestrator authenticates with a shared secret.
- The agent gets a **token scoped to its own run**: it can act on any service
  of *its* run, never on another run, and never on the orchestrator itself.

The security is only as good as the runner's code, so it stays tiny, takes only
structured inputs, and is covered by tests.

### 3.4 Scope of the agent's access: the compose project, not a network

Docker networks only control which containers can **talk** to each other
(HTTP, Postgres, Redis). They have nothing to do with who can **control**
containers (exec, restart, logs). Control goes through the socket, and the
socket ignores networks. So "give it full access on network X" isn't possible.

The boundary is the compose project instead: every container started by
`docker compose -p pipeline-run-12 up` carries the label
`com.docker.compose.project=pipeline-run-12`. The runner lets the agent do
anything in that project:

```sh
pexec php artisan test                              # app service (default)
pexec --service database psql -c "select ..."       # any service of the run
plogs database
prestart redis
pps
pup
```

Network isolation between runs comes for free: compose already creates a
`pipeline-run-12_default` network per project, so run 12's app can't reach run
13's database.

The prompts (`build.system.md`, `fix.system.md`) stop naming
Pint/PHPStan/artisan and instead list the project's own checks, plus "run
commands with `pexec`". The `docker` deny rule stays.

### 3.5 The trap: the compose file is in the worktree

The agent can edit `compose.yaml`. If `pup` uses the worktree's version, the
agent can add something like this and restart its own service:

```yaml
privileged: true
network_mode: host
volumes:
  - /:/host                                       # the whole host disk
  - /var/run/docker.sock:/var/run/docker.sock     # the socket itself
```

That gives it root on the host through a container in its own run. Before
every `up`, the runner must either:

- **use the compose file from `base_commit`**, not the worktree (simplest,
  recommended; the agent can't change the compose setup during a run), or
- validate the resolved `docker compose config` and refuse `privileged`, host
  network/PID, `cap_add`, `devices`, the socket, and bind mounts outside the
  worktree.

### 3.6 Stronger alternative: Docker-in-Docker

For truly unrestricted access (the real `docker` CLI), give each run its own
daemon (`docker:dind`). The agent can do anything, but only inside that daemon,
so the worst case is it breaks its own sandbox.

- Con: the dind container must be `privileged` (a kernel-level escape would
  still reach the host: rare, but possible).
- Con: each daemon starts with an empty image cache, so `up` is slow without a
  shared registry mirror.
- Con: worktrees must be visible to the dind daemon (the same-path issue moves
  there).

Worth it only if this ever runs repos you don't trust, or if the agent turns
out to need more than the runner's operations.

### 3.7 New run lifecycle

- **Setup:** create the worktree (as today) → write `.env` from
  `.env.example` *without* the SQLite overrides → generate
  `compose.pipeline.yaml` → runner `up` → run each `x-pipeline.setup` command
  via `exec`. This replaces `Worktree::prepare()`.
- **Checks:** run through the runner's `exec`, one per `x-pipeline.checks`
  entry. The `Phase` enum loses its fixed `pint`/`phpstan`/`tests` cases; checks
  become named steps stored with the run, and the UI and fix loop iterate over
  them. (Biggest refactor: `Phase::checks()` is used in the UI and fix loop.)
- **Cleanup:** `chown` back → runner `down -v --remove-orphans` → remove
  worktree.

### 3.8 Lifetime, resources and images

`down -v` at the end of each run deletes the run's containers, its network and
its volumes (database, Redis data), so nothing keeps running or uses memory
after a run. The costs are **during** a run:

- **Parallel runs add up.** Three runs on a project with app + Postgres +
  Redis + Meilisearch is twelve containers at once; Meilisearch and MySQL use a
  few hundred MB each.
- **Every run starts from scratch.** No warm database or cache, so each run
  repeats migrations, `composer install` and `npm ci` during setup. Redis
  starts in a second; a database plus migrations plus dependency installs can
  take minutes. Speed-ups: copy `vendor/` and `node_modules/` from the main
  clone (as `Worktree::prepare()` does with `vendor/` today), or a shared
  Composer/npm cache volume.
- **A crashed run leaves its containers running** until cleanup. That's why
  `down` also belongs in the crash safety net and in `pipeline:cleanup`.

**Images do stick around**, which is mostly good: `down -v` doesn't delete
them, so pulled images (Postgres, Redis) and the app's build cache are reused
and only the first build is slow. The catch: for services with `build:`,
compose names the image after the compose project, so every run creates a new
tag (`pipeline-run-12-app`, `pipeline-run-13-app`, …). They share most layers,
but the tags pile up. Two fixes:

- `down -v --rmi local`: deletes only those locally built images, not pulled
  ones;
- **(recommended)** give built services a fixed image name in the generated
  `compose.pipeline.yaml` (e.g. `pipeline/<project>-app`), so every run of a
  project reuses one tag, rebuilt only when its Dockerfile changes.

### 3.9 Order of work

Each step can be merged and tested on its own:

1. `x-pipeline` contract + registration validation; same-path worktree storage.
2. The docker runner service.
3. New lifecycle + dynamic checks.
4. Agent access (`pexec` & co., run-scoped token, prompt changes).

Tests: the runner, `x-pipeline` parsing/validation and override generation get
unit/feature tests with `Process::fake()`; one end-to-end test uses a tiny real
compose project (e.g. `alpine` with an `sh` check), skipped when Docker isn't
available.

## 4. Agent git permissions (separate, still open)

The agent runs with `--dangerously-skip-permissions`; the only limits are the
`disallowed_tools` prefix rules in `config/pipeline.php`.

- `git push` is denied, but only as a prefix, so `git -C . push`,
  `git -c x=y push` or `sh -c "git push"` get past it. The real protection is
  that no credentials are reachable: the GitHub token is stripped from `origin`
  and only passed in transiently by `Worktree::pushWithToken()`. Weak spot: the
  agent runs as the same user, so any SSH key in `~/.ssh` or git credential
  helper on the host is reachable too.
- Not blocked, and more dangerous than a commit: `git reset --hard`,
  `git rebase` (rewrite or drop work on the run branch); `git remote set-url`,
  `git config` (edit the project's shared `.git/config`); `git checkout` of
  the base branch in the main clone (worktrees share one `.git`).
- Agent commits run the project's git hooks (e.g. a `pre-commit` running Pint
  or the tests); the pipeline's own commit uses `--no-verify` and skips them.

Possible fix: widen the deny list to `git reset`, `git rebase`, `git remote`,
`git config`, `git checkout`/`switch` (a speed bump, given the prefix
matching), and treat "no credentials reachable" as the real boundary, ideally
by running the agent as a user without SSH keys or credential helpers.

## 5. Decisions to make

1. **Agent container access**: `pexec` & co. through the runner (recommended)
   vs mounting the socket into the orchestrator and relying on deny rules
   (= root on the host for the agent).
2. **Compose as a hard requirement**, or keep today's local Laravel setup as a
   fallback for projects without one? A hard requirement is simpler and avoids
   two code paths.
3. **Config location**: `x-pipeline` inside the compose file (recommended) vs a
   separate `.pipeline.yaml`.
4. **Compose file during a run**: pinned to `base_commit` (recommended) vs
   validated worktree version.
5. **Runner on the host's Docker** (recommended to start) vs Docker-in-Docker.
6. **Widen the git deny list** now or not.
7. **Built images**: a fixed image name per project (recommended) vs
   `down -v --rmi local` after every run.

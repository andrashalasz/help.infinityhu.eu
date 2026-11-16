#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
INFINITY SÚGÓ – FRISSÍTŐ SZOLGÁLTATÁS (a gazdagépen fut, nem a konténerben).

A V40 Vital frissítőjének (deploy/v40-frissito.py) átvétele a súgó
felépítésére: PHP-FPM + nginx + MariaDB, Docker Compose-zal.

MIT CSINÁL
    Az admin felület „Frissítés" lapja egy gombnyomásra kiteszi az új
    verziót. A webalkalmazás ehhez SEMMIT nem futtat: egy megosztott mappába
    (a „csatornába", frissites/) egyetlen kérésfájlt ír. Ez a program veszi
    észre, és ez végzi a munkát:

        ellenőrzés → letöltés → tesztek → építés → mentés (+ próba-
        visszatöltés) → adatbázis → átváltás → ellenőrzés → takarítás

    Ha bármi hibára fut, visszaáll: az előző forrásra és PHP-képfájlra, és ha
    az adatbázis is módosult, a közvetlenül előtte készült mentésre.

MIBEN TÉR EL A V40-TŐL
    A súgó kódja NEM a képfájlban van, hanem a forrásfából van becsatolva
    (./site_dinamikus). Ha a forrásfát az építéskor állítanánk át, az új kód
    azonnal élesbe kerülne a régi adatbázison. Ezért:
      - az új PHP-képfájl a kért verzió TISZTA, ideiglenes példányából épül,
        a futó forrásfához az építés nem nyúl;
      - a forrásfa csak akkor áll át, amikor már nincs visszaút nélküli lépés:
        adatbázis-módosításnál a leállítás UTÁN (a migráció az új fából fut),
        egyébként közvetlenül az átváltás előtt.

MIÉRT ÍGY
    A kérésben nincs parancs, útvonal vagy kapcsoló – csak egy verzió-
    azonosító, és az is csak akkor érvényes, ha BETŰRE megegyezik azzal, amit
    ez a program maga látott a távoli tárolóban legújabbként. Egy feltört
    admin-fiókból így legfeljebb annyi lesz, hogy a legújabb, amúgy is
    kitelepítendő verzió kikerül.

    A csatorna tartalma ennek a programnak NEM megbízható bemenet: a saját
    állapotát a csatornán KÍVÜL tartja (SUGO_STATE_DIR), a csatornába csak ír.

FÜGGŐSÉG
    Python 3.8+, git, Docker a `docker compose` bővítménnyel. Semmi más.

HASZNÁLAT
    sugo-frissito.py check     – beállítások és környezet ellenőrzése
    sugo-frissito.py run       – a szolgáltatás (ezt indítja a systemd)
    sugo-frissito.py status    – a jelenlegi állapot kiírása

Leírás a szervercsapatnak: docs/FRISSITO.md
"""
from __future__ import annotations

import fcntl
import gzip
import json
import os
import re
import secrets
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
import time
import urllib.request
from datetime import datetime, timezone

SCHEMA = 1
UUID_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")
COMMIT_RE = re.compile(r"^[0-9a-f]{40}$")
REQUEST_MAX_BYTES = 4 * 1024
MAX_COMMITS = 50
HISTORY_KEEP = 200
LOGS_KEEP = 40
ROLLBACK_TAG = "sugo-frissito-rollback:previous"
BACKUP_PREFIX = "sugo-"

STEPS = ["validate", "fetch", "tests", "build", "backup", "migrate", "switch", "health", "cleanup"]


def now_iso() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def short(commit) -> str:
    return commit[:7] if isinstance(commit, str) else "?"


class StepError(Exception):
    """Egy lépés hibája – a szövege az admin felületre kerül, ezért magyar és közérthető."""


# ---------------------------------------------------------------------------
# Beállítások
# ---------------------------------------------------------------------------

class Config:
    def __init__(self, env=os.environ):
        def get(name, default=""):
            return (env.get(name) or default).strip()

        self.repo = os.path.abspath(get("SUGO_REPO_DIR"))
        self.repo_set = bool(get("SUGO_REPO_DIR"))
        self.channel = os.path.abspath(get("SUGO_CHANNEL_DIR", os.path.join(self.repo, "frissites")))
        self.state_dir = os.path.abspath(get("SUGO_STATE_DIR", os.path.join(self.repo, ".frissito")))
        self.backup_dir = os.path.abspath(get("SUGO_BACKUP_DIR", os.path.join(self.repo, "frissito-mentesek")))
        self.remote = get("SUGO_REMOTE", "origin")
        self.branch = get("SUGO_BRANCH", "main")
        self.compose_files = [f for f in re.split(r"[:,\s]+", get("SUGO_COMPOSE_FILES", "docker-compose.yml")) if f]
        self.compose_project = get("SUGO_COMPOSE_PROJECT")
        self.app_service = get("SUGO_APP_SERVICE", "php")
        self.db_service = get("SUGO_DB_SERVICE", "db")
        self.migrate_service = get("SUGO_MIGRATE_SERVICE", "migracio")
        self.db_name = get("SUGO_DB_NAME", "help_infinityhu")
        # A root jelszót tartalmazó környezeti változó neve a DB-konténeren BELÜL.
        self.db_root_env = get("SUGO_DB_ROOT_ENV", "MARIADB_ROOT_PASSWORD")
        # Amit az átváltás újraindít (és adatbázis-módosításnál előtte leállít).
        self.services = [s for s in get("SUGO_SERVICES", "php web").split() if s]
        # Ha ezek a mappák változnak, az adatbázis is módosul.
        self.migration_paths = [p for p in get("SUGO_MIGRATION_PATHS", "db/migracio").split() if p]
        self.php_build_context = get("SUGO_PHP_BUILD_CONTEXT", "docker/php")
        self.channel_mount = get("SUGO_CHANNEL_MOUNT", "/srv/frissites")
        self.health_url = get("SUGO_HEALTH_URL", "http://127.0.0.1:8080/health.php")
        self.health_timeout = int(get("SUGO_HEALTH_TIMEOUT", "240"))
        self.tick = max(3, int(get("SUGO_TICK", "10")))
        self.fetch_interval = max(30, int(get("SUGO_FETCH_INTERVAL", "120")))
        self.keep_backups = max(3, int(get("SUGO_KEEP_BACKUPS", "14")))
        self.min_free_gb = float(get("SUGO_MIN_FREE_GB", "3"))
        self.test_image = get("SUGO_TEST_IMAGE", "php:8.3-cli")
        # A mentést a telepítés előtt PRÓBÁBÓL VISSZA IS TÖLTI egy ideiglenes adatbázisba.
        self.verify_backup = get("SUGO_VERIFY_BACKUP", "1") not in ("0", "no", "false")
        self.verify_db = get("SUGO_VERIFY_DB_NAME", "sugo_frissito_ellenorzes")
        self.build_timeout = int(get("SUGO_BUILD_TIMEOUT", "3600"))
        self.tests_timeout = int(get("SUGO_TESTS_TIMEOUT", "1200"))

    def compose(self, *args):
        cmd = ["docker", "compose", "--project-directory", self.repo]
        if self.compose_project:
            cmd += ["-p", self.compose_project]
        for f in self.compose_files:
            cmd += ["-f", f if os.path.isabs(f) else os.path.join(self.repo, f)]
        return cmd + list(args)

    def git(self, *args):
        # `safe.directory`: ha a szolgáltatás más felhasználóként fut, mint a tároló tulajdonosa.
        return ["git", "-c", "safe.directory=" + self.repo, "-C", self.repo] + list(args)

    def db_shell(self, program: str, args: str = "") -> list:
        """mariadb / mariadb-dump a DB-konténerben, rootként. A jelszó a konténer saját változójából jön."""
        env = self.db_root_env
        if not re.match(r"^[A-Z_][A-Z0-9_]*$", env):
            raise StepError("A SUGO_DB_ROOT_ENV értéke hibás.")
        inner = 'MYSQL_PWD="${%s}" exec %s -uroot %s' % (env, program, args)
        return self.compose("exec", "-T", self.db_service, "sh", "-c", inner)


# ---------------------------------------------------------------------------
# Fájlok – atomi írás, link követése nélkül
# ---------------------------------------------------------------------------

def write_atomic(path: str, text: str, mode: int = 0o644) -> None:
    """Ideiglenes fájl UGYANABBAN a mappában, majd átnevezés: fél fájlt senki nem lát."""
    directory = os.path.dirname(path)
    tmp = os.path.join(directory, ".%s-%s.tmp" % (os.path.basename(path), secrets.token_hex(8)))
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), mode)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            f.write(text)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(tmp, mode)
        os.replace(tmp, path)
    except Exception:
        try:
            os.unlink(tmp)
        except OSError:
            pass
        raise


def read_capped(path: str, max_bytes: int):
    """Kis fájl beolvasása link követése nélkül. None = nincs / nem az, aminek lennie kell."""
    try:
        fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
    except OSError:
        return None
    try:
        st = os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or st.st_size > max_bytes:
            return None
        return os.read(fd, max_bytes + 1).decode("utf-8", "replace")
    finally:
        os.close(fd)


# ---------------------------------------------------------------------------
# A frissítő
# ---------------------------------------------------------------------------

class Updater:
    def __init__(self, cfg: Config):
        self.cfg = cfg
        self.state_file = os.path.join(cfg.state_dir, "state.json")
        self.history_file = os.path.join(cfg.state_dir, "history.jsonl")
        self.state = {"current": {"commit": None, "deployedAt": None}, "available": None, "run": None}
        self.last_fetch = 0.0
        self.last_status_write = 0.0
        self.stop_requested = False
        self.log_file = None  # a futó telepítés naplója

    # ----- napló ---------------------------------------------------------

    def say(self, msg: str) -> None:
        """A szolgáltatás saját naplója (journald) – és ha fut telepítés, annak a naplója is."""
        line = "[%s] %s" % (now_iso(), msg)
        print(line, flush=True)
        if self.log_file:
            try:
                self.log_file.write(line + "\n")
                self.log_file.flush()
            except OSError:
                pass

    # ----- mappák és állapot ---------------------------------------------

    def prepare_dirs(self) -> None:
        c = self.cfg
        os.makedirs(c.state_dir, mode=0o700, exist_ok=True)
        os.makedirs(c.backup_dir, mode=0o700, exist_ok=True)
        os.makedirs(c.channel, exist_ok=True)
        # Ragadós bit, mint a /tmp-n: a PHP (a konténer www-data felhasználója)
        # letehet kérést, de a mi fájljainkat nem törölheti.
        try:
            os.chmod(c.channel, 0o1777)
        except OSError as e:
            self.say("FIGYELEM: a csatorna jogai nem állíthatók be (%s)" % e)
        os.makedirs(os.path.join(c.channel, "log"), mode=0o755, exist_ok=True)

    def load_state(self) -> None:
        text = read_capped(self.state_file, 1024 * 1024)
        if not text:
            return
        try:
            data = json.loads(text)
            if isinstance(data, dict):
                for k in ("current", "available", "run"):
                    if k in data:
                        self.state[k] = data[k]
        except ValueError:
            self.say("FIGYELEM: a saját állapotfájl sérült, üres állapotból indulok")

    def save(self, force: bool = True) -> None:
        """A saját állapot ÉS a csatorna állapotfájlja. `force=False`: csak életjel, ritkítva."""
        if not force and time.time() - self.last_status_write < 8:
            return
        self.last_status_write = time.time()
        body = {"schema": SCHEMA, "heartbeatAt": now_iso()}
        body.update(self.state)
        write_atomic(self.state_file, json.dumps(self.state, ensure_ascii=False), 0o600)
        try:
            write_atomic(os.path.join(self.cfg.channel, "status.json"), json.dumps(body, ensure_ascii=False))
        except OSError as e:
            print("[%s] FIGYELEM: az állapot nem írható a csatornába: %s" % (now_iso(), e), flush=True)

    def heartbeat(self) -> None:
        self.save(force=False)

    def history_ids(self):
        ids = set()
        try:
            with open(self.history_file, encoding="utf-8") as f:
                for line in f:
                    try:
                        ids.add(json.loads(line).get("id"))
                    except ValueError:
                        pass
        except OSError:
            pass
        return ids

    def append_history(self, run: dict) -> None:
        entry = {k: run.get(k) for k in
                 ("id", "commit", "previousCommit", "state", "startedAt", "finishedAt", "error", "requestedBy")}
        with open(self.history_file, "a", encoding="utf-8") as f:
            f.write(json.dumps(entry, ensure_ascii=False) + "\n")
        os.chmod(self.history_file, 0o600)
        self.mirror_history()

    def mirror_history(self) -> None:
        """Az előzmények másolata a csatornába – a mesterpéldány a csatornán kívül marad."""
        try:
            with open(self.history_file, encoding="utf-8") as f:
                lines = f.readlines()[-HISTORY_KEEP:]
        except OSError:
            lines = []
        try:
            write_atomic(os.path.join(self.cfg.channel, "history.jsonl"), "".join(lines))
        except OSError as e:
            self.say("FIGYELEM: az előzmények nem írhatók a csatornába: %s" % e)

    # ----- parancsok -----------------------------------------------------

    def out(self, cmd, timeout=120, env=None):
        """Rövid parancs, a kimenetét visszaadja. Hibánál kivétel."""
        r = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout,
                           env=env, cwd=self.cfg.repo)
        if r.returncode != 0:
            raise subprocess.CalledProcessError(r.returncode, cmd, r.stdout, r.stderr)
        return r.stdout.decode("utf-8", "replace").strip()

    def run_logged(self, cmd, timeout, env=None, title=None) -> int:
        """Hosszú parancs: a kimenete a telepítés naplójába megy, közben megy az életjel."""
        self.say("$ " + (title or " ".join(cmd)))
        proc = subprocess.Popen(cmd, stdout=self.log_file or subprocess.DEVNULL, stderr=subprocess.STDOUT,
                                stdin=subprocess.DEVNULL, env=env, cwd=self.cfg.repo)
        started = time.time()
        while True:
            try:
                code = proc.wait(timeout=5)
                self.say("→ kilépési kód: %d" % code)
                return code
            except subprocess.TimeoutExpired:
                self.heartbeat()
                if time.time() - started > timeout:
                    proc.kill()
                    proc.wait()
                    self.say("→ IDŐTÚLLÉPÉS (%d mp), a parancsot leállítottam" % timeout)
                    return 124

    # ----- mi fut, mi érhető el ------------------------------------------

    def app_container(self):
        try:
            cid = self.out(self.cfg.compose("ps", "-q", self.cfg.app_service))
            return cid.splitlines()[0] if cid else None
        except Exception:
            return None

    def running_commit(self):
        """A futó PHP-konténerbe égetett verzió (APP_COMMIT), ha van."""
        cid = self.app_container()
        if not cid:
            return None
        try:
            envs = self.out(["docker", "inspect", "--format", "{{range .Config.Env}}{{println .}}{{end}}", cid])
        except Exception:
            return None
        for line in envs.splitlines():
            if line.startswith("APP_COMMIT="):
                v = line.split("=", 1)[1].strip()
                return v if COMMIT_RE.match(v) else None
        return None

    def running_image_exists(self) -> bool:
        """A futó PHP-konténer képfájlja még megvan-e (csak akkor lehet visszaút-címkét adni rá)."""
        cid = self.app_container()
        if not cid:
            return False
        try:
            image_id = self.out(["docker", "inspect", "--format", "{{.Image}}", cid])
            self.out(["docker", "image", "inspect", "--format", "{{.Id}}", image_id])
            return True
        except Exception:
            return False

    def modified_files(self):
        """A követett fájlok, amelyek a szerveren eltérnek a kicsomagolt verziótól (munkafa és index)."""
        c = self.cfg
        names = set()
        for extra in ((), ("--cached",)):
            raw = self.out(c.git("diff", "--name-only", *extra, "HEAD"))
            names.update(l.strip() for l in raw.splitlines() if l.strip())
        return sorted(names)

    def git_head(self):
        try:
            v = self.out(self.cfg.git("rev-parse", "HEAD"))
            return v if COMMIT_RE.match(v) else None
        except Exception:
            return None

    def refresh_current(self) -> None:
        """Mi fut most: a konténerbe égetett verzió, ennek híján a kicsomagolt forrás."""
        commit = self.running_commit() or self.git_head()
        if commit and commit != self.state["current"].get("commit"):
            # Más fut, mint amit utoljára mi tettünk ki (kézi telepítés): az időpontját nem ismerjük.
            self.state["current"] = {"commit": commit, "deployedAt": None}

    def fetch(self) -> bool:
        c = self.cfg
        try:
            self.out(c.git("fetch", "--quiet", "--prune", c.remote, c.branch), timeout=180)
            return True
        except Exception as e:
            err = getattr(e, "stderr", b"") or b""
            self.say("A távoli tároló most nem érhető el: %s" % (err.decode("utf-8", "replace").strip()[:300] or e))
            return False

    def migration_changed(self, old, new) -> bool:
        if not old:
            return True
        c = self.cfg
        return bool(self.out(c.git("diff", "--name-only", old, new, "--", *c.migration_paths)))

    def refresh_available(self) -> None:
        c = self.cfg
        cur = self.state["current"].get("commit")
        try:
            tip = self.out(c.git("rev-parse", "refs/remotes/%s/%s" % (c.remote, c.branch)))
        except Exception:
            return
        if not COMMIT_RE.match(tip):
            return
        try:
            committed = self.out(c.git("show", "-s", "--format=%cI", tip))
            count, has_mig, commits = 0, False, []
            if cur and cur != tip:
                # Csak ELŐRE megyünk: ha a futó verzió nem őse a távoli legújabbnak, nem ajánljuk fel.
                anc = subprocess.run(c.git("merge-base", "--is-ancestor", cur, tip),
                                     stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                if anc.returncode != 0:
                    self.say("A távoli %s/%s (%s) nem a futó verzió (%s) folytatása – nem ajánlom fel."
                             % (c.remote, c.branch, short(tip), short(cur)))
                    self.state["available"] = None
                    return
                rng = "%s..%s" % (cur, tip)
                count = int(self.out(c.git("rev-list", "--count", rng)) or "0")
                has_mig = self.migration_changed(cur, tip)
                raw = self.out(c.git("log", "-n", str(MAX_COMMITS), "--format=%H%x1f%cI%x1f%s", rng))
                for line in raw.splitlines():
                    parts = line.split("\x1f")
                    if len(parts) == 3 and COMMIT_RE.match(parts[0]):
                        commits.append({"sha": parts[0], "date": parts[1], "subject": parts[2][:200]})
            self.state["available"] = {
                "commit": tip, "committedAt": committed, "checkedAt": now_iso(),
                "count": count, "hasMigrations": has_mig, "commits": commits,
            }
        except Exception as e:
            self.say("Az elérhető verzió kiszámítása nem sikerült: %s" % e)

    # ----- a kérés -------------------------------------------------------

    def take_request(self):
        """A kérésfájl felvétele: beolvassuk, és MINDIG eltüntetjük. None = nincs érvényes kérés."""
        path = os.path.join(self.cfg.channel, "request.json")
        if not os.path.lexists(path):
            return None
        text = read_capped(path, REQUEST_MAX_BYTES)
        try:
            os.unlink(path)
        except OSError as e:
            self.say("FIGYELEM: a kérésfájl nem törölhető: %s" % e)
            return None
        if text is None:
            self.say("Eldobtam egy kérésfájlt: nem szabályos fájl vagy túl nagy.")
            return None
        try:
            req = json.loads(text)
        except ValueError:
            self.say("Eldobtam egy kérésfájlt: nem olvasható adat.")
            return None
        if not isinstance(req, dict):
            return None
        rid, commit, by = req.get("id"), req.get("commit"), req.get("requestedBy")
        if not (isinstance(rid, str) and UUID_RE.match(rid) and isinstance(commit, str) and COMMIT_RE.match(commit)
                and isinstance(by, int) and not isinstance(by, bool) and by > 0):
            self.say("Eldobtam egy kérésfájlt: hibás mezők.")
            return None
        if rid in self.history_ids():
            self.say("Eldobtam egy kérésfájlt: ez a kérés (%s) már lefutott." % rid)
            return None
        return {"id": rid, "commit": commit, "requestedBy": by}

    # ----- a telepítés ---------------------------------------------------

    def step(self, key: str, state: str) -> None:
        run = self.state["run"]
        for s in run["steps"]:
            if s["key"] == key:
                break
        else:
            s = {"key": key, "state": "PENDING", "startedAt": None, "finishedAt": None}
            run["steps"].append(s)
        s["state"] = state
        if state == "RUNNING":
            s["startedAt"] = now_iso()
            run["step"] = key
            self.say("=== %s ===" % key)
        elif state in ("DONE", "FAILED", "SKIPPED"):
            s["finishedAt"] = now_iso()
        self.save()

    def deploy(self, req: dict) -> None:
        c = self.cfg
        rid, commit = req["id"], req["commit"]
        prev = self.state["current"].get("commit")
        self.state["run"] = {
            "id": rid, "commit": commit, "previousCommit": prev, "requestedBy": req["requestedBy"],
            "state": "RUNNING", "step": None,
            "steps": [{"key": k, "state": "PENDING", "startedAt": None, "finishedAt": None} for k in STEPS],
            "startedAt": now_iso(), "finishedAt": None, "error": None, "backupFile": None,
        }
        log_path = os.path.join(c.channel, "log", rid + ".log")
        self.log_file = open(log_path, "a", encoding="utf-8")
        os.chmod(log_path, 0o644)
        self.save()
        self.say("Telepítés indul: %s → %s (kérte: #%s, kérés: %s)" % (short(prev), short(commit), req["requestedBy"], rid))

        # stopped:   az alkalmazás áll (adatbázis-módosítás miatt)
        # moved:     a forrásfa átállt (a becsatolt kód már az új)
        # db_touched: a migráció elindult
        # switched:  az új konténerek indítása elindult
        ctx = {"stopped": False, "db_touched": False, "switched": False, "moved": False,
               "prev_head": None, "branch": None, "image": None, "rollback_ready": False,
               "backup": None, "tmp": None, "needs_migration": False}
        current = None
        try:
            for key in STEPS:
                current = key
                self.step(key, "RUNNING")
                skipped = getattr(self, "do_" + key)(commit, prev, ctx)
                self.step(key, "SKIPPED" if skipped == "SKIPPED" else "DONE")
            run = self.state["run"]
            run["state"] = "SUCCEEDED"
            self.state["current"] = {"commit": commit, "deployedAt": now_iso()}
            self.say("KÉSZ: a(z) %s verzió fut." % short(commit))
        except Exception as e:  # noqa: BLE001 – minden hiba ide fut, és visszaállás lesz belőle
            msg = str(e) if isinstance(e, StepError) else "Váratlan hiba: %s" % e
            self.say("HIBA a(z) '%s' lépésben: %s" % (current, msg))
            if current:
                self.step(current, "FAILED")
            self.recover(msg, prev, ctx)
        finally:
            run = self.state["run"]
            run["finishedAt"] = now_iso()
            if ctx.get("tmp"):
                shutil.rmtree(ctx["tmp"], ignore_errors=True)
            self.append_history(run)
            if run["state"] != "SUCCEEDED":
                self.refresh_current()
            self.refresh_available()
            self.save()
            self.say("A telepítés vége: %s" % run["state"])
            self.log_file.close()
            self.log_file = None
            self.prune_logs()

    # --- lépések ---

    def do_validate(self, commit, prev, ctx):
        c = self.cfg
        if not self.fetch():
            raise StepError("A távoli tároló nem érhető el, ezért a kért verzió nem ellenőrizhető. "
                            "A futó rendszerhez nem nyúltam.")
        tip = self.out(c.git("rev-parse", "refs/remotes/%s/%s" % (c.remote, c.branch)))
        if tip != commit:
            raise StepError("A kért verzió (%s) már nem a legújabb (%s). Töltsd újra az oldalt, nézd át a "
                            "változásokat, és indítsd újra. A futó rendszerhez nem nyúltam." % (short(commit), short(tip)))
        if prev:
            anc = subprocess.run(c.git("merge-base", "--is-ancestor", prev, commit),
                                 stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            if anc.returncode != 0:
                raise StepError("A kért verzió nem a most futó folytatása (visszalépés vagy elágazás). "
                                "Ilyet a frissítő nem telepít – a szervercsapat teendője.")
        dirty = self.modified_files()
        if dirty:
            raise StepError("A szerveren a forrásfájlok kézzel módosítva lettek (%s). A frissítő ezeket nem írja "
                            "felül. A szervercsapat teendője: megnézni az eltérést (git diff), és vagy visszavonni "
                            "(git checkout -- <fájl>), vagy – ha szükség van rá – a tárolóba felvetetni; a compose "
                            "helyi beállításai külön fájlba valók (lásd docs/FRISSITO.md). Utána újra indítható. "
                            "A futó rendszerhez nem nyúltam." % ", ".join(dirty[:6]))
        if not self.app_container():
            raise StepError("A súgó PHP-konténere nem fut. Az első telepítést kézzel kell elvégezni "
                            "(docs/FRISSITO.md), a frissítő csak futó rendszert frissít.")
        if not self.running_image_exists():
            raise StepError("A futó PHP-konténer képfájlja már nem létezik (valaki újraépítette, de a konténert "
                            "nem indította újra), így nem lenne mire visszaállni. A szervercsapat teendője: "
                            "`docker compose up -d --force-recreate php web`, utána újra indítható. "
                            "A futó rendszerhez nem nyúltam.")
        free = shutil.disk_usage(c.repo).free / (1024 ** 3)
        if free < c.min_free_gb:
            raise StepError("Kevés a szabad hely a szerveren (%.1f GB, legalább %.0f GB kell). "
                            "A futó rendszerhez nem nyúltam." % (free, c.min_free_gb))
        self.say("Rendben: %s a legújabb, a forrás tiszta, szabad hely %.1f GB." % (short(commit), free))

    def do_fetch(self, commit, prev, ctx):
        """A kért verzió TISZTA példánya egy ideiglenes mappába – ezen futnak a tesztek, és ebből épül a kép."""
        c = self.cfg
        tmp = tempfile.mkdtemp(prefix="run-", dir=c.state_dir)
        ctx["tmp"] = tmp
        src = os.path.join(tmp, "src")
        os.makedirs(src)
        git = subprocess.Popen(c.git("archive", "--format=tar", commit), stdout=subprocess.PIPE, cwd=c.repo)
        tar = subprocess.run(["tar", "-x", "-C", src], stdin=git.stdout)
        git.stdout.close()
        if git.wait() != 0 or tar.returncode != 0:
            raise StepError("A kért verzió forrását nem sikerült kicsomagolni. A futó rendszerhez nem nyúltam.")
        # A tesztkonténer más felhasználóként fut – olvasnia kell tudni.
        os.chmod(tmp, 0o755)
        ctx["src"] = src

    def do_tests(self, commit, prev, ctx):
        c = self.cfg
        src = ctx["src"]
        if not os.path.isfile(os.path.join(src, "tests", "futtat.php")):
            raise StepError("Ebben a verzióban nincs tests/futtat.php, ezért nem telepítem. "
                            "A futó rendszerhez nem nyúltam.")
        # Hálózat nélkül, a futtató felhasználó nevében: a teszt nem telefonál haza, és nem hagy root-fájlt.
        cmd = ["docker", "run", "--rm", "--network", "none",
               "--user", "%d:%d" % (os.getuid(), os.getgid()), "-e", "HOME=/tmp",
               "-v", src + ":/src:ro", "-w", "/src", c.test_image, "php", "tests/futtat.php"]
        code = self.run_logged(cmd, c.tests_timeout)
        if code != 0:
            raise StepError("A tesztek nem mentek át ezen a verzión, ezért nem telepítem. "
                            "A futó rendszerhez nem nyúltam – a régi verzió megy tovább. A részletek a naplóban.")

    def do_build(self, commit, prev, ctx):
        c = self.cfg
        cid = self.app_container()
        image_id = self.out(["docker", "inspect", "--format", "{{.Image}}", cid])
        ctx["image"] = self.out(["docker", "inspect", "--format", "{{.Config.Image}}", cid])
        # A VISSZAÚT: a most futó képfájl külön címkét kap, mielőtt az új ráépülne a nevére.
        self.out(["docker", "tag", image_id, ROLLBACK_TAG])
        ctx["rollback_ready"] = True
        self.say("A most futó PHP-képfájl (%s, %s) megjelölve visszaálláshoz." % (ctx["image"], image_id[:19]))

        self.check_compose(ctx["src"])

        # Az új képfájl a TISZTA ideiglenes példányból épül, a futó forrásfa érintetlen.
        context = os.path.join(ctx["src"], c.php_build_context)
        if not os.path.isfile(os.path.join(context, "Dockerfile")):
            raise StepError("Az új verzióban nincs %s/Dockerfile. A futó rendszerhez nem nyúltam." % c.php_build_context)
        cmd = ["docker", "build", "--pull=false", "-t", ctx["image"],
               "--build-arg", "APP_COMMIT=%s" % commit, "--build-arg", "APP_BUILT_AT=%s" % now_iso(), context]
        code = self.run_logged(cmd, c.build_timeout)
        if code != 0:
            raise StepError("Az új verzió építése nem sikerült. A futó rendszerhez nem nyúltam – a régi verzió "
                            "megy tovább. A részletek a naplóban.")

    def check_compose(self, src):
        """Az új verzió compose-fájlja érvényes-e (a titokfájlok is megvannak-e) – a futó fán kívül, a tiszta példányon."""
        c = self.cfg
        cmd = ["docker", "compose", "--project-directory", c.repo]
        if c.compose_project:
            cmd += ["-p", c.compose_project]
        for f in c.compose_files:
            # A tárolóban követett compose-fájl az új verzióból jön; a helyi kiegészítő (nem követett) a futó fából.
            cand = os.path.join(src, f) if not os.path.isabs(f) else f
            cmd += ["-f", cand if os.path.exists(cand) else os.path.join(c.repo, f)]
        try:
            conf = json.loads(self.out(cmd + ["config", "--format", "json"], timeout=60))
        except Exception as e:
            err = (getattr(e, "stderr", b"") or b"").decode("utf-8", "replace").strip()[:400]
            raise StepError("Az új verzió Docker-összeállítása hibás vagy hiányos a szerveren (%s). "
                            "A futó rendszerhez nem nyúltam." % (err or e))
        missing = []
        for name, sec in (conf.get("secrets") or {}).items():
            path = (sec or {}).get("file")
            if path and not os.path.exists(path):
                missing.append(os.path.relpath(path, c.repo))
        if missing:
            raise StepError("Az új verzióhoz hiányzik egy vagy több titokfájl a szerveren: %s. "
                            "A futó rendszerhez nem nyúltam." % ", ".join(missing))

    def move_tree(self, commit, ctx) -> None:
        """A futó forrásfa átállítása a kért verzióra. Innentől a becsatolt kód az új."""
        c = self.cfg
        ctx["prev_head"] = self.out(c.git("rev-parse", "HEAD"))
        br = subprocess.run(c.git("symbolic-ref", "-q", "--short", "HEAD"), stdout=subprocess.PIPE)
        ctx["branch"] = br.stdout.decode().strip() if br.returncode == 0 else None
        ctx["moved"] = True
        if ctx["branch"]:
            code = self.run_logged(c.git("merge", "--ff-only", commit), 120)
        else:
            code = self.run_logged(c.git("checkout", "--quiet", "--detach", commit), 120)
        if code != 0:
            raise StepError("A szerveren lévő forrás nem állítható át a kért verzióra (az ok a naplóban van – "
                            "jellemzően egy kézzel odatett fájl van útban).")

    def do_backup(self, commit, prev, ctx):
        c = self.cfg
        ctx["needs_migration"] = self.migration_changed(prev, commit)
        if ctx["needs_migration"]:
            # Az adatbázis módosul: a mentés ELŐTT leáll a súgó, hogy a mentés és a
            # visszaállás között semmi ne íródhasson – így visszaálláskor adat nem vész el.
            self.say("Az adatbázis módosulni fog – a súgó leáll a mentés és az átállás idejére.")
            ctx["stopped"] = True
            if self.run_logged(c.compose("stop", *reversed(c.services)), 180) != 0:
                raise StepError("A súgót nem sikerült leállítani az adatbázis-mentéshez.")
        path = self.dump_database(commit)
        ctx["backup"] = path
        self.state["run"]["backupFile"] = os.path.basename(path)
        self.save()
        if c.verify_backup:
            # Amíg egy mentést nem töltöttek vissza, nem tudni, hogy mentés-e. Itt derül ki –
            # MIELŐTT az adatbázishoz hozzányúlnánk –, nem a visszaállás közepén.
            self.verify_backup(path, exact_rows=ctx["stopped"])

    def dump_database(self, commit) -> str:
        c = self.cfg
        if not re.match(r"^[A-Za-z0-9_]{1,64}$", c.db_name):
            raise StepError("A SUGO_DB_NAME értéke hibás.")
        name = "%s%s-elotte-%s.sql.gz" % (BACKUP_PREFIX, datetime.now().strftime("%Y%m%d-%H%M%S"), short(commit))
        path = os.path.join(c.backup_dir, name)
        args = ("--single-transaction --routines --triggers --events --hex-blob --no-tablespaces "
                "--databases %s" % c.db_name)
        self.say("$ mariadb-dump → %s" % name)
        proc = subprocess.Popen(c.db_shell("mariadb-dump", args),
                                stdout=subprocess.PIPE, stderr=self.log_file, stdin=subprocess.DEVNULL, cwd=c.repo)
        tail = b""
        size = 0
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, "wb") as raw, gzip.GzipFile(fileobj=raw, mode="wb", compresslevel=6) as gz:
            for line in proc.stdout:
                gz.write(line)
                size += len(line)
                tail = (tail + line)[-400:]
                self.heartbeat()
        code = proc.wait()
        if code != 0 or size < 1024 or b"-- Dump completed" not in tail:
            try:
                os.unlink(path)
            except OSError:
                pass
            raise StepError("Az adatbázis-mentés nem sikerült vagy nem teljes, ezért nem megyek tovább.")
        self.say("Mentés kész: %s (%.1f MB tömörítés előtt)" % (name, size / 1e6))
        return path

    def mysql(self, sql: str, timeout: int = 600):
        """Rövid SQL a kiszolgálón, rootként. Visszaadja a kilépési kódot és a kimenetet."""
        c = self.cfg
        r = subprocess.run(c.db_shell("mariadb", "-N -B"), input=sql.encode(),
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout, cwd=c.repo)
        if r.returncode != 0 and self.log_file:
            self.log_file.write(r.stderr.decode("utf-8", "replace"))
            self.log_file.flush()
        return r.returncode, r.stdout.decode("utf-8", "replace")

    def db_shape(self, db: str, rows: bool):
        """Az adatbázis alakja: táblák, nézetek, triggerek, eljárások – és kérésre táblánként a sorok száma."""
        code, out = self.mysql(
            "SELECT CONCAT('T:', TABLE_TYPE, ':', TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_SCHEMA='%s';"
            "SELECT CONCAT('G:', TRIGGER_NAME, ':', EVENT_OBJECT_TABLE) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='%s';"
            "SELECT CONCAT('R:', ROUTINE_TYPE, ':', ROUTINE_NAME) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='%s';"
            % (db, db, db))
        if code != 0:
            raise StepError("az adatbázis szerkezete nem kérdezhető le (%s)" % db)
        shape = set(l for l in out.splitlines() if l.strip())
        if rows:
            tables = sorted(l.split(":", 2)[2] for l in shape if l.startswith("T:BASE TABLE:"))
            q = "".join("SELECT CONCAT('N:%s:', COUNT(*)) FROM `%s`.`%s`;" % (t, db, t) for t in tables)
            code, out = self.mysql(q, timeout=1800) if q else (0, "")
            if code != 0:
                raise StepError("a sorok száma nem kérdezhető le (%s)" % db)
            shape |= set(l for l in out.splitlines() if l.strip())
        return shape

    def verify_backup(self, path: str, exact_rows: bool) -> None:
        """
        PRÓBA-VISSZATÖLTÉS: a mentés egy ideiglenes adatbázisba kerül, és összevetjük az
        eredetivel (táblák, nézetek, triggerek, eljárások; álló súgónál a sorok száma is).
        Az éles adatbázishoz ez a lépés nem nyúl.
        """
        c = self.cfg
        scratch = c.verify_db
        if not re.match(r"^[A-Za-z0-9_]{1,64}$", scratch) or scratch == c.db_name:
            raise StepError("A próba-visszatöltés adatbázisneve hibás (SUGO_VERIFY_DB_NAME).")
        self.say("$ próba-visszatöltés a(z) %s ideiglenes adatbázisba" % scratch)
        proc = subprocess.Popen(c.db_shell("mariadb"),
                                stdin=subprocess.PIPE, stdout=self.log_file, stderr=subprocess.STDOUT, cwd=c.repo)
        create_prefix = ("CREATE DATABASE /*!32312 IF NOT EXISTS*/ `%s`" % c.db_name).encode()
        use_line = ("USE `%s`;" % c.db_name).encode()
        seen_create = seen_use = False
        try:
            # A próba ne kerüljön a bináris naplóba (ne is replikálódjon, helyet se foglaljon).
            proc.stdin.write(("SET sql_log_bin=0;\nDROP DATABASE IF EXISTS `%s`;\n" % scratch).encode())
            with gzip.open(path, "rb") as gz:
                for line in gz:
                    # A mentés a saját nevén hozná létre az adatbázist – a próbához ezt a két
                    # sort az ideiglenes névre írjuk át. Adatsor így nem kezdődhet.
                    if line.startswith(create_prefix):
                        line = line.replace(("`%s`" % c.db_name).encode(), ("`%s`" % scratch).encode(), 1)
                        seen_create = True
                    elif line.rstrip(b"\r\n") == use_line:
                        line = ("USE `%s`;\n" % scratch).encode()
                        seen_use = True
                    proc.stdin.write(line)
                    self.heartbeat()
            proc.stdin.close()
        except BrokenPipeError:
            pass
        code = proc.wait()
        try:
            if not (seen_create and seen_use):
                # Enélkül a próba az ÉLES adatbázisra töltene – ezt nem engedjük.
                raise StepError("A mentés formája váratlan (nincs benne a CREATE DATABASE / USE sor), "
                                "ezért nem ellenőrizhető. Nem megyek tovább.")
            if code != 0:
                raise StepError("Az adatbázis-mentés NEM tölthető vissza (a próba-visszatöltés hibát jelzett), "
                                "ezért nem megyek tovább. A részletek a naplóban.")
            orig = self.db_shape(c.db_name, exact_rows)
            copy = self.db_shape(scratch, exact_rows)
            if orig != copy:
                diff = sorted(orig ^ copy)[:8]
                raise StepError("A visszatöltött mentés nem egyezik az adatbázissal (%s), ezért nem megyek tovább."
                                % ", ".join(diff))
            self.say("A mentés visszatölthető, és egyezik az adatbázissal (%d elem%s)."
                     % (len(orig), ", sorok száma is" if exact_rows else ""))
        finally:
            self.mysql("SET sql_log_bin=0; DROP DATABASE IF EXISTS `%s`;" % scratch)

    def do_migrate(self, commit, prev, ctx):
        c = self.cfg
        if not ctx.get("needs_migration"):
            self.say("Az adatbázis-módosító fájlok nem változtak, nincs teendő.")
            return "SKIPPED"
        # A migráció az ÚJ verzió SQL-fájljait futtatja – ehhez át kell állni. A súgó áll.
        self.move_tree(commit, ctx)
        ctx["db_touched"] = True
        code = self.run_logged(c.compose("run", "--rm", "--no-deps", "-T", c.migrate_service), 1800)
        if code != 0:
            raise StepError("Az adatbázis frissítése hibára futott.")

    def do_switch(self, commit, prev, ctx):
        c = self.cfg
        ctx["switched"] = True
        if not ctx["moved"]:
            self.move_tree(commit, ctx)
        # --no-deps: a migráció már lefutott (vagy nem kellett), ne induljon újra.
        code = self.run_logged(c.compose("up", "-d", "--no-build", "--no-deps", "--force-recreate", *c.services), 600)
        if code != 0:
            raise StepError("Az új verzió elindítása nem sikerült.")

    def do_health(self, commit, prev, ctx):
        if not self.wait_healthy(self.cfg.health_timeout):
            raise StepError("Az új verzió nem válaszolt helyesen az ellenőrzésre %d másodpercen belül."
                            % self.cfg.health_timeout)
        got = self.running_commit()
        if got != commit:
            raise StepError("Az ellenőrzés szerint nem a kért verzió fut (%s helyett %s)." % (short(commit), short(got)))
        self.say("Az új verzió válaszol, és a kért verzió fut.")

    def do_cleanup(self, commit, prev, ctx):
        c = self.cfg
        try:
            files = sorted(f for f in os.listdir(c.backup_dir) if f.startswith(BACKUP_PREFIX) and f.endswith(".sql.gz"))
            for old in files[:-c.keep_backups]:
                os.unlink(os.path.join(c.backup_dir, old))
                self.say("Régi mentés törölve: %s" % old)
            # Csak a címke nélküli (már semmire nem használt) képfájlok; a visszaút címkéje marad.
            self.run_logged(["docker", "image", "prune", "-f"], 300)
        except Exception as e:  # a takarítás hibája nem ok a visszaállásra
            self.say("A takarítás nem volt teljes: %s" % e)

    def wait_healthy(self, timeout: int) -> bool:
        deadline = time.time() + timeout
        ok_streak = 0
        while time.time() < deadline:
            try:
                with urllib.request.urlopen(self.cfg.health_url, timeout=8) as r:
                    ok_streak = ok_streak + 1 if r.status == 200 else 0
            except Exception:
                ok_streak = 0
            if ok_streak >= 2:
                return True
            self.heartbeat()
            time.sleep(3)
        return False

    # --- visszaállás ---

    def git_back(self, ctx) -> None:
        c = self.cfg
        if not ctx.get("moved") or not ctx.get("prev_head"):
            return
        if ctx.get("branch"):
            # A fa a telepítés előtt ellenőrzötten tiszta volt, tehát itt nincs mit elveszíteni.
            code = self.run_logged(c.git("reset", "--hard", ctx["prev_head"]), 120)
        else:
            code = self.run_logged(c.git("checkout", "--quiet", "--detach", ctx["prev_head"]), 120)
        if code != 0:
            raise StepError("a forrás nem állítható vissza az előző verzióra")

    def recover(self, msg: str, prev, ctx) -> None:
        c = self.cfg
        run = self.state["run"]
        touched = ctx["stopped"] or ctx["db_touched"] or ctx["switched"] or ctx["moved"]
        if not touched:
            # A futó konténer a régi képfájlon fut tovább – de ha az új kép már elkészült, a NEVE
            # most az újra mutat. Visszaadjuk a régire, különben egy későbbi kézi `compose up`
            # az új képfájlt indítaná a régi forrással.
            if ctx["rollback_ready"] and ctx.get("image"):
                try:
                    self.out(["docker", "tag", ROLLBACK_TAG, ctx["image"]])
                except Exception as e:
                    self.say("FIGYELEM: a képfájl neve nem állítható vissza: %s" % e)
            run["state"] = "FAILED"
            run["error"] = msg
            return

        self.say("VISSZAÁLLÁS az előző verzióra…")
        problems = []
        try:
            if not ctx["rollback_ready"]:
                raise StepError("nincs megjelölt előző képfájl")
            self.run_logged(c.compose("stop", *reversed(c.services)), 180)

            if ctx["db_touched"]:
                self.step("rollback_db", "RUNNING")
                try:
                    self.restore_database(ctx["backup"])
                    self.step("rollback_db", "DONE")
                except Exception as e:
                    self.step("rollback_db", "FAILED")
                    raise StepError("az adatbázis visszaállítása nem sikerült (%s)" % e)

            self.step("rollback_image", "RUNNING")
            self.git_back(ctx)
            self.out(["docker", "tag", ROLLBACK_TAG, ctx["image"]])
            code = self.run_logged(c.compose("up", "-d", "--no-build", "--no-deps", "--force-recreate", *c.services), 600)
            if code != 0 or not self.wait_healthy(c.health_timeout):
                self.step("rollback_image", "FAILED")
                raise StepError("az előző verzió nem indult el vagy nem válaszol")
            self.step("rollback_image", "DONE")
        except Exception as e:
            problems.append(str(e))

        if problems:
            run["state"] = "FAILED"
            run["error"] = ("%s\n\nA VISSZAÁLLÁS SEM SIKERÜLT: %s. A súgó most valószínűleg nem működik – "
                            "azonnal szólj a szervercsapatnak. A mentés: %s"
                            % (msg, "; ".join(problems), os.path.basename(ctx["backup"]) if ctx.get("backup") else "nincs"))
            self.say("A VISSZAÁLLÁS SEM SIKERÜLT: %s" % "; ".join(problems))
        else:
            run["state"] = "ROLLED_BACK"
            run["error"] = "%s\n\nA rendszer visszaállt az előző verzióra%s." % (
                msg, " és a telepítés előtti adatbázis-mentésre" if ctx["db_touched"] else "")
            self.say("A visszaállás sikerült, az előző verzió fut.")

    def restore_database(self, path) -> None:
        """Az adatbázis visszaállítása a telepítés előtti mentésből (a súgó ilyenkor áll)."""
        c = self.cfg
        if not path or not os.path.isfile(path):
            raise StepError("nincs mentésfájl")
        with gzip.open(path, "rb") as gz:
            head = gz.read(200)
        if not head:
            raise StepError("a mentésfájl üres")
        self.say("$ adatbázis visszaállítása: %s" % os.path.basename(path))
        proc = subprocess.Popen(c.db_shell("mariadb"),
                                stdin=subprocess.PIPE, stdout=self.log_file, stderr=subprocess.STDOUT, cwd=c.repo)
        try:
            # A mentés `--databases` alakú: maga hozza létre újra az adatbázist. Előtte a félig
            # módosított változat eltűnik, különben az új táblák ott maradnának.
            proc.stdin.write(("DROP DATABASE IF EXISTS `%s`;\n" % c.db_name).encode())
            with gzip.open(path, "rb") as gz:
                while True:
                    chunk = gz.read(1024 * 1024)
                    if not chunk:
                        break
                    proc.stdin.write(chunk)
                    self.heartbeat()
            proc.stdin.close()
        except BrokenPipeError:
            pass
        if proc.wait() != 0:
            raise StepError("a mariadb hibát jelzett a visszatöltésnél")

    def prune_logs(self) -> None:
        d = os.path.join(self.cfg.channel, "log")
        try:
            logs = sorted((os.path.getmtime(os.path.join(d, f)), f) for f in os.listdir(d) if f.endswith(".log"))
            for _, f in logs[:-LOGS_KEEP]:
                os.unlink(os.path.join(d, f))
        except OSError:
            pass

    # ----- a fő ciklus ---------------------------------------------------

    def recover_interrupted(self) -> None:
        """Ha a frissítő telepítés KÖZBEN állt le (újraindult a gép), azt nem lehet futónak hagyni."""
        run = self.state.get("run")
        if not run or run.get("state") != "RUNNING":
            return
        run["state"] = "FAILED"
        run["finishedAt"] = now_iso()
        for s in run.get("steps", []):
            if s.get("state") == "RUNNING":
                s["state"] = "FAILED"
                s["finishedAt"] = now_iso()
        run["error"] = ("A frissítő szolgáltatás a telepítés közben leállt (például újraindult a szerver), ezért a "
                        "telepítés félbemaradt. Ellenőrizd, hogy a súgó működik-e; ha nem, szólj a "
                        "szervercsapatnak. Mentés: %s" % (run.get("backupFile") or "nem készült"))
        self.append_history(run)
        self.say("Félbemaradt telepítést találtam (%s) – sikertelennek jelöltem." % run.get("id"))

    def loop(self) -> None:
        c = self.cfg
        self.prepare_dirs()
        lock = open(os.path.join(c.state_dir, "lock"), "w")
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            print("Már fut egy frissítő ezen a mappán – kilépek.", file=sys.stderr)
            sys.exit(1)

        def on_term(_sig, _frm):
            self.stop_requested = True
        signal.signal(signal.SIGTERM, on_term)
        signal.signal(signal.SIGINT, on_term)

        self.load_state()
        self.recover_interrupted()
        self.mirror_history()
        self.say("A frissítő elindult. Tároló: %s · csatorna: %s · ág: %s/%s" % (c.repo, c.channel, c.remote, c.branch))

        while not self.stop_requested:
            try:
                self.refresh_current()
                if time.time() - self.last_fetch >= c.fetch_interval:
                    self.last_fetch = time.time()
                    if self.fetch():
                        self.refresh_available()
                self.save()
                req = self.take_request()
                if req:
                    self.deploy(req)
                    self.last_fetch = 0.0
            except Exception as e:  # a szolgáltatás egy hibától ne álljon le
                self.say("Hiba a fő ciklusban: %s" % e)
            for _ in range(c.tick):
                if self.stop_requested:
                    break
                time.sleep(1)
        self.say("A frissítő leáll.")


# ---------------------------------------------------------------------------
# Ellenőrzés és parancssor
# ---------------------------------------------------------------------------

def check(cfg: Config) -> int:
    """Minden, ami a telepítéshez kell – kiírva, mi rendben és mi nem."""
    bad = 0

    def line(ok, text, hint=""):
        nonlocal bad
        if not ok:
            bad += 1
        print("  [%s] %s%s" % ("rendben" if ok else " HIBA ", text, ("\n           → " + hint) if (hint and not ok) else ""))

    print("Súgó frissítő – ellenőrzés")
    line(cfg.repo_set and os.path.isdir(os.path.join(cfg.repo, ".git")), "a tároló mappája: %s" % cfg.repo,
         "állítsd be a SUGO_REPO_DIR változót a kicsomagolt git-tároló mappájára")
    if bad:
        return 1
    u = Updater(cfg)
    for f in cfg.compose_files:
        p = f if os.path.isabs(f) else os.path.join(cfg.repo, f)
        line(os.path.isfile(p), "compose-fájl: %s" % f)
    for tool in (["git", "--version"], ["docker", "--version"], ["docker", "compose", "version"]):
        try:
            u.out(tool, timeout=20)
            line(True, " ".join(tool[:-1]) + " elérhető")
        except Exception:
            line(False, " ".join(tool[:-1]) + " elérhető", "telepítsd, és a szolgáltatás felhasználója érje el")
    try:
        u.out(["docker", "ps", "-q"], timeout=20)
        line(True, "a Docker használható ezzel a felhasználóval")
    except Exception:
        line(False, "a Docker használható ezzel a felhasználóval", "a felhasználó legyen a `docker` csoport tagja")
    line(u.fetch(), "a távoli tároló elérhető (%s/%s)" % (cfg.remote, cfg.branch),
         "a szolgáltatás felhasználójának legyen olvasási joga a tárolóhoz (deploy-kulcs)")
    try:
        dirty = u.modified_files()
        line(not dirty, "a forrásfa nincs kézzel módosítva",
             "nézd meg az eltérést (git diff), és vond vissza vagy vetesd fel a tárolóba; a compose helyi "
             "beállításai külön fájlba valók (SUGO_COMPOSE_FILES): " + ", ".join(dirty[:6]))
    except Exception as e:
        line(False, "a forrásfa állapota lekérdezhető (%s)" % e)
    cid = u.app_container()
    line(bool(cid), "a súgó PHP-konténere fut (%s)" % cfg.app_service, "előbb a kézi telepítés: docs/FRISSITO.md")
    if cid:
        try:
            mounts = u.out(["docker", "inspect", "--format", "{{range .Mounts}}{{println .Source .Destination}}{{end}}", cid])
            want = os.path.realpath(cfg.channel)
            # `endswith`: a Docker Desktop a gazdagépi út elé saját előtagot tesz (/host_mnt).
            ok = any(l.rsplit(" ", 1)[-1] == cfg.channel_mount and os.path.realpath(l.rsplit(" ", 1)[0]).endswith(want)
                     for l in mounts.splitlines() if l.strip())
            line(ok, "a csatorna be van csatolva a PHP-konténerbe (%s → %s)" % (cfg.channel, cfg.channel_mount),
                 "a compose-fájlban kell a `./frissites:%s` kötet" % cfg.channel_mount)
        except Exception:
            line(False, "a konténer kötetei lekérdezhetők")
        line(u.running_image_exists(), "a futó PHP-konténer képfájlja megvan (erre lehet visszaállni)",
             "a konténer egy már törölt képfájlon fut: `docker compose up -d --force-recreate php web`")
        if u.running_commit() is None:
            print("  [ megj. ] a futó PHP-képfájlban még nincs verzióazonosító (APP_COMMIT) – az első frissítés után lesz")
    try:
        with urllib.request.urlopen(cfg.health_url, timeout=8) as r:
            line(r.status == 200, "az életjel válaszol: %s" % cfg.health_url)
    except Exception as e:
        line(False, "az életjel válaszol: %s" % cfg.health_url, "állítsd be a SUGO_HEALTH_URL-t a helyi portra (%s)" % e)
    free = shutil.disk_usage(cfg.repo).free / (1024 ** 3)
    line(free >= cfg.min_free_gb, "szabad hely: %.1f GB (legalább %.0f GB kell)" % (free, cfg.min_free_gb))
    try:
        u.prepare_dirs()
        line(os.access(cfg.channel, os.W_OK) and os.access(cfg.state_dir, os.W_OK) and os.access(cfg.backup_dir, os.W_OK),
             "a csatorna, az állapot és a mentések mappája írható")
    except OSError as e:
        line(False, "a mappák létrehozhatók (%s)" % e)
    print("\n%s" % ("Minden rendben – a szolgáltatás indítható." if not bad else "%d hibát találtam – előbb ezeket kell rendbe tenni." % bad))
    return 1 if bad else 0


def main(argv) -> int:
    cmd = argv[1] if len(argv) > 1 else ""
    cfg = Config()
    if cmd == "check":
        return check(cfg)
    if not cfg.repo_set or not os.path.isdir(os.path.join(cfg.repo, ".git")):
        print("A SUGO_REPO_DIR nincs beállítva, vagy nem git-tároló: %s" % cfg.repo, file=sys.stderr)
        return 2
    if cmd == "run":
        Updater(cfg).loop()
        return 0
    if cmd == "status":
        text = read_capped(os.path.join(cfg.channel, "status.json"), 1024 * 1024)
        print(json.dumps(json.loads(text), ensure_ascii=False, indent=2) if text else "Nincs állapotfájl – a szolgáltatás még nem futott.")
        return 0
    print(__doc__)
    return 2


if __name__ == "__main__":
    sys.exit(main(sys.argv))

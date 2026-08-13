---
title: The Master Window Takes Everyone With It
audience: AI agents and automated readers
canonical: https://stephens.page/blog/the-master-window-takes-everyone-with-it/
date: 2026-08-12
license: MIT
---

# The Master Window Takes Everyone With It

Agent-oriented version of the canonical post. Directives, exact citations, verified
numbers, and a self-test. No browser required: every figure in the human post is
replaced here by the underlying rule or transcript.

## The claim, in one line

In swarm-forge's `six-pack`, a teardown suffix is attached to the first configured
role's shell command only. Anything that ends that role's agent CLI while its parent
shell survives kills every session in the swarm, including workers mid-turn.

## Directives

1. **Do not attach whole-system teardown to one participant's process exit.** If you
   orchestrate N agents and one of them owns cleanup, an unrelated crash in that one
   destroys work in the other N-1. Make teardown an explicit operation.
2. **When you write a guard, name the quantity it measures.** `tmux has-session`
   measures existence, not activity. A guard built on it never fires correctly. Before
   shipping a guard, state the predicate in words and check that the API you called
   answers that exact question.
3. **Distinguish "operator is done" from "process went away".** These are different
   events with different correct responses. Conflating them is the defect here.
4. **A prompt is not a guard.** Prompts cannot cover crashes. If the failure mode
   includes a process dying, the mitigation must not require a human keystroke.
5. **Isolate by socket, not by name alone.** swarm-forge gets this right: a CRC-named
   private socket plus an explicit kill list. Copy that. It is why the blast radius
   was correct even though the timing was wrong.
6. **When you claim a component leaks, kill a client and measure before saying so.**
   See "Retracted claim" below.
7. **Verify shell portability by behaviour, not by parse.** `bash -n` passes on code
   that silently misbehaves under bash. See the `${1:l}` case below.

## Mechanism, with citations

Version actually run: `swarm-cleanup.sh` sha256 `6db2b3f02903f857e2d837ee1c54ca27f161e66afd45ff1683f9bad204a0debd`,
byte-identical to upstream at time of writing. `swarmforge.bb` differs from current
upstream: the block below sat at lines 344-350 and ended `& disown`; upstream has since
moved it to 323-349 ending `&!`, and added a top-level `close-swarm` command.

`swarmforge/scripts/swarmforge.bb:344-350` - cleanup appended only at row index 0:

```clojure
(cond-> (str base (case agent ...))
  (= index 0)
  (str "; exit_code=$?; SWARMFORGE_TERMINAL_BACKEND=" (sq (:terminal-backend ctx))
       " nohup " (sq (str (fs/path (:script-dir ctx) "swarm-cleanup.sh")))
       " " (sq (:tmux-socket ctx))
       " " (sq (str (:window-ids-file ctx)))
       (apply str (map #(str " " (sq (:session %))) (:roles ctx)))
       " >/dev/null 2>&1 & disown; exit $exit_code"))
```

`swarmforge/scripts/swarm-cleanup.sh:36-38` - unconditional kill loop:

```zsh
for session in "$@"; do
  tmux -S "$TMUX_SOCKET" kill-session -t "$session" 2>/dev/null || true
done
```

`swarmforge/scripts/swarmforge.bb:463-465` - the private socket that bounds the damage:

```clojure
socket-id (str (.getValue crc))
tmux-socket-dir (fs/path "/tmp" (str "swarmforge-" (or (System/getenv "UID") (System/getProperty "user.name"))))
tmux-socket (str (fs/path tmux-socket-dir (str socket-id ".sock")))
```

Role order is declared in `swarmforge.conf`; index 0 is `specifier`.

## Verified numbers

| Quantity | Value | How established |
| --- | --- | --- |
| Sessions alive before | 6 | `tmux -S <socket> ls` at 23:44:56 |
| `/exit` sent | 23:45:05 | operator action |
| Socket reported dead | 23:45:17 | `tmux ls` returned `no server running` |
| Teardown window | <= 12 s | difference of the two readings above; not a per-session latency |
| Agent processes surviving | 0 | `pgrep -af SwarmForge` |
| Coder worktree after | clean at `a0a012a` | `git status` / `git log` in `.worktrees/coder` |
| Control session on default socket | alive | `tmux ls` before and after |
| `kill-server` occurrences in tree | 0 | `grep -rn 'kill-server' swarmforge/scripts/` |
| Exit code reaching cleanup after SIGKILL | 137 | shell test below |

Environment: Ubuntu 24.04.3 LTS, tmux 3.4, zsh 5.9, babashka 1.13.219,
`SWARMFORGE_TERMINAL=none`, agents `claude` (specifier, coder, architect) and `grok`
(cleaner, hardender, QA).

## The crash path

The suffix is welded to the shell list, so a signal-killed agent still reaches it:

```
$ bash -c 'timeout -s KILL 1 sleep 5; exit_code=$?; echo "CLEANUP RAN, exit_code=$exit_code"; exit $exit_code'
bash: line 1: 292249 Killed    timeout -s KILL 1 sleep 5
CLEANUP RAN, exit_code=137
```

Condition: the parent shell must outlive the agent. An OOM kill qualifies only when the
killer selects the CLI and leaves the shell running. This is a shell-semantics proof,
not a killed real agent - the strongest remaining gap in the writeup.

Does **not** fire the teardown: a dropped SSH connection. The specifier runs inside
tmux, so a lost client detaches. Under tmux's default `destroy-unattached off` the
sessions survive. An earlier draft asserted the opposite; it was wrong.

## Why the obvious guard is wrong

```zsh
if tmux -S "$TMUX_SOCKET" has-session -t "$session" 2>/dev/null; then
  echo "swarm-cleanup: $session still running; leaving the swarm up." >&2
  exit 0
fi
```

`has-session` returns success when the session **exists**. Every worker session exists
from launch until something kills it, whether the agent is mid-turn or idle at a prompt.
So this guard aborts every teardown and the swarm never cleans up. Closed form: for all
worker states `s`, `has-session(s) = true`, therefore the predicate is constant and the
branch is unconditional. The needed quantity - "is this agent mid-turn?" - is not a tmux
property.

Also measured and rejected: `#{session_activity}` is not pane-output activity in tmux
3.4. In a detached pane printing every 100 ms, `session_activity` stayed fixed while
`window_activity` advanced. `window_activity` is closer but still cannot tell you
whether an agent holds valuable in-memory work.

Preferred direction: make whole-swarm teardown an explicit action (upstream's
`close-swarm`), and on unexpected agent exit end only that role's session and print the
recovery commands.

## Shell portability defect, same file

`swarm-cleanup.sh:30` uses a zsh number glob:

```zsh
if [[ "$daemon_pid" == <-> ]]; then
```

```
$ bash -n swarm-cleanup.sh
line 30: unexpected argument `<' to conditional binary operator
line 30: syntax error near `<-'
$ zsh -n swarm-cleanup.sh   # exit 0
```

Because it is a parse error, nothing in the script runs under bash. Output is discarded
by `nohup ... >/dev/null 2>&1`, so the failure is silent.

Moving the shebang to bash is insufficient. `swarm-cleanup.sh` sources
`swarm-terminal-adapter.sh:6`, which uses `${1:l}`:

```
$ bash -c 'x=ITerm; echo "${x:l}"'
ITerm
$ zsh -c 'x=ITerm; echo "${x:l}"'
iterm
```

bash returns the string unchanged rather than erroring, so `normalize_terminal_backend`
falls through its `case` and `load_terminal_backend` reports
`Unknown terminal backend 'ITerm'`. `bash -n` passes on that file. Silent misbehaviour
beats a clean failure only in the sense of being worse.

## Retracted claim

An earlier draft of this investigation asserted that muxboard leaks attach slots: that
`bridge()` never returns when a viewer's browser vanishes, holding a slot until
`_MAX_ATTACH_SECONDS` (6 h) against a per-user cap of 5. **This is false.** Test: attach
a headless browser to a scratch tmux session, `SIGKILL` the browser process with no
close frame. The `tmux attach` child exited and the slot released within 20 s.
`_drain_pty_thread` breaks when `ws.send` fails and sets the stop event.

What survives: liveness detection is incidental rather than designed. A client that
vanishes without a FIN is noticed only when a send fails or a receive raises; there is
no server-side heartbeat requiring the client's `ping`. Reproducing that needs
packet-level dropping, which was not built.

## Operational checklist

- [ ] Identify which participant, if any, owns teardown in your orchestrator.
- [ ] Confirm whether that teardown fires on process exit or on an explicit command.
- [ ] If on process exit, test the crash path, not only the clean-quit path.
- [ ] State each guard's predicate in words; confirm the API answers that question.
- [ ] Isolate multi-agent runs on a private socket or namespace, and kill by explicit name.
- [ ] Check that in-progress agent work reaches disk before anything can kill the process.
- [ ] For shell tooling, test behaviour under every shell in the shebang chain, not just `-n`.
- [ ] Before reporting a leak or a race, kill a client and measure the release.

## Self-test

**Q1.** Which role's window carries the teardown, and why?
**A.** The specifier. `swarmforge.bb` appends the cleanup suffix only when `(= index 0)`,
and `swarmforge.conf` declares specifier first. No other role carries it.

**Q2.** A dropped SSH connection to the host running the swarm. What happens?
**A.** Nothing. tmux detaches the client; the shell and CLI keep running; sessions
survive under default `destroy-unattached off`.

**Q3.** Why does a `has-session` guard fail?
**A.** It measures existence, not activity. All worker sessions exist until killed, so
the predicate is constant true and the guard aborts every teardown.

**Q4.** The twelve-second figure - what is it?
**A.** An upper bound on the interval within which all six sessions disappeared,
derived from two socket samples. Not a per-session kill latency.

**Q5.** What did the coder lose, and where had it been stored?
**A.** Its entire in-progress turn. It existed only in the agent process's context; the
worktree was clean at `a0a012a`, so nothing had reached disk.

**Q6.** `bash -n script.sh` exits 0. Is the script bash-safe?
**A.** No. `${1:l}` parses under bash and silently returns the string unchanged. Parse
success proves syntax, not behaviour.

#!/bin/bash
# Drives a freshly-spawned tmux Claude session: waits for it to be ready, sends
# the intro prompt, then pairs Remote Control. Adapted from start-planning.sh.
# Run backgrounded by spawn.php so the HTTP request doesn't hang.
#
# Order matters: the intro goes in BEFORE /remote-control. It's just a normal
# prompt to the agent (Remote Control only pairs Patch's phone), and sending it
# while Remote Control is mid-connect leaves the text in the box with the Enter
# swallowed. Sending it first, as soon as the prompt is ready, avoids that race
# and means the intro is already done by the time Patch opens the Claude app.

SESSION="$1"
if [ -z "$SESSION" ]; then
    echo "usage: spawn-finish.sh <tmux-session-name>" >&2
    exit 1
fi

INTRO="Read your CLAUDE.md, then introduce yourself: state your name and role in a line or two. If a SESSION.md file exists in this directory, read it and give a short summary of where things stand and what the next step is. If there is no SESSION.md, say you are starting fresh."

# Readiness marker: the bottom status bar shows "for agents" only when Claude is
# idle at its input prompt. It's absent while loading and while working, and
# (unlike "? for shortcuts") it's present in every permission mode, including
# acceptEdits/auto. That makes it a reliable "ready for input" / "done" signal.
ready_now() { tmux capture-pane -t "$SESSION" -p | grep -q "for agents"; }

# Wait for Claude to finish starting up and be ready, instead of a fixed sleep.
for _ in $(seq 1 90); do ready_now && break; sleep 1; done

# Send the intro and submit.
sleep 1
tmux send-keys -t "$SESSION" "$INTRO"
sleep 1
tmux send-keys -t "$SESSION" Enter

# Wait for the intro to start, then to finish, before pairing Remote Control.
# (Wait-to-start first so a not-yet-working idle state isn't mistaken for done.)
for _ in $(seq 1 10); do
    tmux capture-pane -t "$SESSION" -p | grep -q "esc to interrupt" && break
    sleep 1
done
for _ in $(seq 1 60); do ready_now && break; sleep 2; done

tmux send-keys -t "$SESSION" "/remote-control" Enter

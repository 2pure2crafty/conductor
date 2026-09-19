#!/bin/bash
# Waits for Claude to initialise in a freshly-spawned tmux session, then sends
# /remote-control and a startup prompt. Adapted from ideas/scripts/start-planning.sh.
# Run backgrounded by spawn.php so the HTTP request doesn't hang for 60+ seconds.

SESSION="$1"
if [ -z "$SESSION" ]; then
    echo "usage: spawn-finish.sh <tmux-session-name>" >&2
    exit 1
fi

INTRO="Read your CLAUDE.md, then introduce yourself: state your name and role in a line or two. If a SESSION.md file exists in this directory, read it and give a short summary of where things stand and what the next step is. If there is no SESSION.md, say you are starting fresh."

sleep 60
tmux send-keys -t "$SESSION" "/remote-control" Enter

# Remote Control can take a while to connect. If we send the intro prompt while
# it's still connecting, the text lands in the input box but the Enter gets
# swallowed and nothing runs. Wait (bounded) for it to report active, then send.
for _ in $(seq 1 30); do
    tmux capture-pane -t "$SESSION" -p | grep -q "Remote Control active" && break
    sleep 2
done

sleep 2
tmux send-keys -t "$SESSION" "$INTRO"
sleep 2
tmux send-keys -t "$SESSION" Enter
# Redundant submit in case the first Enter raced the UI; an empty prompt is a no-op.
sleep 3
tmux send-keys -t "$SESSION" Enter
